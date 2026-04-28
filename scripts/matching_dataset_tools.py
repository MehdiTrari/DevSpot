from __future__ import annotations

import html
import json
import re
import unicodedata
from collections import Counter, defaultdict
from copy import deepcopy
from pathlib import Path
from typing import Any

TEXT_REPLACEMENTS: list[tuple[re.Pattern[str], str, str]] = [
    (re.compile(r"\bPostgrela base de donnees\b", re.IGNORECASE), "PostgreSQL", "text_fix_postgresql"),
    (re.compile(r"\bMyla base de donnees\b", re.IGNORECASE), "MySQL", "text_fix_mysql"),
    (re.compile(r"\bla base de donnees\b", re.IGNORECASE), "SQL", "text_fix_sql_phrase"),
    (re.compile(r"\ble serveur\b", re.IGNORECASE), "backend", "text_fix_backend"),
    (re.compile(r"\ble front\b", re.IGNORECASE), "frontend", "text_fix_frontend"),
    (re.compile(r"\b[Dd]eveloppeur\s+[Dd]eveloppeur\b"), "Developpeur", "text_fix_duplicate_developer"),
    (re.compile(r"\b[Dd]eveloppeuse\s+[Dd]eveloppeuse\b"), "Developpeuse", "text_fix_duplicate_developer"),
    (re.compile(r"\b[qQ]A Engineer\b"), "QA Engineer", "text_fix_qa_case"),
    (re.compile(r"\b[dD]evOps Engineer\b"), "DevOps Engineer", "text_fix_devops_case"),
]

POSITION_ALIASES = {
    "developpeur symfony": "Symfony Developer",
    "developpeuse symfony": "Symfony Developer",
    "developpeur php": "PHP Developer",
    "developpeuse php": "PHP Developer",
    "developpeur frontend": "Frontend Developer",
    "developpeuse frontend": "Frontend Developer",
    "developpeur front-end": "Frontend Developer",
    "developpeuse front-end": "Frontend Developer",
    "developpeur backend": "Backend Developer",
    "developpeuse backend": "Backend Developer",
    "developpeur back-end": "Backend Developer",
    "developpeuse back-end": "Backend Developer",
    "developpeur node.js": "Node.js Developer",
    "developpeuse node.js": "Node.js Developer",
    "developpeur react": "React Developer",
    "developpeuse react": "React Developer",
    "developpeur vue.js": "Vue.js Developer",
    "developpeuse vue.js": "Vue.js Developer",
    "developpeur full stack": "Full Stack Developer",
    "developpeuse full stack": "Full Stack Developer",
    "developpeur mobile": "Mobile Developer",
    "developpeuse mobile": "Mobile Developer",
    "developpeur android": "Mobile Developer",
    "developpeuse android": "Mobile Developer",
    "developpeur applications android": "Mobile Developer",
    "developpeuse applications android": "Mobile Developer",
    "developpeur python": "Data Engineer",
    "developpeuse python": "Data Engineer",
    "integrateur web moderne": "Frontend Developer",
}

SKILL_ALIASES = {
    "testing": "Test Automation",
    "automation": "Test Automation",
    "rest api": "API Development",
    "api rest": "API Development",
    "gitlab ci": "CI/CD",
    "prometheus": "Observability",
    "postgres": "PostgreSQL",
    "nodejs": "Node.js",
    "vue": "Vue.js",
}

TECHNOLOGY_ALIASES = {
    "testing": "Test Automation",
    "automation": "Test Automation",
    "rest api": "API Development",
    "api rest": "API Development",
    "gitlab ci": "CI/CD",
    "prometheus": "Observability",
    "postgres": "PostgreSQL",
    "nodejs": "Node.js",
    "vue": "Vue.js",
}

CANONICAL_CASE = {
    "php": "PHP",
    "symfony": "Symfony",
    "sql": "SQL",
    "mysql": "MySQL",
    "postgresql": "PostgreSQL",
    "git": "Git",
    "javascript": "JavaScript",
    "typescript": "TypeScript",
    "react": "React",
    "vue.js": "Vue.js",
    "node.js": "Node.js",
    "html": "HTML",
    "css": "CSS",
    "tailwind css": "Tailwind CSS",
    "docker": "Docker",
    "kubernetes": "Kubernetes",
    "redis": "Redis",
    "linux": "Linux",
    "api platform": "API Platform",
    "api development": "API Development",
    "ci/cd": "CI/CD",
    "observability": "Observability",
    "test automation": "Test Automation",
    "testing library": "Testing Library",
    "qa": "QA",
    "figma": "Figma",
    "postman": "Postman",
    "android": "Android",
    "kotlin": "Kotlin",
    "python": "Python",
    "airflow": "Airflow",
}

