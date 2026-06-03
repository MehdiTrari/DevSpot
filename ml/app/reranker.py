from __future__ import annotations

import os
import pickle
from functools import lru_cache
from pathlib import Path

import numpy as np


DEFAULT_RERANKER_PATH = str(Path(__file__).resolve().parents[1] / "models" / "devspot-matching-reranker-v1.pkl")


class RerankerService:
    def __init__(self, model_path: str | None = None) -> None:
        self.model_path = model_path or os.getenv("RERANKER_MODEL_PATH", DEFAULT_RERANKER_PATH)
        self.model = None
        self.feature_names: list[str] = []
        self.available = False
        self._load()

    def _load(self) -> None:
        path = Path(self.model_path)
        if not path.is_file():
            return

        try:
            with path.open("rb") as handle:
                payload = pickle.load(handle)
        except Exception:
            return

        if not isinstance(payload, dict) or "model" not in payload:
            return

        feature_names = payload.get("featureNames", [])
        self.feature_names = [str(name) for name in feature_names] if isinstance(feature_names, list) else []
        self.model = payload["model"]
        self.available = hasattr(self.model, "predict_proba")

    def score_features(self, features: list[list[float]]) -> list[float]:
        if not self.available or self.model is None:
            raise RuntimeError("Reranker model is not available.")

        matrix = np.asarray(features, dtype=np.float32)
        probabilities = self.model.predict_proba(matrix)[:, 1]

        return [round(float(score), 6) for score in probabilities.tolist()]


@lru_cache(maxsize=1)
def get_reranker_service() -> RerankerService:
    return RerankerService()
