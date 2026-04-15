from __future__ import annotations

import argparse
import json
import random
import sys
from pathlib import Path
from typing import Any

import numpy as np
import torch
from torch import nn
from torch.utils.data import DataLoader, TensorDataset
from transformers import AutoModel, AutoTokenizer

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from ml.app.preprocessing import normalize_text  # noqa: E402
from scripts.matching_dataset_tools import (  # noqa: E402
    build_candidate_text,
    build_offer_text,
    load_dataset,
    weak_relevance,
)


class FrozenEmbedder:
    def __init__(self, model_name: str, device: str = "cpu") -> None:
        self.device = torch.device(device)
        self.tokenizer = AutoTokenizer.from_pretrained(model_name)
        self.model = AutoModel.from_pretrained(model_name)
        self.model.to(self.device)
        self.model.eval()

    def encode(self, texts: list[str], batch_size: int = 8) -> torch.Tensor:
        outputs: list[torch.Tensor] = []
        for start in range(0, len(texts), batch_size):
            chunk = [normalize_text(text) for text in texts[start:start + batch_size]]
            encoded = self.tokenizer(
                chunk,
                return_tensors="pt",
                truncation=True,
                max_length=256,
                padding=True,
            )
            encoded = {key: value.to(self.device) for key, value in encoded.items()}
            with torch.no_grad():
                model_output = self.model(**encoded)
            attention_mask = encoded["attention_mask"].unsqueeze(-1).expand(model_output.last_hidden_state.size()).float()
            masked = model_output.last_hidden_state * attention_mask
            summed = torch.sum(masked, dim=1)
            counts = torch.clamp(attention_mask.sum(dim=1), min=1e-9)
            embeddings = summed / counts
            embeddings = torch.nn.functional.normalize(embeddings, p=2, dim=1)
            outputs.append(embeddings.cpu())
        return torch.cat(outputs, dim=0)


def build_training_pairs(dataset: dict[str, Any], negatives_per_offer: int, seed: int) -> tuple[list[int], list[int], list[float]]:
    random_generator = random.Random(seed)
    developers = dataset["developers"]
    offers = dataset["offers"]

    offer_indices: list[int] = []
    candidate_indices: list[int] = []
    labels: list[float] = []

    for offer_index, offer in enumerate(offers):
        positives = []
        negatives = []
        for candidate_index, developer in enumerate(developers):
            relevance = weak_relevance(offer, developer)
            if relevance > 0:
                positives.append((candidate_index, float(relevance) / 3.0))
            else:
                negatives.append(candidate_index)

        if not positives or not negatives:
            continue

        for candidate_index, label in positives:
            offer_indices.append(offer_index)
            candidate_indices.append(candidate_index)
            labels.append(label)

        sample_size = min(len(negatives), max(len(positives) * negatives_per_offer, negatives_per_offer))
        for negative_index in random_generator.sample(negatives, sample_size):
            offer_indices.append(offer_index)
            candidate_indices.append(negative_index)
            labels.append(0.0)

    return offer_indices, candidate_indices, labels


def train_projection(
    offer_embeddings: torch.Tensor,
    candidate_embeddings: torch.Tensor,
    offer_indices: list[int],
    candidate_indices: list[int],
    labels: list[float],
    epochs: int,
    batch_size: int,
    learning_rate: float,
    device: str,
) -> tuple[torch.Tensor, dict[str, float]]:
    model_device = torch.device(device)
    feature_dim = offer_embeddings.shape[1]
    projection = nn.Linear(feature_dim, feature_dim, bias=False)
    projection.weight.data.copy_(torch.eye(feature_dim))
    projection.to(model_device)

    dataset = TensorDataset(
        torch.tensor(offer_indices, dtype=torch.long),
        torch.tensor(candidate_indices, dtype=torch.long),
        torch.tensor(labels, dtype=torch.float32),
    )
    dataloader = DataLoader(dataset, batch_size=batch_size, shuffle=True)

    offer_embeddings = offer_embeddings.to(model_device)
    candidate_embeddings = candidate_embeddings.to(model_device)

    optimizer = torch.optim.AdamW(projection.parameters(), lr=learning_rate, weight_decay=1e-4)
    loss_fn = nn.BCEWithLogitsLoss()
    last_loss = 0.0

    for _ in range(epochs):
        for batch_offer_indices, batch_candidate_indices, batch_labels in dataloader:
            batch_offer = offer_embeddings[batch_offer_indices.to(model_device)]
            batch_candidate = candidate_embeddings[batch_candidate_indices.to(model_device)]
            batch_labels = batch_labels.to(model_device)

            projected_offer = torch.nn.functional.normalize(projection(batch_offer), p=2, dim=1)
            projected_candidate = torch.nn.functional.normalize(projection(batch_candidate), p=2, dim=1)
            cosine_scores = torch.sum(projected_offer * projected_candidate, dim=1)
            logits = cosine_scores * 12.0
            loss = loss_fn(logits, batch_labels)

            optimizer.zero_grad()
            loss.backward()
            optimizer.step()
            last_loss = float(loss.detach().cpu())

    return projection.weight.detach().cpu(), {"finalLoss": round(last_loss, 6)}


def main() -> int:
    parser = argparse.ArgumentParser(description="Train a lightweight projection layer on top of CamemBERT embeddings.")
    parser.add_argument("--dataset", required=True, help="Path to the cleaned dataset JSON")
    parser.add_argument("--model", default="camembert-base", help="Base HuggingFace model name or local path")
    parser.add_argument("--output", required=True, help="Path to the output projection .pt file")
    parser.add_argument("--device", default="cpu", help="Torch device to use")
    parser.add_argument("--epochs", type=int, default=6, help="Number of training epochs")
    parser.add_argument("--batch-size", type=int, default=64, help="Batch size")
    parser.add_argument("--learning-rate", type=float, default=0.005, help="Learning rate")
    parser.add_argument("--negatives-per-offer", type=int, default=4, help="Negative samples per positive offer block")
    parser.add_argument("--seed", type=int, default=42, help="Random seed")
    args = parser.parse_args()

    random.seed(args.seed)
    np.random.seed(args.seed)
    torch.manual_seed(args.seed)

    dataset = load_dataset(args.dataset)
    offer_indices, candidate_indices, labels = build_training_pairs(dataset, args.negatives_per_offer, args.seed)
    if not labels:
        raise RuntimeError("The weak supervision pipeline did not produce any training pairs.")

    developers = dataset["developers"]
    offers = dataset["offers"]
    candidate_texts = [build_candidate_text(developer) for developer in developers]
    offer_texts = [build_offer_text(offer) for offer in offers]

    embedder = FrozenEmbedder(model_name=args.model, device=args.device)
    offer_embeddings = embedder.encode(offer_texts)
    candidate_embeddings = embedder.encode(candidate_texts)

    projection, training_report = train_projection(
        offer_embeddings=offer_embeddings,
        candidate_embeddings=candidate_embeddings,
        offer_indices=offer_indices,
        candidate_indices=candidate_indices,
        labels=labels,
        epochs=args.epochs,
        batch_size=args.batch_size,
        learning_rate=args.learning_rate,
        device=args.device,
    )

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    payload: dict[str, Any] = {
        "projection": projection,
        "model": args.model,
        "dataset": str(Path(args.dataset).resolve()),
        "trainingPairs": len(labels),
        **training_report,
    }
    torch.save(payload, output_path)

    report = {
        "output": str(output_path.resolve()),
        "trainingPairs": len(labels),
        **training_report,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