MATCHING_TEXT_REPLACEMENTS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"\breact(?:\.js|js)?\b", re.IGNORECASE), "React"),
    (re.compile(r"\bvue(?:\.js|js)?\b", re.IGNORECASE), "Vue.js"),
    (re.compile(r"\bnode(?:\.js|js)?\b", re.IGNORECASE), "Node.js"),
    (re.compile(r"\btypescript\b", re.IGNORECASE), "TypeScript"),
    (re.compile(r"\bjavascript\b", re.IGNORECASE), "JavaScript"),
    (re.compile(r"\bpostgres(?:ql)?\b|\bPostgrela base de donnees\b", re.IGNORECASE), "PostgreSQL"),
    (re.compile(r"\bmysql\b|\bMyla base de donnees\b", re.IGNORECASE), "MySQL"),
    (re.compile(r"\bapi\s*platform\b", re.IGNORECASE), "API Platform"),
    (re.compile(r"\b(?:rest api|api rest)\b", re.IGNORECASE), "REST API"),
    (re.compile(r"\bci\s*\/?\s*cd\b", re.IGNORECASE), "CI/CD"),
    (re.compile(r"\bgitlab ci\b", re.IGNORECASE), "CI/CD"),
    (re.compile(r"\bprometheus\b", re.IGNORECASE), "Observability"),
    (re.compile(r"\btailwind css\b", re.IGNORECASE), "Tailwind CSS"),
    (re.compile(r"\btesting library\b", re.IGNORECASE), "Testing Library"),
    (re.compile(r"\bfigma\b", re.IGNORECASE), "Figma"),
    (re.compile(r"\bqa engineer\b|\bqa\b", re.IGNORECASE), "QA"),
    (re.compile(r"\bsymfony\b", re.IGNORECASE), "Symfony"),
    (re.compile(r"\bdocker\b", re.IGNORECASE), "Docker"),
    (re.compile(r"\bkubernetes\b", re.IGNORECASE), "Kubernetes"),
    (re.compile(r"\bphp\b", re.IGNORECASE), "PHP"),
    (re.compile(r"\bsql\b", re.IGNORECASE), "SQL"),
    (re.compile(r"\bhtml\b", re.IGNORECASE), "HTML"),
    (re.compile(r"\bcss\b", re.IGNORECASE), "CSS"),
    (re.compile(r"\betl\b", re.IGNORECASE), "ETL"),
    (re.compile(r"\bfull-stack\b", re.IGNORECASE), "full stack"),
    (re.compile(r"\bdevops\b", re.IGNORECASE), "DevOps"),
    (re.compile(r"\ble front\b|\bfront end\b", re.IGNORECASE), "frontend"),
    (re.compile(r"\ble backend\b|\bback end\b", re.IGNORECASE), "backend"),
]

SOFT_SKILL_KEYS = {
    "communication",
    "teamwork",
    "problem solving",
    "leadership",
    "adaptability",
    "time management",
    "critical thinking",
    "creativity",
    "curiosity",
}

KEYWORD_PATTERNS = {
    "php": re.compile(r"\bphp\b"),
    "symfony": re.compile(r"\bsymfony|api platform\b"),
    "react": re.compile(r"\breact\b"),
    "vue.js": re.compile(r"\bvue(?:\.js)?\b"),
    "node.js": re.compile(r"\bnode(?:\.js)?|express|nestjs\b"),
    "javascript": re.compile(r"\bjavascript\b"),
    "typescript": re.compile(r"\btypescript\b"),
    "html": re.compile(r"\bhtml\b"),
    "css": re.compile(r"\bcss\b"),
    "tailwind css": re.compile(r"\btailwind\b"),
    "sql": re.compile(r"\bsql\b"),
    "mysql": re.compile(r"\bmysql\b"),
    "postgresql": re.compile(r"\bpostgres(?:ql)?\b"),
    "docker": re.compile(r"\bdocker\b"),
    "kubernetes": re.compile(r"\bkubernetes|k8s\b"),
    "redis": re.compile(r"\bredis\b"),
    "linux": re.compile(r"\blinux\b"),
    "ci/cd": re.compile(r"\bci/cd|pipeline|gitlab ci|github actions|delivery\b"),
    "observability": re.compile(r"\bobservabilite|observability|prometheus|monitoring|fiabilite|sre\b"),
    "test automation": re.compile(r"\btest automation|automatisation|testing|tests? automatiques|selenium|cypress|playwright\b"),
    "qa": re.compile(r"\bqa|qualite\b"),
    "api development": re.compile(r"\bapi(?:s)?|rest|graphql|microservice\b"),
    "postman": re.compile(r"\bpostman\b"),
    "figma": re.compile(r"\bfigma\b"),
    "android": re.compile(r"\bandroid\b"),
    "kotlin": re.compile(r"\bkotlin\b"),
    "python": re.compile(r"\bpython\b"),
    "airflow": re.compile(r"\bairflow\b"),
    "full stack": re.compile(r"\bfull stack\b"),
    "frontend": re.compile(r"\bfrontend|front-end|design system|ui|ux\b"),
    "backend": re.compile(r"\bbackend|back-end\b"),
    "devops": re.compile(r"\bdevops|cloud|platform engineer|infra|infrastructure\b"),
    "mobile": re.compile(r"\bmobile\b"),
    "data": re.compile(r"\bdata engineer|data scientist|machine learning|etl\b"),
}

