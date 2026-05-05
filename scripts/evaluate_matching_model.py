from __future__ import annotations

import argparse
import hashlib
import json
import math
import random
import sys
from copy import deepcopy
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from ml.app.preprocessing import normalize_text  # noqa: E402
from scripts.matching_dataset_tools import (  # noqa: E402
    FAMILY_REQUIRED_KEYWORDS,
    build_candidate_text,
    build_candidate_text_legacy,
    build_offer_text,
    developer_keywords,
    developer_role_families,
    load_dataset,
    offer_keywords,
    offer_role_families,
    weak_relevance,
)

DEFAULT_METHODS = ["baseline", "semantic", "enriched_proxy"]
SUPPORTED_METHODS = set(DEFAULT_METHODS)
PROHIBITED_REVIEW_KEYS = {
    "firstName",
    "lastName",
    "email",
    "companyName",
    "schoolName",
    "portfolioUrl",
    "githubUrl",
    "linkedinUrl",
}

PROXIMITY_SKILLS = {
    "javascript": {"react", "vue.js", "node.js", "typescript", "frontend"},
    "typescript": {"react", "vue.js", "node.js", "frontend", "full stack"},
    "react": {"javascript", "typescript", "frontend", "full stack"},
    "vue.js": {"javascript", "typescript", "frontend", "node.js"},
    "node.js": {"javascript", "typescript", "backend", "api development"},
    "php": {"symfony", "backend", "api development"},
    "symfony": {"php", "backend", "api development"},
    "sql": {"mysql", "postgresql", "backend", "data"},
    "postgresql": {"sql", "backend", "data"},
    "mysql": {"sql", "backend"},
    "docker": {"devops", "kubernetes", "ci/cd"},
    "kubernetes": {"devops", "docker"},
    "ci/cd": {"devops", "docker"},
    "observability": {"devops", "kubernetes"},
    "qa": {"test automation", "testing library"},
    "test automation": {"qa", "testing library"},
    "python": {"data", "airflow", "sql"},
    "airflow": {"data", "python"},
    "android": {"kotlin", "mobile"},
    "kotlin": {"android", "mobile"},
}

DEVELOPMENT_FAMILIES = {"backend", "frontend", "fullstack", "node"}
INCOMPATIBLE_FAMILY_SCORE_CAP = 0.74
FAVORABLE_SCORE_THRESHOLD = 0.8


class EmbeddingModel:
    def __init__(self, model_name: str, projection_path: str | None = None, device: str = "cpu") -> None:
        import torch
        from transformers import AutoModel, AutoTokenizer

        self.torch = torch
        self.device = torch.device(device)
        self.tokenizer = AutoTokenizer.from_pretrained(model_name)
        self.model = AutoModel.from_pretrained(model_name)
        self.model.to(self.device)
        self.model.eval()
        self.projection = self._load_projection(projection_path)

    def _load_projection(self, projection_path: str | None) -> Any | None:
        if not projection_path:
            return None
        payload = self.torch.load(projection_path, map_location=self.device)
        projection = payload["projection"] if isinstance(payload, dict) and "projection" in payload else payload
        if not isinstance(projection, self.torch.Tensor):
            projection = self.torch.tensor(projection, dtype=self.torch.float32)
        return projection.to(self.device, dtype=self.torch.float32)

    def encode(self, texts: list[str], batch_size: int = 8) -> list[list[float]]:
        vectors: list[list[float]] = []
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
            with self.torch.no_grad():
                outputs = self.model(**encoded)
            attention_mask = encoded["attention_mask"].unsqueeze(-1).expand(outputs.last_hidden_state.size()).float()
            masked = outputs.last_hidden_state * attention_mask
            summed = self.torch.sum(masked, dim=1)
            counts = self.torch.clamp(attention_mask.sum(dim=1), min=1e-9)
            embeddings = summed / counts
            if self.projection is not None:
                embeddings = self.torch.matmul(embeddings, self.projection.T)
            embeddings = self.torch.nn.functional.normalize(embeddings, p=2, dim=1)
            vectors.extend(embeddings.cpu().numpy().astype(float).tolist())
        return vectors


def dcg(relevances: list[int]) -> float:
    total = 0.0
    for idx, rel in enumerate(relevances, start=1):
        total += (2**rel - 1) / math.log2(idx + 1)
    return total


def dot(left: list[float], right: list[float]) -> float:
    return sum(a * b for a, b in zip(left, right))


def rank_indices(scores: list[float]) -> list[int]:
    return sorted(range(len(scores)), key=lambda idx: (-scores[idx], idx))


