from __future__ import annotations

import argparse
import json
import pickle
import random
import sys
from copy import deepcopy
from pathlib import Path
from typing import Any

import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import average_precision_score, roc_auc_score

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts import evaluate_matching_model as evaluator  # noqa: E402
from scripts.matching_dataset_tools import load_dataset  # noqa: E402


def split_offer_indices(offer_count: int, seed: int, train_ratio: float, validation_ratio: float) -> dict[str, list[int]]:
    indices = list(range(offer_count))
    rng = random.Random(seed)
    rng.shuffle(indices)

    train_end = int(offer_count * train_ratio)
    validation_end = train_end + int(offer_count * validation_ratio)
    return {
        "train": sorted(indices[:train_end]),
        "validation": sorted(indices[train_end:validation_end]),
        "test": sorted(indices[validation_end:]),
    }


def subset_dataset_by_offer_indices(dataset: dict[str, Any], offer_indices: list[int]) -> dict[str, Any]:
    subset = {
        key: deepcopy(value)
        for key, value in dataset.items()
        if key not in {"offers", "evaluationLabels"}
    }
    subset["offers"] = [deepcopy(dataset["offers"][offer_index]) for offer_index in offer_indices]

    labels = dataset.get("evaluationLabels")
    if isinstance(labels, dict):
        labels_by_offer_index = {
            int(offer_payload.get("offerIndex")): deepcopy(offer_payload)
            for offer_payload in labels.get("offers", [])
            if isinstance(offer_payload, dict) and isinstance(offer_payload.get("offerIndex"), int)
        }
        remapped_offers: list[dict[str, Any]] = []
        for new_index, original_index in enumerate(offer_indices):
            offer_payload = labels_by_offer_index.get(original_index)
            if not offer_payload:
                continue
            offer_payload["offerIndex"] = new_index
            remapped_offers.append(offer_payload)

        subset["evaluationLabels"] = {
            **deepcopy(labels),
            "offers": remapped_offers,
        }

    return subset


def build_pair_dataset(
    dataset: dict[str, Any],
    offer_indices: list[int],
    semantic_scores: list[list[float]],
    enriched_scores: list[list[float]],
    features: dict[str, Any],
) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    developers = dataset["developers"]
    offers = dataset["offers"]
    relevances = evaluator.explicit_relevance_matrix(dataset)
    if relevances is None:
        raise RuntimeError("Explicit evaluation labels are required to train the reranker.")

    rows: list[list[float]] = []
    labels: list[int] = []
    junior_flags: list[int] = []

    for offer_index in offer_indices:
        offer = offers[offer_index]
        for developer_index, developer in enumerate(developers):
            rows.append(
                evaluator.reranker_feature_vector(
                    offer,
                    developer,
                    semantic_scores[offer_index][developer_index],
                    enriched_scores[offer_index][developer_index],
                    features["offerKeywords"][offer_index],
                    features["developerKeywords"][developer_index],
                    features["offerFamilies"][offer_index],
                    features["developerFamilies"][developer_index],
                    features["candidateTexts"][developer_index],
                )
            )
            labels.append(1 if relevances[offer_index][developer_index] > 0 else 0)
            junior_flags.append(1 if evaluator.is_junior_developer(developer) else 0)

    return np.asarray(rows, dtype=np.float32), np.asarray(labels, dtype=np.int8), np.asarray(junior_flags, dtype=np.int8)


def binary_metrics(labels: np.ndarray, probabilities: np.ndarray) -> dict[str, float]:
    if labels.size == 0:
        return {"rocAuc": 0.0, "averagePrecision": 0.0, "positiveRate": 0.0}

    unique_labels = np.unique(labels)
    roc_auc = roc_auc_score(labels, probabilities) if len(unique_labels) > 1 else 0.0
    average_precision = average_precision_score(labels, probabilities) if np.any(labels == 1) else 0.0
    return {
        "rocAuc": round(float(roc_auc), 4),
        "averagePrecision": round(float(average_precision), 4),
        "positiveRate": round(float(labels.mean()), 4),
    }


def train_reranker(
    train_x: np.ndarray,
    train_y: np.ndarray,
    train_junior_flags: np.ndarray,
    seed: int,
    max_iter: int,
    junior_positive_weight: float,
) -> HistGradientBoostingClassifier:
    positive_count = max(1, int(train_y.sum()))
    negative_count = max(1, int(train_y.shape[0] - positive_count))
    positive_weight = min(20.0, negative_count / positive_count)
    sample_weight = np.where(train_y == 1, positive_weight, 1.0).astype(np.float32)
    sample_weight = np.where(
        (train_y == 1) & (train_junior_flags == 1),
        sample_weight * junior_positive_weight,
        sample_weight,
    )

    model = HistGradientBoostingClassifier(
        learning_rate=0.05,
        max_depth=6,
        max_iter=max_iter,
        min_samples_leaf=20,
        l2_regularization=0.1,
        random_state=seed,
    )
    model.fit(train_x, train_y, sample_weight=sample_weight)
    return model