ROLE_FAMILY_KEYWORDS = {
    "backend": {"php", "symfony", "sql", "mysql", "postgresql", "api development", "backend"},
    "frontend": {"react", "vue.js", "javascript", "typescript", "html", "css", "tailwind css", "frontend"},
    "fullstack": {"full stack", "backend", "frontend"},
    "node": {"node.js", "javascript", "typescript"},
    "devops": {"devops", "docker", "kubernetes", "redis", "linux", "ci/cd", "observability"},
    "qa": {"qa", "test automation", "postman", "testing library"},
    "mobile": {"mobile", "android", "kotlin"},
    "data": {"data", "python", "airflow"},
}

FAMILY_REQUIRED_KEYWORDS = {
    "backend": {"php", "symfony", "sql", "mysql", "postgresql", "api development"},
    "frontend": {"react", "vue.js", "javascript", "typescript", "html", "css", "tailwind css"},
    "node": {"node.js", "javascript", "typescript", "api development", "postgresql"},
    "devops": {"docker", "kubernetes", "redis", "linux", "ci/cd", "observability"},
    "qa": {"qa", "test automation", "postman", "testing library"},
    "mobile": {"android", "kotlin", "mobile"},
    "data": {"python", "airflow", "sql", "postgresql"},
}

SENIORITY_ORDER = ["junior", "mid", "senior", "lead"]

SENIORITY_TARGETS_BY_FAMILY = {
    "backend": {"junior": 0.20, "mid": 0.50, "senior": 0.24, "lead": 0.06},
    "frontend": {"junior": 0.22, "mid": 0.50, "senior": 0.22, "lead": 0.06},
    "fullstack": {"junior": 0.18, "mid": 0.50, "senior": 0.26, "lead": 0.06},
    "node": {"junior": 0.18, "mid": 0.50, "senior": 0.26, "lead": 0.06},
    "devops": {"junior": 0.10, "mid": 0.45, "senior": 0.35, "lead": 0.10},
    "qa": {"junior": 0.18, "mid": 0.50, "senior": 0.24, "lead": 0.08},
    "mobile": {"junior": 0.16, "mid": 0.50, "senior": 0.28, "lead": 0.06},
    "data": {"junior": 0.12, "mid": 0.46, "senior": 0.32, "lead": 0.10},
    "default": {"junior": 0.20, "mid": 0.50, "senior": 0.24, "lead": 0.06},
}

SENIORITY_YEAR_RANGES = {
    "junior": (0, 2),
    "mid": (3, 5),
    "senior": (6, 9),
    "lead": (10, 14),
}

SENIORITY_BIO_LABELS = {
    "junior": "Profil junior",
    "mid": "Profil confirmé",
    "senior": "Profil senior",
    "lead": "Profil lead",
}

OFFER_EXPERIENCE_BUCKETS = ["0-2", "3-4", "5-6", "7+"]

OFFER_TARGETS_BY_FAMILY = {
    "backend": {"0-2": 0.18, "3-4": 0.44, "5-6": 0.26, "7+": 0.12},
    "frontend": {"0-2": 0.20, "3-4": 0.46, "5-6": 0.24, "7+": 0.10},
    "fullstack": {"0-2": 0.16, "3-4": 0.46, "5-6": 0.26, "7+": 0.12},
    "node": {"0-2": 0.14, "3-4": 0.46, "5-6": 0.28, "7+": 0.12},
    "devops": {"0-2": 0.08, "3-4": 0.34, "5-6": 0.34, "7+": 0.24},
    "qa": {"0-2": 0.16, "3-4": 0.44, "5-6": 0.28, "7+": 0.12},
    "mobile": {"0-2": 0.14, "3-4": 0.44, "5-6": 0.30, "7+": 0.12},
    "data": {"0-2": 0.08, "3-4": 0.34, "5-6": 0.34, "7+": 0.24},
    "default": {"0-2": 0.15, "3-4": 0.45, "5-6": 0.28, "7+": 0.12},
}