def stable_candidate_id(developer: dict[str, Any], index: int) -> str:
    profile = developer.get("profile", {})
    slug = str(profile.get("slug") or "")
    if slug:
        return slug
    seed = json.dumps(profile, ensure_ascii=False, sort_keys=True)
    return hashlib.sha1(seed.encode("utf-8")).hexdigest()[:12] or f"candidate-{index}"


def baseline_score(offer: dict[str, Any], developer: dict[str, Any]) -> float:
    return baseline_score_from_features(
        offer_keywords(offer),
        developer_keywords(developer),
        offer_role_families(offer),
        developer_role_families(developer),
        offer,
        developer,
    )


def baseline_score_from_features(
    offer_kw: set[str],
    developer_kw: set[str],
    offer_families: set[str],
    developer_families: set[str],
    offer: dict[str, Any],
    developer: dict[str, Any],
) -> float:

    keyword_overlap = len(offer_kw & developer_kw)
    family_overlap = len(offer_families & developer_families)
    if 0 == keyword_overlap and 0 == family_overlap:
        return 0.0

    profile = developer.get("profile", {})
    years_experience = int(profile.get("yearsExperience") or 0)
    offer_experience = int(offer.get("experienceLevel") or 0)
    experience_gap = max(0, offer_experience - years_experience)
    experience_score = 1.0 if experience_gap <= 1 else max(0.0, 1.0 - experience_gap * 0.25)

    location_score = 0.0
    if str(profile.get("locationType") or "") == str(offer.get("locationType") or ""):
        location_score = 0.08
    elif "remote" in {str(profile.get("locationType") or ""), str(offer.get("locationType") or "")}:
        location_score = 0.04

    raw_score = (
        min(0.45, keyword_overlap * 0.08)
        + min(0.30, family_overlap * 0.15)
        + experience_score * 0.17
        + location_score
    )
    return round(min(1.0, raw_score), 4)


def baseline_score_matrix(offers: list[dict[str, Any]], developers: list[dict[str, Any]]) -> list[list[float]]:
    features = build_offline_features(offers, developers)
    return baseline_score_matrix_from_features(offers, developers, features)


def build_offline_features(offers: list[dict[str, Any]], developers: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "offerKeywords": [offer_keywords(offer) for offer in offers],
        "developerKeywords": [developer_keywords(developer) for developer in developers],
        "offerFamilies": [offer_role_families(offer) for offer in offers],
        "developerFamilies": [developer_role_families(developer) for developer in developers],
    }


def baseline_score_matrix_from_features(
    offers: list[dict[str, Any]],
    developers: list[dict[str, Any]],
    features: dict[str, Any],
) -> list[list[float]]:
    return [
        [
            baseline_score_from_features(
                features["offerKeywords"][offer_index],
                features["developerKeywords"][developer_index],
                features["offerFamilies"][offer_index],
                features["developerFamilies"][developer_index],
                offer,
                developer,
            )
            for developer_index, developer in enumerate(developers)
        ]
        for offer_index, offer in enumerate(offers)
    ]


def semantic_score_matrix(
    offers: list[dict[str, Any]],
    developers: list[dict[str, Any]],
    model_name: str,
    projection_path: str | None,
    device: str,
    candidate_text_builder: Callable[[dict[str, Any]], str] = build_candidate_text,
) -> list[list[float]]:
    candidate_texts = [candidate_text_builder(developer) for developer in developers]
    offer_texts = [build_offer_text(offer) for offer in offers]

    model = EmbeddingModel(model_name=model_name, projection_path=projection_path, device=device)
    candidate_vectors = model.encode(candidate_texts)
    offer_vectors = model.encode(offer_texts)

    return [[dot(offer_vector, candidate_vector) for candidate_vector in candidate_vectors] for offer_vector in offer_vectors]


def is_junior_developer(developer: dict[str, Any]) -> bool:
    return int(developer.get("profile", {}).get("yearsExperience") or 0) <= 2


