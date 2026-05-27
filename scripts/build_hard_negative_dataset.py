from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
from typing import Any

from matching_dataset_tools import (
    build_candidate_text,
    load_dataset,
    normalize_key,
    save_dataset,
)
from evaluate_matching_model import build_offline_features, baseline_score_matrix_from_features, stable_candidate_id


ROLE_RULES: list[dict[str, Any]] = [
    {
        "titleContains": ["full stack"],
        "id": "fullstack_react_symfony",
        "groups": [["react"], ["symfony"], ["php", "sql", "postgresql", "mysql", "api"]],
        "reason": "React + Symfony full stack",
    },
    {
        "titleContains": ["devops engineer"],
        "id": "devops",
        "anyCount": ["devops", "docker", "kubernetes", "ci cd", "observability", "linux", "sre", "platform"],
        "minimumHits": 3,
        "reason": "DevOps delivery stack",
    },
    {
        "titleContains": ["android"],
        "id": "android_kotlin",
        "groups": [["android"], ["kotlin"]],
        "reason": "Android + Kotlin",
    },
    {
        "titleContains": ["backend symfony"],
        "id": "backend_symfony",
        "groups": [["symfony"], ["php"], ["sql", "postgresql", "mysql", "api"]],
        "reason": "Symfony backend",
    },
    {
        "titleContains": ["qa engineer"],
        "id": "qa_automation",
        "groups": [["qa", "qualite", "quality"], ["automation", "automatisation", "testing", "test"]],
        "reason": "QA + test automation",
    },
    {
        "titleContains": ["integrateur frontend"],
        "id": "frontend_accessible",
        "groups": [["integrateur", "frontend", "html"], ["css", "tailwind"], ["accessible", "accessibilite", "design system", "ui"]],
        "reason": "Frontend integration/accessibility",
    },
    {
        "titleEquals": "developpeur php",
        "id": "php_backend",
        "groups": [["php"], ["symfony", "mysql", "sql", "postgresql", "api"]],
        "reason": "PHP backend",
    },
    {
        "titleContains": ["frontend developer react"],
        "id": "frontend_react",
        "groups": [["react"], ["typescript", "javascript"], ["css", "html", "tailwind"]],
        "reason": "React frontend",
    },
    {
        "titleContains": ["data engineer"],
        "id": "data_python_sql",
        "groups": [["python"], ["sql", "postgresql", "airflow", "etl"]],
        "reason": "Python/SQL data",
    },
    {
        "titleContains": ["node.js"],
        "id": "node_api",
        "groups": [["node"], ["typescript", "javascript"], ["api", "postgresql", "sql"]],
        "reason": "Node.js API",
    },
    {
        "titleContains": ["platform engineer"],
        "id": "platform_cloud",
        "groups": [["platform", "sre", "cloud"], ["docker", "kubernetes", "observability", "ci cd", "linux"]],
        "reason": "Platform/SRE cloud",
    },
    {
        "titleContains": ["analytics engineer"],
        "id": "analytics_dbt",
        "groups": [["analytics", "dbt"], ["sql", "python", "data"]],
        "reason": "Analytics/dbt",
    },
    {
        "titleContains": ["appsec"],
        "id": "appsec_junior",
        "groups": [["appsec", "security", "securite", "cyber"], ["php", "react", "web", "api", "application"]],
        "juniorPreferred": True,
        "reason": "Application security",
    },
    {
        "titleContains": ["vue"],
        "id": "vue_node",
        "groups": [["vue"], ["node"], ["javascript", "typescript"]],
        "reason": "Vue.js + Node.js",
    },
]


def has_any(text: str, terms: list[str]) -> bool:
    return any(normalize_key(term) in text for term in terms)


def rule_for_offer(offer: dict[str, Any]) -> dict[str, Any]:
    title = normalize_key(str(offer.get("title") or ""))
    for rule in ROLE_RULES:
        if rule.get("titleEquals") == title:
            return rule
        if all(token in title for token in rule.get("titleContains", [])):
            return rule
    return {"id": "generic", "groups": [], "reason": "Generic role"}


