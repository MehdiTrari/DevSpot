from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path
from typing import Any

import numpy as np
import torch
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


class EmbeddingModel:
    def __init__(self, model_name: str, projection_path: str | None = None, device: str = "cpu") -> None:
        self.device = torch.device(device)
        self.tokenizer = AutoTokenizer.from_pretrained(model_name)
        self.model = AutoModel.from_pretrained(model_name)
        self.model.to(self.device)
        self.model.eval()
        self.projection = self._load_projection(projection_path)

    def _load_projection(self, projection_path: str | None) -> torch.Tensor | None:
        if not projection_path:
            return None
        payload = torch.load(projection_path, map_location=self.device)
        projection = payload["projection"] if isinstance(payload, dict) and "projection" in payload else payload
        if not isinstance(projection, torch.Tensor):
            projection = torch.tensor(projection, dtype=torch.float32)
        return projection.to(self.device, dtype=torch.float32)

    def encode(self, texts: list[str], batch_size: int = 8) -> np.ndarray:
        vectors: list[np.ndarray] = []
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
                outputs = self.model(**encoded)
            attention_mask = encoded["attention_mask"].unsqueeze(-1).expand(outputs.last_hidden_state.size()).float()
            masked = outputs.last_hidden_state * attention_mask
            summed = torch.sum(masked, dim=1)
            counts = torch.clamp(attention_mask.sum(dim=1), min=1e-9)
            embeddings = summed / counts
            if self.projection is not None:
                embeddings = torch.matmul(embeddings, self.projection.T)
            embeddings = torch.nn.functional.normalize(embeddings, p=2, dim=1)
            vectors.extend(embeddings.cpu().numpy())
        return np.asarray(vectors, dtype=np.float32)


def dcg(relevances: list[int]) -> float:
    total = 0.0
    for idx, rel in enumerate(relevances, start=1):
        total += (2**rel - 1) / math.log2(idx + 1)
    return total


def evaluate(dataset: dict[str, Any], model_name: str, projection_path: str | None, device: str) -> dict[str, Any]:
    developers = dataset["developers"]
    offers = dataset["offers"]
    candidate_texts = [build_candidate_text(developer) for developer in developers]
    offer_texts = [build_offer_text(offer) for offer in offers]

    model = EmbeddingModel(model_name=model_name, projection_path=projection_path, device=device)
    candidate_vectors = model.encode(candidate_texts)
    offer_vectors = model.encode(offer_texts)

    similarities = offer_vectors @ candidate_vectors.T

    recall_at_1 = 0.0
    recall_at_3 = 0.0
    recall_at_5 = 0.0
    reciprocal_rank = 0.0
    ndcg_at_5 = 0.0
    evaluated_offers = 0
    offer_summaries: list[dict[str, Any]] = []

    for offer_index, offer in enumerate(offers):
        relevances = [weak_relevance(offer, developer) for developer in developers]
        positive_indices = [idx for idx, rel in enumerate(relevances) if rel > 0]
        if not positive_indices:
            continue

        evaluated_offers += 1
        ranking = np.argsort(-similarities[offer_index])
        ranked_relevances = [relevances[idx] for idx in ranking]

        recall_at_1 += 1.0 if any(rel > 0 for rel in ranked_relevances[:1]) else 0.0
        recall_at_3 += 1.0 if any(rel > 0 for rel in ranked_relevances[:3]) else 0.0
        recall_at_5 += 1.0 if any(rel > 0 for rel in ranked_relevances[:5]) else 0.0

        rr = 0.0
        for rank, rel in enumerate(ranked_relevances, start=1):
            if rel > 0:
                rr = 1.0 / rank
                break
        reciprocal_rank += rr

        actual_dcg = dcg(ranked_relevances[:5])
        ideal_dcg = dcg(sorted([rel for rel in relevances if rel > 0], reverse=True)[:5])
        ndcg_at_5 += actual_dcg / ideal_dcg if ideal_dcg > 0 else 0.0

        top_ranked = []
        for idx in ranking[:3]:
            profile = developers[int(idx)]["profile"]
            top_ranked.append({
                "fullName": f"{profile['firstName']} {profile['lastName']}",
                "score": round(float(similarities[offer_index][int(idx)]), 4),
                "weakRelevance": relevances[int(idx)],
            })
        offer_summaries.append({
            "title": offer["title"],
            "positiveCandidates": len(positive_indices),
            "top3": top_ranked,
        })

    if evaluated_offers == 0:
        raise RuntimeError("No evaluable offers were found in the dataset.")

    return {
        "model": model_name,
        "projectionPath": projection_path,
        "offersEvaluated": evaluated_offers,
        "recall@1": round(recall_at_1 / evaluated_offers, 4),
        "recall@3": round(recall_at_3 / evaluated_offers, 4),
        "recall@5": round(recall_at_5 / evaluated_offers, 4),
        "mrr": round(reciprocal_rank / evaluated_offers, 4),
        "ndcg@5": round(ndcg_at_5 / evaluated_offers, 4),
        "sampleOffers": offer_summaries[:5],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Evaluate DevSpot matching quality with weak supervision.")
    parser.add_argument("--dataset", required=True, help="Path to the cleaned dataset JSON")
    parser.add_argument("--model", default="camembert-base", help="HuggingFace model name or local path")
    parser.add_argument("--projection", help="Optional path to a trained projection .pt file")
    parser.add_argument("--device", default="cpu", help="Torch device to use")
    parser.add_argument("--output", help="Optional path to save the JSON evaluation report")
    args = parser.parse_args()

    dataset = load_dataset(args.dataset)
    report = evaluate(dataset, model_name=args.model, projection_path=args.projection, device=args.device)

    payload = json.dumps(report, ensure_ascii=False, indent=2)
    print(payload)
    if args.output:
        Path(args.output).write_text(payload + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