def fairness_metrics_from_group_scores(junior_scores: list[float], non_junior_scores: list[float]) -> dict[str, float | int | str]:
    junior_avg = sum(junior_scores) / len(junior_scores) if junior_scores else 0.0
    non_junior_avg = sum(non_junior_scores) / len(non_junior_scores) if non_junior_scores else 0.0
    junior_selection_rate = (
        sum(1 for score in junior_scores if score >= FAVORABLE_SCORE_THRESHOLD) / len(junior_scores)
        if junior_scores
        else 0.0
    )
    non_junior_selection_rate = (
        sum(1 for score in non_junior_scores if score >= FAVORABLE_SCORE_THRESHOLD) / len(non_junior_scores)
        if non_junior_scores
        else 0.0
    )
    selection_rate_ratio = (
        junior_selection_rate / non_junior_selection_rate
        if non_junior_selection_rate > 0.0
        else 1.0
    )
    disparate_impact_ratio = junior_avg / non_junior_avg if non_junior_avg > 0.0 else 1.0

    if not junior_scores or not non_junior_scores:
        assessment = "insufficient_comparison_population"
    elif selection_rate_ratio < 0.8:
        assessment = "junior_under_selected"
    elif selection_rate_ratio > 1.25:
        assessment = "junior_over_selected"
    else:
        assessment = "balanced_selection_rate"

    return {
        "junior_avg_score": round(junior_avg, 4),
        "non_junior_avg_score": round(non_junior_avg, 4),
        "disparate_impact_ratio": round(disparate_impact_ratio, 4),
        "junior_count": len(junior_scores),
        "non_junior_count": len(non_junior_scores),
        "junior_selection_rate": round(junior_selection_rate, 4),
        "non_junior_selection_rate": round(non_junior_selection_rate, 4),
        "selection_rate_ratio": round(selection_rate_ratio, 4),
        "score_gap": round(junior_avg - non_junior_avg, 4),
        "assessment": assessment,
    }


def fairness_metrics_from_score_matrix(scores: list[list[float]], developers: list[dict[str, Any]]) -> dict[str, float | int | str]:
    junior_scores: list[float] = []
    non_junior_scores: list[float] = []

    for offer_scores in scores:
        for developer_index, score in enumerate(offer_scores):
            if is_junior_developer(developers[developer_index]):
                junior_scores.append(float(score))
            else:
                non_junior_scores.append(float(score))

    return fairness_metrics_from_group_scores(junior_scores, non_junior_scores)


def summarize_cleaning_impact(
    cleaned_scores: list[list[float]],
    legacy_scores: list[list[float]],
    developers: list[dict[str, Any]],
) -> dict[str, Any]:
    cleaned_fairness = fairness_metrics_from_score_matrix(cleaned_scores, developers)
    legacy_fairness = fairness_metrics_from_score_matrix(legacy_scores, developers)

    junior_deltas: list[float] = []
    non_junior_deltas: list[float] = []
    for offer_index, cleaned_offer_scores in enumerate(cleaned_scores):
        for developer_index, cleaned_score in enumerate(cleaned_offer_scores):
            delta = float(cleaned_score) - float(legacy_scores[offer_index][developer_index])
            if is_junior_developer(developers[developer_index]):
                junior_deltas.append(delta)
            else:
                non_junior_deltas.append(delta)

    def average(values: list[float]) -> float:
        return sum(values) / len(values) if values else 0.0

    return {
        "cleaned": cleaned_fairness,
        "legacy_raw": legacy_fairness,
        "delta_cleaned_vs_legacy": {
            key: round(float(cleaned_fairness[key]) - float(legacy_fairness[key]), 4)
            for key in [
                "junior_avg_score",
                "non_junior_avg_score",
                "disparate_impact_ratio",
                "junior_selection_rate",
                "non_junior_selection_rate",
                "selection_rate_ratio",
                "score_gap",
            ]
        },
        "score_delta": {
            "junior_avg_delta": round(average(junior_deltas), 4),
            "junior_avg_abs_delta": round(average([abs(delta) for delta in junior_deltas]), 4),
            "non_junior_avg_delta": round(average(non_junior_deltas), 4),
            "non_junior_avg_abs_delta": round(average([abs(delta) for delta in non_junior_deltas]), 4),
        },
    }


def build_cleaning_impact_report(
    dataset: dict[str, Any],
    model_name: str,
    projection_path: str | None,
    device: str,
) -> dict[str, Any]:
    offers = dataset["offers"]
    developers = dataset["developers"]
    cleaned_scores = semantic_score_matrix(
        offers,
        developers,
        model_name=model_name,
        projection_path=projection_path,
        device=device,
        candidate_text_builder=build_candidate_text,
    )
    legacy_scores = semantic_score_matrix(
        offers,
        developers,
        model_name=model_name,
        projection_path=projection_path,
        device=device,
        candidate_text_builder=build_candidate_text_legacy,
    )

    return {
        "model": model_name,
        "projectionPath": projection_path,
        "offers": len(offers),
        "developers": len(developers),
        "candidateTextVariants": ["cleaned", "legacy_raw"],
        "semanticFairness": summarize_cleaning_impact(cleaned_scores, legacy_scores, developers),
    }


def counterfactual_location_type(value: str) -> str:
    normalized = str(value or "").strip().lower()
    if normalized == "remote":
        return "onsite"
    if normalized == "onsite":
        return "remote"
    if normalized == "hybrid":
        return "remote"
    return "remote"


def counterfactual_apparent_origin_name(value: str, fallback: str) -> str:
    normalized = " ".join(str(value or "").split())
    if not normalized:
        return fallback
    if normalized == fallback:
        return "Camille"
    return fallback


