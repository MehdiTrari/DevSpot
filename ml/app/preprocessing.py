from __future__ import annotations

import re
import unicodedata

_whitespace_re = re.compile(r"\s+")


def normalize_text(text: str) -> str:
    normalized = unicodedata.normalize("NFKC", text or "")
    normalized = normalized.replace("\u00a0", " ")
    normalized = _whitespace_re.sub(" ", normalized)

    return normalized.strip()