def grade_candidate(offer: dict[str, Any], developer: dict[str, Any], candidate_text: str) -> tuple[int, str]:
    rule = rule_for_offer(offer)
    grade = 0

    if "anyCount" in rule:
        hits = sum(1 for term in rule["anyCount"] if normalize_key(term) in candidate_text)
        minimum_hits = int(rule.get("minimumHits", 3))
        if hits >= minimum_hits:
            grade = 3
        elif hits >= max(2, minimum_hits - 1):
            grade = 2
        elif hits >= 1:
            grade = 1
    else:
        groups = rule.get("groups", [])
        matched_groups = sum(1 for group in groups if has_any(candidate_text, group))
        if groups and matched_groups == len(groups):
            grade = 3
        elif groups and matched_groups >= max(2, len(groups) - 1):
            grade = 2
        elif matched_groups >= 1:
            grade = 1

    if grade <= 0:
        return 0, str(rule["reason"])

    profile = developer.get("profile", {})
    years_experience = int(profile.get("yearsExperience") or 0)
    required_years = int(offer.get("experienceLevel") or 0)
    if years_experience < max(0, required_years - 2):
        grade = min(grade, 1)
    if rule.get("juniorPreferred") and years_experience > 4:
        grade = min(grade, 2)

    return grade, str(rule["reason"])


def deterministic_tie_break(offer_index: int, developer_index: int) -> int:
    digest = hashlib.sha1(f"{offer_index}:{developer_index}:hard-v1".encode("utf-8")).hexdigest()
    return int(digest[:8], 16)


def select_balanced_positive_candidates(
    candidates: list[dict[str, Any]],
    developers: list[dict[str, Any]],
    offer_index: int,
    limit: int,
) -> list[dict[str, Any]]:
    selected: list[dict[str, Any]] = []
    seen: set[int] = set()

    for grade in [3, 2, 1]:
        buckets: dict[str, list[dict[str, Any]]] = {}
        for candidate in candidates:
            if candidate["relevance"] != grade:
                continue
            level = str(developers[candidate["developerIndex"]].get("profile", {}).get("experienceLevel") or "unknown")
            buckets.setdefault(level, []).append(candidate)

        for bucket in buckets.values():
            bucket.sort(
                key=lambda item: (
                    abs(item["yearsGap"]),
                    deterministic_tie_break(offer_index, item["developerIndex"]),
                )
            )

        progressed = True
        while progressed and len(selected) < limit:
            progressed = False
            for level in ["junior", "mid", "senior", "lead", "unknown"]:
                bucket = buckets.get(level, [])
                while bucket and bucket[0]["developerIndex"] in seen:
                    bucket.pop(0)
                if not bucket:
                    continue
                item = bucket.pop(0)
                selected.append(item)
                seen.add(item["developerIndex"])
                progressed = True
                if len(selected) >= limit:
                    break

    return selected


def summary(values: list[int]) -> dict[str, float | int]:
    ordered = sorted(values)
    if not ordered:
        return {"min": 0, "p25": 0, "median": 0, "p75": 0, "max": 0, "avg": 0.0}

    def percentile(value: float) -> int:
        return ordered[int((len(ordered) - 1) * value)]

    return {
        "min": ordered[0],
        "p25": percentile(0.25),
        "median": percentile(0.5),
        "p75": percentile(0.75),
        "max": ordered[-1],
        "avg": round(sum(values) / len(values), 2),
    }