def save_json(path: str | None, payload: dict[str, Any]) -> None:
    if not path:
        return
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Train a lightweight tabular reranker for DevSpot matching.")
    parser.add_argument("--dataset", required=True, help="Path to the hard-negative dataset JSON")
    parser.add_argument("--model", default="camembert-base", help="HuggingFace model name or local path")
    parser.add_argument("--projection", help="Optional path to a trained projection .pt file")
    parser.add_argument("--device", default="cpu", help="Torch device to use")
    parser.add_argument("--output", required=True, help="Path to the output reranker .pkl file")
    parser.add_argument("--split-report-output", help="Optional path to save split metadata and train/validation metrics")
    parser.add_argument("--eval-output", help="Optional path to save the held-out test evaluation report")
    parser.add_argument("--fairness-output", help="Optional path to save the held-out fairness report")
    parser.add_argument("--review-output", help="Optional path to save a baseline vs reranker review pack on the held-out test split")
    parser.add_argument("--review-sample-size", type=int, default=10, help="Number of offers to include in the review pack")
    parser.add_argument("--seed", type=int, default=42, help="Deterministic random seed")
    parser.add_argument("--train-ratio", type=float, default=0.7, help="Train offer ratio")
    parser.add_argument("--validation-ratio", type=float, default=0.15, help="Validation offer ratio")
    parser.add_argument("--max-iter", type=int, default=300, help="Maximum number of boosting iterations")
    parser.add_argument("--junior-positive-weight", type=float, default=2.0, help="Extra multiplier applied to positive junior training pairs")
    args = parser.parse_args()

    random.seed(args.seed)
    np.random.seed(args.seed)

    dataset = load_dataset(args.dataset)
    developers = dataset["developers"]
    offers = dataset["offers"]
    splits = split_offer_indices(len(offers), args.seed, args.train_ratio, args.validation_ratio)
    if not splits["test"]:
        raise RuntimeError("The configured split left no offers for the test set.")

    features = evaluator.build_offline_features(offers, developers)
    semantic_scores = evaluator.semantic_score_matrix(
        offers,
        developers,
        model_name=args.model,
        projection_path=args.projection,
        device=args.device,
    )
    enriched_scores = evaluator.enriched_proxy_score_matrix(offers, developers, semantic_scores, features)

    train_x, train_y, train_junior_flags = build_pair_dataset(dataset, splits["train"], semantic_scores, enriched_scores, features)
    validation_x, validation_y, _validation_junior_flags = build_pair_dataset(dataset, splits["validation"], semantic_scores, enriched_scores, features)
    test_x, test_y, _test_junior_flags = build_pair_dataset(dataset, splits["test"], semantic_scores, enriched_scores, features)

    model = train_reranker(train_x, train_y, train_junior_flags, args.seed, args.max_iter, args.junior_positive_weight)
    validation_probabilities = model.predict_proba(validation_x)[:, 1] if validation_x.size else np.asarray([], dtype=np.float32)
    test_probabilities = model.predict_proba(test_x)[:, 1] if test_x.size else np.asarray([], dtype=np.float32)

    payload = {
        "model": model,
        "featureNames": evaluator.RERANKER_FEATURE_NAMES,
        "dataset": str(Path(args.dataset).resolve()),
        "baseModel": args.model,
        "projectionPath": args.projection,
        "seed": args.seed,
        "juniorPositiveWeight": args.junior_positive_weight,
        "splits": splits,
        "validationMetrics": binary_metrics(validation_y, validation_probabilities),
        "testMetrics": binary_metrics(test_y, test_probabilities),
    }
    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("wb") as handle:
        pickle.dump(payload, handle)

    split_report = {
        "dataset": args.dataset,
        "output": str(output_path.resolve()),
        "offers": len(offers),
        "developers": len(developers),
        "featureNames": evaluator.RERANKER_FEATURE_NAMES,
        "splits": {key: len(value) for key, value in splits.items()},
        "juniorPositiveWeight": args.junior_positive_weight,
        "validationMetrics": payload["validationMetrics"],
        "testMetrics": payload["testMetrics"],
    }
    print(json.dumps(split_report, ensure_ascii=False, indent=2))
    save_json(args.split_report_output, split_report)

    if args.eval_output or args.fairness_output or args.review_output:
        test_dataset = subset_dataset_by_offer_indices(dataset, splits["test"])
        test_report, test_scores = evaluator.evaluate(
            test_dataset,
            model_name=args.model,
            projection_path=args.projection,
            device=args.device,
            methods=["baseline", "semantic", "enriched_proxy", "reranker"],
            reranker_model_path=str(output_path),
        )

        if args.eval_output:
            target = Path(args.eval_output)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(json.dumps(test_report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

        if args.fairness_output:
            fairness_report = {
                "dataset": args.dataset,
                "split": "test",
                "methods": {
                    method: evaluator.fairness_metrics_from_score_matrix(test_scores[method], test_dataset["developers"])
                    for method in ["baseline", "semantic", "enriched_proxy", "reranker"]
                },
            }
            save_json(args.fairness_output, fairness_report)

        if args.review_output:
            review_pack = evaluator.build_review_pack(
                test_dataset,
                {"reranker": test_scores["reranker"], "baseline": test_scores["baseline"]},
                sample_size=args.review_sample_size,
                seed=args.seed,
            )
            evaluator.assert_review_pack_is_anonymized(review_pack)
            target = Path(args.review_output)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(review_pack, encoding="utf-8")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
