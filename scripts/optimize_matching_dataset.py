from __future__ import annotations

import argparse
import json
from pathlib import Path

from matching_dataset_tools import clean_dataset, load_dataset, save_dataset


def main() -> int:
    parser = argparse.ArgumentParser(description="Normalize and clean a DevSpot matching dataset JSON file.")
    parser.add_argument("input", help="Path to the raw dataset JSON")
    parser.add_argument("output", help="Path to the cleaned dataset JSON")
    parser.add_argument(
        "--report",
        help="Optional path to a JSON report with the applied changes",
    )
    args = parser.parse_args()

    dataset = load_dataset(args.input)
    cleaned_dataset, report = clean_dataset(dataset)
    save_dataset(args.output, cleaned_dataset)

    report_path = Path(args.report) if args.report else Path(args.output).with_suffix(".report.json")
    report_payload = {
        "input": str(Path(args.input).resolve()),
        "output": str(Path(args.output).resolve()),
        "developers": len(cleaned_dataset.get("developers", [])),
        "offers": len(cleaned_dataset.get("offers", [])),
        "changes": report,
    }
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report_payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    print(json.dumps(report_payload, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