def apply_counterfactual_variant(dataset: dict[str, Any], variant: str) -> dict[str, Any]:
    mutated = deepcopy(dataset)

    for developer in mutated.get("developers", []):
        profile = developer.setdefault("profile", {})
        if variant == "location":
            profile["locationType"] = counterfactual_location_type(str(profile.get("locationType") or ""))
        elif variant == "school":
            for education in profile.get("education", []):
                education["schoolName"] = "Counterfactual School"
        elif variant == "apparent_origin":
            profile["firstName"] = counterfactual_apparent_origin_name(profile.get("firstName", ""), "Aminata")
            profile["lastName"] = counterfactual_apparent_origin_name(profile.get("lastName", ""), "Diallo")
        else:
            raise ValueError(f"Unsupported counterfactual variant: {variant}")

    return mutated


def summarize_counterfactual_impact(
    original_scores: list[list[float]],
    counterfactual_scores: list[list[float]],
    developers: list[dict[str, Any]],
) -> dict[str, Any]:
    junior_deltas: list[float] = []
    non_junior_deltas: list[float] = []
    changed_pairs = 0
    total_pairs = 0
    top1_changed = 0
    top5_overlap_sum = 0.0

    for offer_index, original_offer_scores in enumerate(original_scores):
        counterfactual_offer_scores = counterfactual_scores[offer_index]
        original_ranking = rank_indices(original_offer_scores)
        counterfactual_ranking = rank_indices(counterfactual_offer_scores)
        top5_overlap_sum += top_overlap(
            top_k_ids(original_offer_scores, developers),
            top_k_ids(counterfactual_offer_scores, developers),
        )
        if (original_ranking[0] if original_ranking else None) != (counterfactual_ranking[0] if counterfactual_ranking else None):
            top1_changed += 1

        for developer_index, original_score in enumerate(original_offer_scores):
            delta = float(counterfactual_offer_scores[developer_index]) - float(original_score)
            total_pairs += 1
            if abs(delta) > 1e-9:
                changed_pairs += 1
            if is_junior_developer(developers[developer_index]):
                junior_deltas.append(delta)
            else:
                non_junior_deltas.append(delta)

    def average(values: list[float]) -> float:
        return sum(values) / len(values) if values else 0.0

    offer_count = len(original_scores)
    return {
        "changed_pairs_rate": round(changed_pairs / total_pairs if total_pairs else 0.0, 4),
        "top1_changed_rate": round(top1_changed / offer_count if offer_count else 0.0, 4),
        "avg_top5_overlap": round(top5_overlap_sum / offer_count if offer_count else 0.0, 4),
        "score_delta": {
            "junior_avg_delta": round(average(junior_deltas), 4),
            "junior_avg_abs_delta": round(average([abs(delta) for delta in junior_deltas]), 4),
            "non_junior_avg_delta": round(average(non_junior_deltas), 4),
            "non_junior_avg_abs_delta": round(average([abs(delta) for delta in non_junior_deltas]), 4),
        },
    }


def build_counterfactual_fairness_report(
    dataset: dict[str, Any],
    model_name: str,
    projection_path: str | None,
    device: str,
    methods: list[str] | None = None,
) -> dict[str, Any]:
    selected_methods = parse_methods(methods or DEFAULT_METHODS)
    _, original_method_scores = evaluate(
        dataset,
        model_name=model_name,
        projection_path=projection_path,
        device=device,
        methods=selected_methods,
    )

    variants: dict[str, Any] = {}
    for variant in ["location", "school", "apparent_origin"]:
        mutated_dataset = apply_counterfactual_variant(dataset, variant)
        _, counterfactual_scores = evaluate(
            mutated_dataset,
            model_name=model_name,
            projection_path=projection_path,
            device=device,
            methods=selected_methods,
        )
        variants[variant] = {
            method: summarize_counterfactual_impact(
                original_method_scores[method],
                counterfactual_scores[method],
                dataset["developers"],
            )
            for method in selected_methods
            if method in original_method_scores and method in counterfactual_scores
        }

    return {
        "model": model_name,
        "projectionPath": projection_path,
        "offers": len(dataset.get("offers", [])),
        "developers": len(dataset.get("developers", [])),
        "methods": selected_methods,
        "variants": variants,
    }


def enriched_proxy_bonus(offer: dict[str, Any], developer: dict[str, Any]) -> float:
    return enriched_proxy_bonus_from_features(
        offer_keywords(offer),
        developer_keywords(developer),
        offer_role_families(offer),
        developer_role_families(developer),
        developer,
    )