def build_dataset(
    dataset: dict[str, Any],
    positives_per_offer: int,
    hard_negatives_per_offer: int,
) -> tuple[dict[str, Any], dict[str, Any]]:
    offers = dataset.get("offers", [])
    developers = dataset.get("developers", [])
    candidate_texts = [normalize_key(build_candidate_text(developer)) for developer in developers]
    features = build_offline_features(offers, developers)
    baseline_scores = baseline_score_matrix_from_features(offers, developers, features)

    offer_labels: list[dict[str, Any]] = []
    positive_counts: list[int] = []
    hard_negative_counts: list[int] = []
    warnings: list[dict[str, Any]] = []

    for offer_index, offer in enumerate(offers):
        candidates: list[dict[str, Any]] = []
        for developer_index, developer in enumerate(developers):
            relevance, reason = grade_candidate(offer, developer, candidate_texts[developer_index])
            if relevance <= 0:
                continue
            years_gap = int(developer.get("profile", {}).get("yearsExperience") or 0) - int(offer.get("experienceLevel") or 0)
            candidates.append({
                "developerIndex": developer_index,
                "relevance": relevance,
                "reason": reason,
                "yearsGap": years_gap,
            })

        positives = select_balanced_positive_candidates(candidates, developers, offer_index, positives_per_offer)
        if len(positives) < min(5, positives_per_offer):
            warnings.append({
                "offerIndex": offer_index,
                "title": offer.get("title", ""),
                "positives": len(positives),
            })

        positive_indexes = {candidate["developerIndex"] for candidate in positives}
        hard_negatives = [
            {"developerIndex": index, "score": baseline_scores[offer_index][index]}
            for index in range(len(developers))
            if index not in positive_indexes and baseline_scores[offer_index][index] > 0
        ]
        hard_negatives.sort(key=lambda item: (-item["score"], item["developerIndex"]))
        hard_negatives = hard_negatives[:hard_negatives_per_offer]

        positive_counts.append(len(positives))
        hard_negative_counts.append(len(hard_negatives))
        offer_labels.append({
            "offerIndex": offer_index,
            "offerTitle": offer.get("title", ""),
            "rule": rule_for_offer(offer)["id"],
            "positiveCandidates": [
                {
                    "candidateId": stable_candidate_id(developers[candidate["developerIndex"]], candidate["developerIndex"]),
                    "relevance": candidate["relevance"],
                    "reason": candidate["reason"],
                }
                for candidate in positives
            ],
            "hardNegativeCandidates": [
                {
                    "candidateId": stable_candidate_id(developers[candidate["developerIndex"]], candidate["developerIndex"]),
                    "reason": "high baseline score but excluded from explicit gold set",
                }
                for candidate in hard_negatives
            ],
        })

    dataset["evaluationLabels"] = {
        "version": "hard-negatives-v1",
        "labelSource": "explicit sparse labels generated for hard-negative offline evaluation; non-listed candidates are relevance 0",
        "nonListedCandidatesAre": 0,
        "maxPositiveCandidatesPerOffer": positives_per_offer,
        "hardNegativeCandidatesPerOffer": hard_negatives_per_offer,
        "notes": [
            "These labels intentionally avoid treating every broad same-family profile as positive.",
            "Hard negatives are high-scoring lexical candidates excluded from the explicit gold set.",
            "The labels are generated heuristically and should be replaced or validated by human review before strong fairness claims.",
        ],
        "offers": offer_labels,
    }

    report = {
        "offers": len(offers),
        "developers": len(developers),
        "positiveCandidatesPerOffer": summary(positive_counts),
        "hardNegativesPerOffer": summary(hard_negative_counts),
        "totalExplicitPositiveLabels": sum(positive_counts),
        "totalHardNegatives": sum(hard_negative_counts),
        "warnings": warnings,
    }
    return dataset, report


def main() -> int:
    parser = argparse.ArgumentParser(description="Build a sparse hard-negative DevSpot matching dataset.")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--report-output", required=True)
    parser.add_argument("--positives-per-offer", type=int, default=12)
    parser.add_argument("--hard-negatives-per-offer", type=int, default=12)
    args = parser.parse_args()

    dataset = load_dataset(args.input)
    improved_dataset, report = build_dataset(
        dataset,
        positives_per_offer=args.positives_per_offer,
        hard_negatives_per_offer=args.hard_negatives_per_offer,
    )
    report = {"input": args.input, "output": args.output, **report}

    save_dataset(args.output, improved_dataset)
    Path(args.report_output).parent.mkdir(parents=True, exist_ok=True)
    Path(args.report_output).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
