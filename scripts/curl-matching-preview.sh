#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
PAYLOAD_PATH="${2:-payload.json}"

curl -sS -X POST "${BASE_URL}/api/matching/preview" \
  -H "Content-Type: application/json" \
  --data @"${PAYLOAD_PATH}"
