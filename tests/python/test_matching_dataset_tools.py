from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from scripts.matching_dataset_tools import build_candidate_text


class MatchingDatasetToolsTest(unittest.TestCase):
    def test_build_candidate_text_matches_shared_fixture_contract(self) -> None:
        fixture_path = ROOT / "tests" / "Fixtures" / "noisy_candidate_matching_profile.json"
        fixture = json.loads(fixture_path.read_text(encoding="utf-8"))

        candidate_payload = {"profile": fixture["profile"]}

        self.assertEqual(fixture["expectedText"], build_candidate_text(candidate_payload))


if __name__ == "__main__":
    unittest.main()
