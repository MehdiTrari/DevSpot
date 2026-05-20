from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts import evaluate_matching_model as evaluator


def make_developer(
    slug: str,
    headline: str,
    skills: list[str],
    *,
    first_name: str = "Alice",
    last_name: str = "Martin",
    company_name: str = "Secret Corp",
    school_name: str = "Secret School",
) -> dict:
    return {
        "user": {"email": f"{slug}@demo.devspot.local"},
        "profile": {
            "firstName": first_name,
            "lastName": last_name,
            "headline": headline,
            "bio": "Contact portfolio https://portfolio.example.test alice@example.test",
            "slug": slug,
            "locationType": "remote",
            "experienceLevel": "junior",
            "yearsExperience": 2,
            "portfolioUrl": "https://portfolio.example.test",
            "githubUrl": "https://github.com/example",
            "linkedinUrl": "https://linkedin.example.test/in/example",
            "desiredPositions": [headline],
            "profileSkills": [
                {"skill": skill, "level": "intermediate", "years": 1}
                for skill in skills
            ],
            "experiences": [{
                "companyName": company_name,
                "title": headline,
                "description": "Build product features",
                "technologies": skills,
            }],
            "education": [{
                "schoolName": school_name,
                "degree": "Bachelor",
                "field": "Informatique",
                "description": "Software projects",
            }],
        },
    }


def mini_dataset() -> dict:
    return {
        "developers": [
            make_developer("react-dev", "Frontend Developer React", ["React", "TypeScript", "CSS"]),
            make_developer("ops-dev", "DevOps Engineer", ["Docker", "Kubernetes", "Linux"], first_name="Bob"),
        ],
        "offers": [{
            "title": "Frontend Developer React",
            "description": "Build React TypeScript interfaces with CSS.",
            "location": "Remote",
            "locationType": "remote",
            "experienceLevel": 2,
        }],
    }