def enriched_proxy_bonus_from_features(
    offer_kw: set[str],
    developer_kw: set[str],
    offer_families: set[str],
    developer_families: set[str],
    developer: dict[str, Any],
) -> float:
    if not offer_kw or not developer_kw:
        return 0.0

    if not compatible_primary_families(offer_families, developer_families):
        return 0.0

    direct_overlap = offer_kw & developer_kw
    proximity_hits = 0
    for candidate_skill in developer_kw - direct_overlap:
        if PROXIMITY_SKILLS.get(candidate_skill, set()) & offer_kw:
            proximity_hits += 1

    family_overlap = offer_families & developer_families
    bonus = min(0.08, proximity_hits * 0.025)
    bonus += min(0.04, max(0, len(family_overlap) - 1) * 0.02)

    profile = developer.get("profile", {})
    if str(profile.get("experienceLevel") or "").lower() == "junior" and direct_overlap:
        bonus += 0.015

    return round(min(0.15, bonus), 4)


def primary_families(families: set[str]) -> set[str]:
    development_overlap = families & DEVELOPMENT_FAMILIES
    if development_overlap:
        return development_overlap
    return set(families)


def compatible_primary_families(offer_families: set[str], developer_families: set[str]) -> bool:
    offer_primary = primary_families(offer_families)
    developer_primary = primary_families(developer_families)
    if not offer_primary or not developer_primary:
        return True
    if offer_primary & developer_primary:
        return True
    if "fullstack" in offer_primary and developer_primary & {"backend", "frontend", "node"}:
        return True
    return False


def has_blocking_role_mismatch(offer_families: set[str], developer: dict[str, Any]) -> bool:
    offer_primary = primary_families(offer_families)
    if not (offer_primary & DEVELOPMENT_FAMILIES):
        return False

    headline = str(developer.get("profile", {}).get("headline") or "").lower()
    has_developer_headline = any(
        token in headline
        for token in ["developpeur", "developer", "full stack", "frontend", "backend", "php", "symfony", "react", "node"]
    )
    if has_developer_headline:
        return False

    return any(
        token in headline
        for token in ["qa", "qualite", "quality", "devops", "platform engineer", "mobile", "android", "data engineer"]
    )


def apply_family_guardrail(score: float, offer_families: set[str], developer_families: set[str]) -> float:
    if compatible_primary_families(offer_families, developer_families):
        return score
    return min(score, INCOMPATIBLE_FAMILY_SCORE_CAP)


def apply_developer_guardrail(score: float, offer_families: set[str], developer_families: set[str], developer: dict[str, Any]) -> float:
    if has_blocking_role_mismatch(offer_families, developer):
        return min(score, INCOMPATIBLE_FAMILY_SCORE_CAP)
    return apply_family_guardrail(score, offer_families, developer_families)


def enriched_proxy_score_matrix(
    offers: list[dict[str, Any]],
    developers: list[dict[str, Any]],
    semantic_scores: list[list[float]],
    features: dict[str, Any] | None = None,
) -> list[list[float]]:
    current_features = features or build_offline_features(offers, developers)
    return [
        [
            round(apply_developer_guardrail(
                min(1.0, max(0.0, semantic_score + enriched_proxy_bonus_from_features(
                    current_features["offerKeywords"][offer_index],
                    current_features["developerKeywords"][developer_index],
                    current_features["offerFamilies"][offer_index],
                    current_features["developerFamilies"][developer_index],
                    developer,
                ))),
                current_features["offerFamilies"][offer_index],
                current_features["developerFamilies"][developer_index],
                developer,
            ), 4)
            for developer_index, (developer, semantic_score) in enumerate(zip(developers, offer_scores))
        ]
        for offer_index, (offer, offer_scores) in enumerate(zip(offers, semantic_scores))
    ]


def weak_relevance_from_features(
    offer_kw: set[str],
    developer_kw: set[str],
    offer_families: set[str],
    developer_families: set[str],
    offer: dict[str, Any],
    developer: dict[str, Any],
) -> int:
    family_overlap = offer_families & developer_families
    keyword_overlap = offer_kw & developer_kw
    years_experience = int(developer.get("profile", {}).get("yearsExperience") or 0)
    offer_experience = int(offer.get("experienceLevel") or 0)
    experience_fit = 1 if years_experience >= max(0, offer_experience - 1) else 0

    if not family_overlap:
        return 0

    best_specific_overlap = 0
    for family in family_overlap:
        if family == "fullstack":
            backend_overlap = len((offer_kw & FAMILY_REQUIRED_KEYWORDS["backend"]) & (developer_kw & FAMILY_REQUIRED_KEYWORDS["backend"]))
            frontend_overlap = len((offer_kw & FAMILY_REQUIRED_KEYWORDS["frontend"]) & (developer_kw & FAMILY_REQUIRED_KEYWORDS["frontend"]))
            if backend_overlap > 0 and frontend_overlap > 0:
                best_specific_overlap = max(best_specific_overlap, backend_overlap + frontend_overlap + 1)
            continue

        required_keywords = FAMILY_REQUIRED_KEYWORDS.get(family, set())
        specific_overlap = len((offer_kw & required_keywords) & (developer_kw & required_keywords))
        best_specific_overlap = max(best_specific_overlap, specific_overlap)

    if best_specific_overlap <= 0:
        return 0

    score = len(family_overlap) * 2 + best_specific_overlap + min(2, len(keyword_overlap)) + experience_fit
    if score >= 7:
        return 3
    if score >= 5:
        return 2
    if score >= 4:
        return 1
    return 0