OFFER_BUCKET_RANGES = {
    "0-2": (0, 2),
    "3-4": (3, 4),
    "5-6": (5, 6),
    "7+": (7, 9),
}


def normalize_key(value: str) -> str:
    ascii_value = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    ascii_value = ascii_value.lower()
    ascii_value = re.sub(r"[^a-z0-9.+/#\s-]", " ", ascii_value)
    return re.sub(r"\s+", " ", ascii_value).strip()


def collapse_spaces(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def apply_text_fixes(value: str, report: Counter[str]) -> str:
    cleaned = value
    for pattern, replacement, report_key in TEXT_REPLACEMENTS:
        updated, count = pattern.subn(replacement, cleaned)
        if count:
            report[report_key] += count
            cleaned = updated
    cleaned = collapse_spaces(cleaned)
    return cleaned


def canonical_case(value: str) -> str:
    normalized = normalize_key(value)
    return CANONICAL_CASE.get(normalized, collapse_spaces(value))


def canonicalize_position(value: str, report: Counter[str]) -> str:
    normalized = normalize_key(value)
    canonical = POSITION_ALIASES.get(normalized)
    if canonical is not None and canonical != value:
        report["position_normalized"] += 1
        return canonical
    return collapse_spaces(value)


def canonicalize_skill(value: str, report: Counter[str]) -> str:
    normalized = normalize_key(value)
    canonical = SKILL_ALIASES.get(normalized)
    if canonical is not None:
        report["skill_normalized"] += 1
        return canonical_case(canonical)
    return canonical_case(value)


def canonicalize_technology(value: str, report: Counter[str]) -> str:
    normalized = normalize_key(value)
    canonical = TECHNOLOGY_ALIASES.get(normalized)
    if canonical is not None:
        report["technology_normalized"] += 1
        return canonical_case(canonical)
    return canonical_case(value)


def dedupe_strings(values: list[str]) -> list[str]:
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        key = normalize_key(value)
        if not key or key in seen:
            continue
        seen.add(key)
        result.append(value)
    return result


def dedupe_matching_strings(values: list[str]) -> list[str]:
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        key = normalize_key(value)
        if not key or key in seen:
            continue
        seen.add(key)
        result.append(collapse_spaces(value))
    return result


def matching_clean_text(value: Any) -> str:
    cleaned = html.unescape(str(value or ""))
    cleaned = re.sub(r"<[^>]+>", " ", cleaned)
    cleaned = re.sub(r"[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}", " ", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\+?[0-9][0-9\s().-]{7,}", " ", cleaned)
    cleaned = re.sub(r"https?://[^\s<>\"']+|www\.[^\s<>\"']+", " ", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\b(?:contact|portfolio|github|linkedin)\b\s*:?", " ", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"(?:\s*-\s*)+", " ", cleaned)

    for pattern, replacement in MATCHING_TEXT_REPLACEMENTS:
        cleaned = pattern.sub(replacement, cleaned)

    return collapse_spaces(cleaned)


def is_soft_skill(value: str) -> bool:
    return normalize_key(value) in SOFT_SKILL_KEYS


def format_skill_descriptor(skill: dict[str, Any]) -> str:
    name = matching_clean_text(skill.get("skill", ""))
    if not name:
        return ""

    details: list[str] = []
    level = collapse_spaces(str(skill.get("level") or ""))
    if level:
        details.append(normalize_key(level))

    years = skill.get("years")
    if isinstance(years, int) and years > 0:
        details.append(f"{years} year" if years == 1 else f"{years} years")

    if not details:
        return name

    return f"{name} ({', '.join(details)})"


def iso_to_period(value: Any) -> str:
    text = collapse_spaces(str(value or ""))
    if len(text) >= 7:
        return text[:7].replace("-", "/")
    return text


def sort_experiences_key(experience: dict[str, Any]) -> tuple[int, str]:
    return (1 if experience.get("isCurrent") else 0, collapse_spaces(str(experience.get("startDate") or "")))


def sort_education_key(education: dict[str, Any]) -> str:
    return collapse_spaces(str(education.get("endDate") or ""))


def filter_non_empty(values: list[str | None]) -> list[str]:
    return [value for value in values if value is not None and collapse_spaces(value)]


def trim_sentence_fragment(value: str) -> str:
    return collapse_spaces(value).rstrip(" .")


def fill_missing_bio(profile: dict[str, Any], report: Counter[str]) -> None:
    bio = collapse_spaces(str(profile.get("bio") or ""))
    if bio:
        profile["bio"] = bio
        return

    headline = collapse_spaces(str(profile.get("headline") or "Profil developpeur"))
    desired = dedupe_strings([canonicalize_position(str(item), report) for item in profile.get("desiredPositions", [])])
    hard_skills = [
        canonicalize_skill(str(item.get("skill", "")), report)
        for item in profile.get("profileSkills", [])
        if normalize_key(str(item.get("skill", ""))) not in {
            "communication",
            "teamwork",
            "problem solving",
            "leadership",
            "adaptability",
            "time management",
            "critical thinking",
            "creativity",
        }
    ]
    hard_skills = dedupe_strings([skill for skill in hard_skills if skill])
    role_part = desired[0] if desired else headline
    skills_part = ", ".join(hard_skills[:3]) if hard_skills else "developpement web"
    profile["bio"] = f"{headline}. Profil oriente {skills_part}. Recherche un poste de {role_part.lower()} dans un contexte produit collaboratif."
    report["bio_filled"] += 1


def dominant_role_family(developer: dict[str, Any]) -> str:
    families = developer_role_families(developer)
    for family in ["fullstack", "devops", "data", "mobile", "qa", "node", "backend", "frontend"]:
        if family in families:
            return family
    return "default"


def allocate_target_counts(total: int, ratios: dict[str, float], ordered_keys: list[str]) -> dict[str, int]:
    counts = {key: int(total * ratios[key]) for key in ordered_keys}
    remaining = total - sum(counts.values())
    remainders = sorted(
        ordered_keys,
        key=lambda key: ((total * ratios[key]) - counts[key], -ordered_keys.index(key)),
        reverse=True,
    )
    for index in range(remaining):
        counts[remainders[index % len(remainders)]] += 1

    if "lead" in counts and total >= 10 and counts["lead"] == 0:
        donor_levels = ["mid", "senior", "junior"]
        for donor in donor_levels:
            if counts[donor] > 1:
                counts[donor] -= 1
                counts["lead"] += 1
                break

    if "senior" in counts and total >= 6 and counts["senior"] == 0:
        donor_levels = ["mid", "junior"]
        for donor in donor_levels:
            if counts[donor] > 1:
                counts[donor] -= 1
                counts["senior"] += 1
                break

    return counts


def experience_signal(profile: dict[str, Any]) -> float:
    years = int(profile.get("yearsExperience") or 0)
    level = normalize_key(str(profile.get("experienceLevel") or ""))
    level_weight = {
        "junior": 0.0,
        "mid": 1.2,
        "senior": 2.6,
        "lead": 4.0,
    }.get(level, 0.6)
    experiences = profile.get("experiences", [])
    current_bonus = 0.4 if any(experience.get("isCurrent") for experience in experiences) else 0.0
    skill_years = [int(skill.get("years") or 0) for skill in profile.get("profileSkills", [])]
    average_skill_years = (sum(skill_years) / len(skill_years)) if skill_years else 0.0
    return years + level_weight + (len(experiences) * 0.35) + (average_skill_years * 0.2) + current_bonus


def years_for_level(existing_years: int, level: str, bucket_index: int, bucket_size: int) -> int:
    low, high = SENIORITY_YEAR_RANGES[level]
    if low <= existing_years <= high:
        return existing_years
    if bucket_size <= 1:
        return (low + high) // 2
    step = (high - low) / max(1, bucket_size - 1)
    return int(round(low + (bucket_index * step)))


def rebalance_profile_skills(profile: dict[str, Any], years_experience: int, experience_level: str, report: Counter[str]) -> None:
    max_level_rank = {
        "junior": 2,
        "mid": 3,
        "senior": 3,
        "lead": 3,
    }[experience_level]
    level_to_rank = {"beginner": 1, "intermediate": 2, "advanced": 3}
    rank_to_level = {1: "beginner", 2: "intermediate", 3: "advanced"}

    for skill in profile.get("profileSkills", []):
        current_years = int(skill.get("years") or 0)
        adjusted_years = max(0, min(years_experience, current_years if current_years > 0 else max(1, min(years_experience, 2))))
        if skill.get("years") != adjusted_years:
            skill["years"] = adjusted_years
            report["skill_years_rebalanced"] += 1

        inferred_rank = 1
        if adjusted_years >= 4:
            inferred_rank = 3
        elif adjusted_years >= 2:
            inferred_rank = 2

        current_rank = level_to_rank.get(normalize_key(str(skill.get("level") or "")), inferred_rank)
        target_rank = min(max_level_rank, max(inferred_rank, min(current_rank, max_level_rank)))
        target_level = rank_to_level[target_rank]
        if skill.get("level") != target_level:
            skill["level"] = target_level
            report["skill_level_rebalanced"] += 1


def harmonize_bio_seniority(profile: dict[str, Any], experience_level: str, report: Counter[str]) -> None:
    bio = collapse_spaces(str(profile.get("bio") or ""))
    if not bio:
        return

    updated_bio = re.sub(
        r"\b(?:Profil|Parcours)\s+(?:junior|mid|senior|lead|confirme|confirmé)\b",
        SENIORITY_BIO_LABELS[experience_level],
        bio,
        count=1,
        flags=re.IGNORECASE,
    )
    if updated_bio != bio:
        profile["bio"] = updated_bio
        report["bio_seniority_rebalanced"] += 1


def rebalance_developer_seniority(cleaned: dict[str, Any], report: Counter[str]) -> None:
    developers = cleaned.get("developers", [])
    grouped_indexes: dict[str, list[int]] = defaultdict(list)

    for index, developer in enumerate(developers):
        grouped_indexes[dominant_role_family(developer)].append(index)

    final_counts: Counter[str] = Counter()
    for family, indexes in grouped_indexes.items():
        ratios = SENIORITY_TARGETS_BY_FAMILY.get(family, SENIORITY_TARGETS_BY_FAMILY["default"])
        target_counts = allocate_target_counts(len(indexes), ratios, SENIORITY_ORDER)
        ordered_indexes = sorted(
            indexes,
            key=lambda idx: experience_signal(developers[idx].get("profile", {})),
        )

        cursor = 0
        for level in SENIORITY_ORDER:
            count = target_counts[level]
            bucket_indexes = ordered_indexes[cursor:cursor + count]
            cursor += count

            for bucket_index, developer_index in enumerate(bucket_indexes):
                profile = developers[developer_index].get("profile", {})
                previous_level = normalize_key(str(profile.get("experienceLevel") or ""))
                previous_years = int(profile.get("yearsExperience") or 0)
                target_years = years_for_level(previous_years, level, bucket_index, max(1, count))

                if previous_level != level:
                    report["developer_experience_level_rebalanced"] += 1
                if previous_years != target_years:
                    report["developer_years_experience_rebalanced"] += 1

                profile["experienceLevel"] = level
                profile["yearsExperience"] = target_years
                harmonize_bio_seniority(profile, level, report)
                rebalance_profile_skills(profile, target_years, level, report)
                final_counts[level] += 1

    for level, count in final_counts.items():
        report[f"final_profiles_{level}"] = count


def dominant_offer_family(offer: dict[str, Any]) -> str:
    families = offer_role_families(offer)
    for family in ["fullstack", "devops", "data", "mobile", "qa", "node", "backend", "frontend"]:
        if family in families:
            return family
    return "default"


def offer_experience_signal(offer: dict[str, Any]) -> float:
    years = int(offer.get("experienceLevel") or 0)
    title = normalize_key(str(offer.get("title") or ""))
    description = normalize_key(str(offer.get("description") or ""))
    lead_markers = sum(marker in title or marker in description for marker in ["lead", "senior", "architect", "staff", "principal", "expert"])
    scope_markers = sum(marker in description for marker in ["ownership", "architecture", "mentoring", "scalability", "platform", "strategy"])
    return years + (lead_markers * 1.5) + (scope_markers * 0.4)


def years_for_offer_bucket(existing_years: int, bucket: str, bucket_index: int, bucket_size: int) -> int:
    low, high = OFFER_BUCKET_RANGES[bucket]
    if low <= existing_years <= high:
        return existing_years
    if bucket_size <= 1:
        return (low + high) // 2
    step = (high - low) / max(1, bucket_size - 1)
    return int(round(low + (bucket_index * step)))


def rebalance_offer_experience(cleaned: dict[str, Any], report: Counter[str]) -> None:
    offers = cleaned.get("offers", [])
    grouped_indexes: dict[str, list[int]] = defaultdict(list)

    for index, offer in enumerate(offers):
        grouped_indexes[dominant_offer_family(offer)].append(index)

    final_counts: Counter[str] = Counter()
    for family, indexes in grouped_indexes.items():
        ratios = OFFER_TARGETS_BY_FAMILY.get(family, OFFER_TARGETS_BY_FAMILY["default"])
        target_counts = allocate_target_counts(len(indexes), ratios, OFFER_EXPERIENCE_BUCKETS)
        ordered_indexes = sorted(indexes, key=lambda idx: offer_experience_signal(offers[idx]))

        cursor = 0
        for bucket in OFFER_EXPERIENCE_BUCKETS:
            count = target_counts[bucket]
            bucket_indexes = ordered_indexes[cursor:cursor + count]
            cursor += count

            for bucket_index, offer_index in enumerate(bucket_indexes):
                offer = offers[offer_index]
                previous_years = int(offer.get("experienceLevel") or 0)
                target_years = years_for_offer_bucket(previous_years, bucket, bucket_index, max(1, count))
                if previous_years != target_years:
                    report["offer_experience_rebalanced"] += 1
                offer["experienceLevel"] = target_years
                final_counts[bucket] += 1

    for bucket, count in final_counts.items():
        report[f"final_offers_{bucket}"] = count


def clean_dataset(dataset: dict[str, Any]) -> tuple[dict[str, Any], dict[str, int]]:
    cleaned = deepcopy(dataset)
    report: Counter[str] = Counter()

    recruiter = cleaned.get("recruiter", {})
    recruiter_profile = recruiter.get("profile", {})
    for key in ["firstName", "lastName", "jobTitle", "workEmail", "phone"]:
        if isinstance(recruiter_profile.get(key), str):
            recruiter_profile[key] = apply_text_fixes(recruiter_profile[key], report)

    for developer in cleaned.get("developers", []):
        profile = developer.get("profile", {})
        for key in ["firstName", "lastName", "headline", "bio", "city", "country", "slug", "portfolioUrl", "githubUrl", "linkedinUrl"]:
            if isinstance(profile.get(key), str):
                profile[key] = apply_text_fixes(profile[key], report)

        profile["desiredPositions"] = dedupe_strings([
            canonicalize_position(apply_text_fixes(str(position), report), report)
            for position in profile.get("desiredPositions", [])
            if collapse_spaces(str(position))
        ])

        for skill in profile.get("profileSkills", []):
            if isinstance(skill.get("skill"), str):
                skill["skill"] = canonicalize_skill(apply_text_fixes(str(skill["skill"]), report), report)
            if isinstance(skill.get("level"), str):
                skill["level"] = normalize_key(skill["level"])
        deduped_skills: list[dict[str, Any]] = []
        seen_skills: set[str] = set()
        for skill in profile.get("profileSkills", []):
            key = normalize_key(str(skill.get("skill", "")))
            if not key or key in seen_skills:
                continue
            seen_skills.add(key)
            deduped_skills.append(skill)
        profile["profileSkills"] = deduped_skills

        for experience in profile.get("experiences", []):
            for key in ["companyName", "title", "description"]:
                if isinstance(experience.get(key), str):
                    experience[key] = apply_text_fixes(experience[key], report)
            experience["technologies"] = dedupe_strings([
                canonicalize_technology(apply_text_fixes(str(technology), report), report)
                for technology in experience.get("technologies", [])
                if collapse_spaces(str(technology))
            ])

        for education in profile.get("education", []):
            for key in ["schoolName", "degree", "field", "description"]:
                if isinstance(education.get(key), str):
                    education[key] = apply_text_fixes(education[key], report)

        fill_missing_bio(profile, report)

    rebalance_developer_seniority(cleaned, report)

    for offer in cleaned.get("offers", []):
        for key in ["title", "description", "location"]:
            if isinstance(offer.get(key), str):
                offer[key] = apply_text_fixes(offer[key], report)

    rebalance_offer_experience(cleaned, report)

    return cleaned, dict(report)


def load_dataset(path: str | Path) -> dict[str, Any]:
    with Path(path).open(encoding="utf-8") as handle:
        return json.load(handle)


def save_dataset(path: str | Path, dataset: dict[str, Any]) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    with target.open("w", encoding="utf-8") as handle:
        json.dump(dataset, handle, ensure_ascii=False, indent=2)
        handle.write("\n")


def build_candidate_text(developer: dict[str, Any]) -> str:
    profile = developer.get("profile", {})
    sections: list[str] = []

    headline = matching_clean_text(profile.get("headline", ""))
    if headline:
        sections.append(f"Headline: {headline}")

    summary_parts: list[str] = []
    bio = matching_clean_text(profile.get("bio", ""))
    if bio:
        summary_parts.append(trim_sentence_fragment(bio))
    experience_level = collapse_spaces(str(profile.get("experienceLevel") or ""))
    if experience_level:
        summary_parts.append(f"Experience level: {normalize_key(experience_level)}")
    years_experience = profile.get("yearsExperience")
    if isinstance(years_experience, int):
        summary_parts.append(f"Years of experience: {years_experience}")
    if summary_parts:
        sections.append("Summary: " + ". ".join(summary_parts))

    target_roles = dedupe_matching_strings([
        matching_clean_text(position)
        for position in profile.get("desiredPositions", [])
    ])
    if target_roles:
        sections.append("Target roles: " + ", ".join(target_roles))

    core_skills = dedupe_matching_strings([
        formatted
        for skill in profile.get("profileSkills", [])
        if not is_soft_skill(str(skill.get("skill", "")))
        for formatted in [format_skill_descriptor(skill)]
        if formatted
    ])
    if core_skills:
        sections.append("Core skills: " + ", ".join(core_skills))

    soft_skills = dedupe_matching_strings([
        formatted
        for skill in profile.get("profileSkills", [])
        if is_soft_skill(str(skill.get("skill", "")))
        for formatted in [format_skill_descriptor(skill)]
        if formatted
    ])
    if soft_skills:
        sections.append("Soft skills: " + ", ".join(soft_skills))

    experience_rows: list[str] = []
    experiences = sorted(profile.get("experiences", []), key=sort_experiences_key, reverse=True)
    for experience in experiences:
        title = matching_clean_text(experience.get("title", ""))
        description = matching_clean_text(experience.get("description", ""))
        technologies = dedupe_matching_strings([
            matching_clean_text(technology)
            for technology in experience.get("technologies", [])
        ])

        parts = filter_non_empty([
            f"Role: {title}" if title else None,
            f"Summary: {trim_sentence_fragment(description)}" if description else None,
            f"Technologies: {', '.join(technologies)}" if technologies else None,
            (
                f"Period: {iso_to_period(experience.get('startDate'))} to present"
                if experience.get("isCurrent") and iso_to_period(experience.get("startDate"))
                else (
                    f"Period: {iso_to_period(experience.get('startDate'))} to {iso_to_period(experience.get('endDate'))}"
                    if iso_to_period(experience.get("startDate")) and iso_to_period(experience.get("endDate"))
                    else (
                        f"Period: {iso_to_period(experience.get('startDate'))}"
                        if iso_to_period(experience.get("startDate"))
                        else None
                    )
                )
            ),
        ])

        if parts:
            experience_rows.append("- " + ". ".join(parts))

    if experience_rows:
        sections.append("Experience:\n" + "\n".join(dedupe_matching_strings(experience_rows)))

    education_rows: list[str] = []
    education_entries = sorted(profile.get("education", []), key=sort_education_key, reverse=True)
    for education in education_entries:
        degree = matching_clean_text(education.get("degree", ""))
        field = matching_clean_text(education.get("field", ""))
        description = matching_clean_text(education.get("description", ""))
        parts = filter_non_empty([
            f"Degree: {degree}" if degree else None,
            f"Field: {field}" if field else None,
            f"Summary: {trim_sentence_fragment(description)}" if description else None,
        ])
        if parts:
            education_rows.append("- " + ". ".join(parts))

    if education_rows:
        sections.append("Education:\n" + "\n".join(dedupe_matching_strings(education_rows)))

    return "\n\n".join(sections)


def build_offer_text(offer: dict[str, Any]) -> str:
    return collapse_spaces(" ".join([
        str(offer.get("title", "")),
        str(offer.get("description", "")),
    ]))


def extract_keywords(text: str) -> set[str]:
    normalized = normalize_key(text)
    return {keyword for keyword, pattern in KEYWORD_PATTERNS.items() if pattern.search(normalized)}


def infer_role_families(text: str, keywords: set[str] | None = None) -> set[str]:
    current_keywords = keywords or extract_keywords(text)
    families = {family for family, family_keywords in ROLE_FAMILY_KEYWORDS.items() if current_keywords & family_keywords}
    if "backend" in families and "frontend" in families:
        families.add("fullstack")
    if "node.js" in current_keywords:
        families.add("node")
    return families


def developer_keywords(developer: dict[str, Any]) -> set[str]:
    profile = developer.get("profile", {})
    keywords = extract_keywords(build_candidate_text(developer))
    for position in profile.get("desiredPositions", []):
        keywords |= extract_keywords(str(position))
    return keywords


def offer_keywords(offer: dict[str, Any]) -> set[str]:
    return extract_keywords(build_offer_text(offer))


def developer_role_families(developer: dict[str, Any]) -> set[str]:
    return infer_role_families(build_candidate_text(developer), developer_keywords(developer))


def offer_role_families(offer: dict[str, Any]) -> set[str]:
    return infer_role_families(build_offer_text(offer), offer_keywords(offer))


def weak_relevance(offer: dict[str, Any], developer: dict[str, Any]) -> int:
    offer_kw = offer_keywords(offer)
    developer_kw = developer_keywords(developer)
    offer_families = offer_role_families(offer)
    developer_families = developer_role_families(developer)

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