class EvaluateMatchingModelTest(unittest.TestCase):
    def test_parse_methods_accepts_reranker(self) -> None:
        self.assertEqual(["baseline", "reranker"], evaluator.parse_methods("baseline,reranker"))

    def test_baseline_score_does_not_call_weak_relevance(self) -> None:
        dataset = mini_dataset()
        offer = dataset["offers"][0]
        developer = dataset["developers"][0]

        with patch.object(evaluator, "weak_relevance", side_effect=AssertionError("should not be called")):
            score = evaluator.baseline_score(offer, developer)

        self.assertGreater(score, 0.0)

    def test_metrics_are_computed_on_mini_dataset(self) -> None:
        dataset = mini_dataset()
        scores = [[0.9, 0.1]]

        report = evaluator.metrics_for_scores(
            "baseline",
            scores,
            dataset["offers"],
            dataset["developers"],
        )

        self.assertEqual("baseline", report["method"])
        self.assertEqual(1, report["offersEvaluated"])
        self.assertEqual(1.0, report["recall@1"])
        self.assertEqual(1.0, report["mrr"])
        self.assertEqual(1.0, report["ndcg@5"])

    def test_reranker_feature_vector_prefers_matching_candidate(self) -> None:
        dataset = mini_dataset()
        features = evaluator.build_offline_features(dataset["offers"], dataset["developers"])

        matching_vector = evaluator.reranker_feature_vector(
            dataset["offers"][0],
            dataset["developers"][0],
            0.9,
            0.92,
            features["offerKeywords"][0],
            features["developerKeywords"][0],
            features["offerFamilies"][0],
            features["developerFamilies"][0],
            features["candidateTexts"][0],
        )
        off_family_vector = evaluator.reranker_feature_vector(
            dataset["offers"][0],
            dataset["developers"][1],
            0.4,
            0.3,
            features["offerKeywords"][0],
            features["developerKeywords"][1],
            features["offerFamilies"][0],
            features["developerFamilies"][1],
            features["candidateTexts"][1],
        )

        self.assertGreater(matching_vector[2], off_family_vector[2])
        self.assertGreater(matching_vector[3], off_family_vector[3])
        self.assertGreater(matching_vector[5], off_family_vector[5])
        self.assertGreater(matching_vector[14], off_family_vector[14])

    def test_review_pack_selection_is_stable_with_same_seed(self) -> None:
        dataset = mini_dataset()
        method_scores = {
            "baseline": [[0.9, 0.1]],
            "semantic": [[0.1, 0.9]],
            "enriched_proxy": [[0.8, 0.2]],
        }

        first = evaluator.build_review_pack(dataset, method_scores, sample_size=1, seed=42)
        second = evaluator.build_review_pack(dataset, method_scores, sample_size=1, seed=42)

        self.assertEqual(first, second)

    def test_review_pack_is_anonymized(self) -> None:
        dataset = mini_dataset()
        method_scores = {
            "baseline": [[0.9, 0.1]],
            "semantic": [[0.1, 0.9]],
            "enriched_proxy": [[0.8, 0.2]],
        }

        pack = evaluator.build_review_pack(dataset, method_scores, sample_size=1, seed=42)

        for prohibited in [
            "Alice",
            "Martin",
            "react-dev@demo.devspot.local",
            "Secret Corp",
            "Secret School",
            "portfolioUrl",
            "githubUrl",
            "linkedinUrl",
            "companyName",
            "schoolName",
        ]:
            self.assertNotIn(prohibited, pack)
        self.assertIn("Candidat A", pack)
        self.assertIn("Note humaine 1-5", pack)

    def test_evaluate_baseline_only_does_not_load_semantic_model(self) -> None:
        report, scores = evaluator.evaluate(mini_dataset(), methods=["baseline"])

        self.assertIn("baseline", report["methods"])
        self.assertIn("baseline", scores)
        self.assertNotIn("semantic", report["methods"])

    def test_evaluate_uses_explicit_evaluation_labels_when_present(self) -> None:
        dataset = mini_dataset()
        dataset["evaluationLabels"] = {
            "labelSource": "explicit test labels",
            "offers": [{
                "offerIndex": 0,
                "positiveCandidates": [{
                    "candidateId": "ops-dev",
                    "relevance": 3,
                }],
            }],
        }

        report, _scores = evaluator.evaluate(dataset, methods=["baseline"])

        self.assertEqual("explicit test labels", report["labelSource"])
        self.assertEqual(0.0, report["methods"]["baseline"]["recall@1"])
        self.assertEqual(0.5, report["methods"]["baseline"]["mrr"])
        self.assertEqual(0, report["methods"]["baseline"]["sampleOffers"][0]["top5"][0]["weakRelevance"])

    def test_enriched_proxy_caps_off_family_candidate(self) -> None:
        capped = evaluator.apply_family_guardrail(
            0.98,
            {"frontend", "backend", "fullstack"},
            {"qa"},
        )

        self.assertEqual(0.74, capped)

    def test_enriched_proxy_keeps_compatible_developer_family(self) -> None:
        uncapped = evaluator.apply_family_guardrail(
            0.98,
            {"frontend", "backend", "fullstack"},
            {"frontend"},
        )

        self.assertEqual(0.98, uncapped)

    def test_enriched_proxy_caps_qa_headline_for_developer_offer(self) -> None:
        developer = make_developer("qa-dev", "QA Engineer produit web", ["HTML", "CSS", "Testing"])

        capped = evaluator.apply_developer_guardrail(
            0.98,
            {"frontend", "backend", "fullstack"},
            {"frontend", "qa"},
            developer,
        )

        self.assertEqual(0.74, capped)

    def test_specific_keyword_guardrail_caps_partial_fullstack_candidate(self) -> None:
        capped = evaluator.apply_specific_keyword_guardrail(
            0.95,
            {"react", "symfony", "php", "full stack", "frontend", "backend"},
            {"react", "typescript", "frontend"},
            {"frontend", "backend", "fullstack"},
            {"frontend"},
        )

        self.assertEqual(0.82, capped)

    def test_experience_alignment_adjustment_penalizes_large_gap(self) -> None:
        offer = {"experienceLevel": 6}
        developer = make_developer("junior-gap", "Backend Developer PHP", ["PHP", "Symfony", "SQL"])

        self.assertEqual(-0.07, evaluator.experience_alignment_adjustment(offer, developer))

    def test_role_template_match_grade_prefers_matching_fullstack_profile(self) -> None:
        offer = {
            "title": "Developpeur Full Stack React / Symfony",
            "experienceLevel": 4,
        }
        matching_developer = make_developer(
            "fullstack-dev",
            "Full Stack Developer React Symfony",
            ["React", "Symfony", "PHP", "SQL"],
        )
        partial_developer = make_developer(
            "frontend-dev",
            "Frontend Developer React",
            ["React", "TypeScript", "CSS"],
        )

        matching_text = evaluator.normalize_key(evaluator.build_candidate_text(matching_developer))
        partial_text = evaluator.normalize_key(evaluator.build_candidate_text(partial_developer))

        self.assertGreater(
            evaluator.role_template_match_grade(offer, matching_developer, matching_text),
            evaluator.role_template_match_grade(offer, partial_developer, partial_text),
        )

    def test_role_template_tiebreak_score_is_positive_for_matching_rule(self) -> None:
        offer = {
            "title": "Developpeur Android Kotlin",
            "experienceLevel": 3,
        }
        developer = make_developer(
            "android-dev",
            "Developpeur Android Kotlin",
            ["Android", "Kotlin", "Mobile"],
        )
        candidate_text = evaluator.normalize_key(evaluator.build_candidate_text(developer))

        self.assertGreater(evaluator.role_template_tiebreak_score(offer, developer, candidate_text), 0.0)

    def test_summarize_cleaning_impact_reports_junior_score_delta(self) -> None:
        developers = [
            make_developer("junior-dev", "Frontend Developer React", ["React", "TypeScript"]),
            make_developer(
                "senior-dev",
                "Backend Developer PHP",
                ["PHP", "Symfony"],
                first_name="Bob",
                last_name="Senior",
            ),
        ]
        developers[1]["profile"]["experienceLevel"] = "senior"
        developers[1]["profile"]["yearsExperience"] = 7

        summary = evaluator.summarize_cleaning_impact(
            cleaned_scores=[[0.9, 0.6], [0.85, 0.55]],
            legacy_scores=[[0.8, 0.65], [0.75, 0.5]],
            developers=developers,
        )

        self.assertEqual(0.1, summary["score_delta"]["junior_avg_delta"])
        self.assertEqual(0.0, summary["score_delta"]["non_junior_avg_delta"])
        self.assertEqual(0.05, summary["score_delta"]["non_junior_avg_abs_delta"])
        self.assertEqual(0.875, summary["cleaned"]["junior_avg_score"])
        self.assertEqual(0.775, summary["legacy_raw"]["junior_avg_score"])

    def test_apply_counterfactual_variant_changes_location_school_and_origin(self) -> None:
        dataset = mini_dataset()

        location_variant = evaluator.apply_counterfactual_variant(dataset, "location")
        school_variant = evaluator.apply_counterfactual_variant(dataset, "school")
        origin_variant = evaluator.apply_counterfactual_variant(dataset, "apparent_origin")

        self.assertEqual("onsite", location_variant["developers"][0]["profile"]["locationType"])
        self.assertEqual(
            "Counterfactual School",
            school_variant["developers"][0]["profile"]["education"][0]["schoolName"],
        )
        self.assertEqual("Aminata", origin_variant["developers"][0]["profile"]["firstName"])
        self.assertEqual("Diallo", origin_variant["developers"][0]["profile"]["lastName"])
        self.assertEqual("remote", dataset["developers"][0]["profile"]["locationType"])
        self.assertEqual("Alice", dataset["developers"][0]["profile"]["firstName"])

    def test_apparent_origin_counterfactual_has_zero_score_shift_when_names_are_unused(self) -> None:
        developers = [
            make_developer("junior-dev", "Frontend Developer React", ["React"]),
            make_developer("senior-dev", "Backend Developer PHP", ["PHP"], first_name="Bob", last_name="Senior"),
        ]
        developers[1]["profile"]["experienceLevel"] = "senior"
        developers[1]["profile"]["yearsExperience"] = 8

        summary = evaluator.summarize_counterfactual_impact(
            original_scores=[[0.9, 0.4], [0.7, 0.8]],
            counterfactual_scores=[[0.9, 0.4], [0.7, 0.8]],
            developers=developers,
        )

        self.assertEqual(0.0, summary["changed_pairs_rate"])
        self.assertEqual(0.0, summary["top1_changed_rate"])
        self.assertEqual(1.0, summary["avg_top5_overlap"])
        self.assertEqual(0.0, summary["score_delta"]["junior_avg_abs_delta"])
        self.assertEqual(0.0, summary["score_delta"]["non_junior_avg_abs_delta"])

    def test_summarize_counterfactual_impact_reports_ranking_and_score_shift(self) -> None:
        developers = [
            make_developer("junior-dev", "Frontend Developer React", ["React"]),
            make_developer("senior-dev", "Backend Developer PHP", ["PHP"], first_name="Bob", last_name="Senior"),
        ]
        developers[1]["profile"]["experienceLevel"] = "senior"
        developers[1]["profile"]["yearsExperience"] = 8

        summary = evaluator.summarize_counterfactual_impact(
            original_scores=[[0.9, 0.4], [0.7, 0.8]],
            counterfactual_scores=[[0.8, 0.4], [0.6, 0.85]],
            developers=developers,
        )

        self.assertEqual(0.75, summary["changed_pairs_rate"])
        self.assertEqual(0.0, summary["top1_changed_rate"])
        self.assertEqual(1.0, summary["avg_top5_overlap"])
        self.assertEqual(-0.1, summary["score_delta"]["junior_avg_delta"])
        self.assertEqual(0.025, summary["score_delta"]["non_junior_avg_delta"])


if __name__ == "__main__":
    unittest.main()
