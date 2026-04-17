from __future__ import annotations

import os
from functools import lru_cache

import numpy as np
import torch
from transformers import AutoModel, AutoTokenizer

from .preprocessing import normalize_text


class CamembertEmbeddingService:
    def __init__(self, model_name: str, device: str | None = None) -> None:
        self.model_name = model_name
        preferred_device = (device or "cpu").lower()
        if preferred_device == "cuda" and not torch.cuda.is_available():
            preferred_device = "cpu"

        self.device = torch.device(preferred_device)
        self.tokenizer = AutoTokenizer.from_pretrained(model_name)
        self.model = AutoModel.from_pretrained(model_name)
        self.model.to(self.device)
        self.model.eval()
        self.projection = self._load_projection(os.getenv("CAMEMBERT_PROJECTION_PATH"))

    def _load_projection(self, projection_path: str | None) -> torch.Tensor | None:
        if not projection_path:
            return None

        payload = torch.load(projection_path, map_location=self.device)
        projection = payload["projection"] if isinstance(payload, dict) and "projection" in payload else payload
        if not isinstance(projection, torch.Tensor):
            projection = torch.tensor(projection, dtype=torch.float32)

        return projection.to(self.device, dtype=torch.float32)

    def embedding_dimension(self) -> int:
        return int(self.model.config.hidden_size)

    def embed_text(self, text: str) -> tuple[list[float], str]:
        embeddings, normalized_texts = self.embed_texts([text])

        return embeddings[0], normalized_texts[0]

    def embed_texts(self, texts: list[str]) -> tuple[list[list[float]], list[str]]:
        normalized_texts = [normalize_text(text) for text in texts]
        encoded = self.tokenizer(
            normalized_texts,
            return_tensors="pt",
            truncation=True,
            max_length=512,
            padding=True,
        )
        encoded = {key: value.to(self.device) for key, value in encoded.items()}

        with torch.no_grad():
            model_output = self.model(**encoded)

        attention_mask = encoded["attention_mask"].unsqueeze(-1).expand(model_output.last_hidden_state.size()).float()
        masked_embeddings = model_output.last_hidden_state * attention_mask
        summed = torch.sum(masked_embeddings, dim=1)
        counts = torch.clamp(attention_mask.sum(dim=1), min=1e-9)
        sentence_embedding = summed / counts
        if self.projection is not None:
            sentence_embedding = torch.matmul(sentence_embedding, self.projection.T)
        normalized_embedding = torch.nn.functional.normalize(sentence_embedding, p=2, dim=1)

        return normalized_embedding.detach().cpu().tolist(), normalized_texts

    def semantic_similarity(self, offer_text: str, candidate_text: str) -> tuple[float, int, int, str, str]:
        offer_embedding, normalized_offer = self.embed_text(offer_text)
        candidate_embedding, normalized_candidate = self.embed_text(candidate_text)

        offer_vector = np.array(offer_embedding, dtype=np.float32)
        candidate_vector = np.array(candidate_embedding, dtype=np.float32)

        similarity = float(np.dot(offer_vector, candidate_vector) / (np.linalg.norm(offer_vector) * np.linalg.norm(candidate_vector)))

        # Contrast-enhancing rescaling matching the PHP SemanticMatchingService.
        # Raw cosine for French tech text clusters 0.85-0.95; floor=0.75 spreads
        # that range across 0-100 % for visible discrimination.
        floor = 0.75
        bounded_similarity = max(0.0, min(1.0, (similarity - floor) / (1.0 - floor)))

        return (
            round(bounded_similarity, 4),
            int(offer_vector.shape[0]),
            int(candidate_vector.shape[0]),
            normalized_offer,
            normalized_candidate,
        )


@lru_cache(maxsize=1)
def get_embedding_service() -> CamembertEmbeddingService:
    model_name = os.getenv("CAMEMBERT_MODEL", "camembert-base")
    device = os.getenv("CAMEMBERT_DEVICE", "cpu")

    return CamembertEmbeddingService(model_name=model_name, device=device)