def weak_relevance_matrix(
    offers: list[dict[str, Any]],
    developers: list[dict[str, Any]],
    features: dict[str, Any],
) -> list[list[int]]:
    return [
        [
            weak_relevance_from_features(
                features["offerKeywords"][offer_index],
                features["developerKeywords"][developer_index],
                features["offerFamilies"][offer_index],
                features["developerFamilies"][developer_index],
                offer,
                developer,
            )
            for developer_index, developer in enumerate(developers)
        ]
        for offer_index, offer in enumerate(offers)
    ]


def metrics_for_scores(
    method_name: str,
    scores: list[list[float]],
    offers: list[dict[str, Any]],
    developers: list[dict[str, Any]],
    top_n: int = 5,
    relevances_matrix: list[list[int]] | None = None,
) -> dict[str, Any]:
    recall_at_1 = 0.0
    recall_at_3 = 0.0
    recall_at_5 = 0.0
    reciprocal_rank = 0.0
    ndcg_at_5 = 0.0
    evaluated_offers = 0
    offer_summaries: list[dict[str, Any]] = []

    for offer_index, offer in enumerate(offers):
        relevances = (
            relevances_matrix[offer_index]
            if relevances_matrix is not None
            else [weak_relevance(offer, developer) for developer in developers]
        )
        positive_indices = [idx for idx, rel in enumerate(relevances) if rel > 0]
        if not positive_indices:
            continue

        evaluated_offers += 1
        ranking = rank_indices(scores[offer_index])
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
        for idx in ranking[:top_n]:
            profile = developers[int(idx)]["profile"]
            top_ranked.append({
                "candidateId": stable_candidate_id(developers[int(idx)], int(idx)),
                "fullName": f"{profile.get('firstName', '')} {profile.get('lastName', '')}".strip(),
                "score": round(float(scores[offer_index][int(idx)]), 4),
                "weakRelevance": relevances[int(idx)],
            })
        offer_summaries.append({
            "title": offer.get("title", ""),
            "positiveCandidates": len(positive_indices),
            "top5": top_ranked,
        })

    if evaluated_offers == 0:
        raise RuntimeError("No evaluable offers were found in the dataset.")

    return {
        "method": method_name,
        "offersEvaluated": evaluated_offers,
        "recall@1": round(recall_at_1 / evaluated_offers, 4),
        "recall@3": round(recall_at_3 / evaluated_offers, 4),
        "recall@5": round(recall_at_5 / evaluated_offers, 4),
        "mrr": round(reciprocal_rank / evaluated_offers, 4),
        "ndcg@5": round(ndcg_at_5 / evaluated_offers, 4),
        "sampleOffers": offer_summaries[:5],
    }


def parse_methods(raw_methods: str | list[str]) -> list[str]:
    if isinstance(raw_methods, str):
        methods = [method.strip() for method in raw_methods.split(",") if method.strip()]
    else:
        methods = raw_methods
    unknown = [method for method in methods if method not in SUPPORTED_METHODS]
    if unknown:
        raise ValueError(f"Unsupported method(s): {', '.join(unknown)}")
    return list(dict.fromkeys(methods))


def top_k_ids(scores: list[float], developers: list[dict[str, Any]], k: int = 5) -> list[str]:
    return [stable_candidate_id(developers[idx], idx) for idx in rank_indices(scores)[:k]]


def top_overlap(left: list[str], right: list[str]) -> float:
    if not left and not right:
        return 1.0
    return len(set(left) & set(right)) / max(1, min(len(left), len(right)))


def disagreement_by_offer(
    method_scores: dict[str, list[list[float]]],
    developers: list[dict[str, Any]],
    seed: int,
) -> list[tuple[int, float]]:
    method_names = list(method_scores)
    random_order = list(range(len(next(iter(method_scores.values()), []))))
    rng = random.Random(seed)
    rng.shuffle(random_order)
    tie_break = {offer_index: tie_index for tie_index, offer_index in enumerate(random_order)}

    disagreements = []
    for offer_index in range(len(next(iter(method_scores.values()), []))):
        rankings = [
            top_k_ids(method_scores[method][offer_index], developers)
            for method in method_names
        ]
        if len(rankings) < 2:
            disagreement = 0.0
        else:
            overlaps = [
                top_overlap(rankings[left], rankings[right])
                for left in range(len(rankings))
                for right in range(left + 1, len(rankings))
            ]
            disagreement = 1.0 - (sum(overlaps) / len(overlaps))
        disagreements.append((offer_index, disagreement))

    return sorted(disagreements, key=lambda item: (-item[1], tie_break[item[0]]))


def sanitize_review_value(value: Any) -> str:
    text = str(value or "").strip()
    for prohibited in PROHIBITED_REVIEW_KEYS:
        text = text.replace(prohibited, "[redacted]")
    return text


def candidate_review_summary(developer: dict[str, Any]) -> dict[str, Any]:
    profile = developer.get("profile", {})
    skills = [
        str(skill.get("skill", ""))
        for skill in profile.get("profileSkills", [])
        if skill.get("skill")
    ][:10]
    target_roles = [str(position) for position in profile.get("desiredPositions", [])][:5]
    return {
        "headline": sanitize_review_value(profile.get("headline", "")),
        "experienceLevel": sanitize_review_value(profile.get("experienceLevel", "")),
        "yearsExperience": profile.get("yearsExperience"),
        "locationType": sanitize_review_value(profile.get("locationType", "")),
        "targetRoles": target_roles,
        "skills": skills,
    }


def build_review_pack(
    dataset: dict[str, Any],
    method_scores: dict[str, list[list[float]]],
    sample_size: int,
    seed: int,
) -> str:
    offers = dataset["offers"]
    developers = dataset["developers"]
    preferred_method = (
        "enriched_proxy"
        if "enriched_proxy" in method_scores
        else ("semantic" if "semantic" in method_scores else next(iter(method_scores)))
    )
    selected = disagreement_by_offer(method_scores, developers, seed)[:max(0, sample_size)]

    lines = [
        "# Revue humaine anonymisee du matching",
        "",
        "Objectif : noter la pertinence du top 5 sans exposer l'identite des candidats.",
        "",
        "Bareme suggere : 1 = non pertinent, 3 = acceptable, 5 = excellent match.",
        "",
    ]

    for review_index, (offer_index, disagreement) in enumerate(selected, start=1):
        offer = offers[offer_index]
        lines.extend([
            f"## Offre {review_index}",
            "",
            f"- Titre : {sanitize_review_value(offer.get('title', ''))}",
            f"- Description : {sanitize_review_value(offer.get('description', ''))}",
            f"- Localisation : {sanitize_review_value(offer.get('location', ''))}",
            f"- Type : {sanitize_review_value(offer.get('locationType', ''))}",
            f"- Experience cible : {sanitize_review_value(offer.get('experienceLevel', ''))}",
            f"- Desaccord inter-methodes : {round(disagreement, 3)}",
            "",
        ])

        ranking = rank_indices(method_scores[preferred_method][offer_index])[:5]
        for candidate_position, developer_index in enumerate(ranking):
            label = chr(ord("A") + candidate_position)
            developer = developers[developer_index]
            summary = candidate_review_summary(developer)
            score_lines = [
                f"{method}: {round(method_scores[method][offer_index][developer_index], 4)}"
                for method in method_scores
            ]
            lines.extend([
                f"### Candidat {label}",
                "",
                f"- Headline : {summary['headline']}",
                f"- Seniorite : {summary['experienceLevel']} ({summary['yearsExperience']} an(s))",
                f"- Mode de travail : {summary['locationType']}",
                f"- Roles vises : {', '.join(summary['targetRoles'])}",
                f"- Competences : {', '.join(summary['skills'])}",
                f"- Scores offline : {' | '.join(score_lines)}",
                "- Note humaine 1-5 : ",
                "- Decision : ",
                "- Commentaire : ",
                "",
            ])

    return "\n".join(lines).strip() + "\n"


def report_deltas(method_reports: dict[str, dict[str, Any]]) -> dict[str, Any]:
    if "baseline" not in method_reports:
        return {}
    baseline = method_reports["baseline"]
    deltas: dict[str, Any] = {}
    for method, report in method_reports.items():
        if method == "baseline":
            continue
        deltas[f"{method}_vs_baseline"] = {
            metric: round(float(report[metric]) - float(baseline[metric]), 4)
            for metric in ["recall@1", "recall@3", "recall@5", "mrr", "ndcg@5"]
        }
    return deltas


def evaluate(
    dataset: dict[str, Any],
    model_name: str = "camembert-base",
    projection_path: str | None = None,
    device: str = "cpu",
    methods: list[str] | None = None,
) -> tuple[dict[str, Any], dict[str, list[list[float]]]]:
    selected_methods = parse_methods(methods or DEFAULT_METHODS)
    developers = dataset["developers"]
    offers = dataset["offers"]
    features = build_offline_features(offers, developers)
    relevances = weak_relevance_matrix(offers, developers, features)

    method_scores: dict[str, list[list[float]]] = {}
    if "baseline" in selected_methods:
        method_scores["baseline"] = baseline_score_matrix_from_features(offers, developers, features)

    semantic_needed = "semantic" in selected_methods or "enriched_proxy" in selected_methods
    if semantic_needed:
        semantic_scores = semantic_score_matrix(
            offers,
            developers,
            model_name=model_name,
            projection_path=projection_path,
            device=device,
        )
        if "semantic" in selected_methods:
            method_scores["semantic"] = semantic_scores
        if "enriched_proxy" in selected_methods:
            method_scores["enriched_proxy"] = enriched_proxy_score_matrix(offers, developers, semantic_scores, features)

    method_reports = {
        method: metrics_for_scores(method, scores, offers, developers, relevances_matrix=relevances)
        for method, scores in method_scores.items()
    }

    report = {
        "model": model_name,
        "projectionPath": projection_path,
        "methodsRequested": selected_methods,
        "offers": len(offers),
        "developers": len(developers),
        "labelSource": "weak_relevance heuristic labels used only for evaluation",
        "methods": method_reports,
        "deltas": report_deltas(method_reports),
    }

    if "semantic" in method_reports:
        report.update({
            "offersEvaluated": method_reports["semantic"]["offersEvaluated"],
            "recall@1": method_reports["semantic"]["recall@1"],
            "recall@3": method_reports["semantic"]["recall@3"],
            "recall@5": method_reports["semantic"]["recall@5"],
            "mrr": method_reports["semantic"]["mrr"],
            "ndcg@5": method_reports["semantic"]["ndcg@5"],
            "sampleOffers": method_reports["semantic"]["sampleOffers"],
        })

    return report, method_scores


def assert_review_pack_is_anonymized(content: str) -> None:
    leaked = [key for key in PROHIBITED_REVIEW_KEYS if key in content]
    if leaked:
        raise RuntimeError(f"Review pack contains prohibited field labels: {', '.join(leaked)}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Evaluate DevSpot matching quality with weak supervision.")
    parser.add_argument("--dataset", required=True, help="Path to the cleaned dataset JSON")
    parser.add_argument("--model", default="camembert-base", help="HuggingFace model name or local path")
    parser.add_argument("--projection", help="Optional path to a trained projection .pt file")
    parser.add_argument("--device", default="cpu", help="Torch device to use")
    parser.add_argument("--output", help="Optional path to save the JSON evaluation report")
    parser.add_argument("--methods", default=",".join(DEFAULT_METHODS), help="Comma-separated methods: baseline,semantic,enriched_proxy")
    parser.add_argument("--review-output", help="Optional path to save an anonymized human review pack")
    parser.add_argument("--review-sample-size", type=int, default=10, help="Number of offers to include in the review pack")
    parser.add_argument("--review-seed", type=int, default=42, help="Deterministic seed for review offer tie-breaking")
    parser.add_argument("--cleaning-impact-output", help="Optional path to save a semantic cleaned-vs-legacy raw junior impact report")
    parser.add_argument("--counterfactual-output", help="Optional path to save a counterfactual fairness report for location, school, and apparent origin")
    args = parser.parse_args()

    dataset = load_dataset(args.dataset)
    report, method_scores = evaluate(
        dataset,
        model_name=args.model,
        projection_path=args.projection,
        device=args.device,
        methods=parse_methods(args.methods),
    )

    payload = json.dumps(report, ensure_ascii=False, indent=2)
    print(payload)
    if args.output:
        Path(args.output).write_text(payload + "\n", encoding="utf-8")

    if args.review_output:
        review_pack = build_review_pack(
            dataset,
            method_scores,
            sample_size=args.review_sample_size,
            seed=args.review_seed,
        )
        assert_review_pack_is_anonymized(review_pack)
        target = Path(args.review_output)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(review_pack, encoding="utf-8")

    if args.cleaning_impact_output:
        cleaning_impact_report = build_cleaning_impact_report(
            dataset,
            model_name=args.model,
            projection_path=args.projection,
            device=args.device,
        )
        target = Path(args.cleaning_impact_output)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(cleaning_impact_report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    if args.counterfactual_output:
        counterfactual_report = build_counterfactual_fairness_report(
            dataset,
            model_name=args.model,
            projection_path=args.projection,
            device=args.device,
            methods=parse_methods(args.methods),
        )
        target = Path(args.counterfactual_output)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(counterfactual_report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
