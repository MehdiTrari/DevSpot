from __future__ import annotations

import re

from .preprocessing import normalize_text


_PATTERNS: list[tuple[str, tuple[str, float, str]]] = [
    (r"\b(projet(s)? en groupe|projet(s)? de groupe|travail en equipe|travail d'equipe|binome|collaboration|collaborer avec|equipe produit)\b", ("teamwork", 0.92, "soft")),
    (r"\b(communication|presentation|soutenance|pitch|jury|echanges? avec|relation client)\b", ("communication", 0.84, "soft")),
    (r"\b(resolution de bugs|corriger des bugs|debug|debogage|resoudre des problemes|problemes produit|correction de bugs)\b", ("problem solving", 0.79, "transferable")),
    (r"\b(organis(ation|er)|planifier|coordonner|sprint|suivi|roadmap)\b", ("organization", 0.76, "transferable")),
    (r"\b(autonomie|autonome|independant|independante|ownership|seul sur|en autonomie)\b", ("autonomy", 0.8, "soft")),
    (r"\b(adaptabilite|adaptation|environnement agile|rythme rapide|polyvalent)\b", ("adaptability", 0.72, "soft")),
    (r"\b(mentoring|pilotage|lead|leadership|encadrer|manager)\b", ("leadership", 0.78, "soft")),
    (r"\b(projet universitaire|projet etudiant|projet scolaire|stage|alternance)\b", ("learning agility", 0.64, "transferable")),
    (r"\b(qualite|tests|non regression|fiabilite|fiabilisation|observabilite)\b", ("critical thinking", 0.68, "transferable")),
    (r"\b(design system|ux|accessibilite|interfaces?)\b", ("creativity", 0.66, "soft")),
    (r"\b(delivery|mise en production|ci/cd|gestion de projet|coordination)\b", ("project management", 0.7, "transferable")),
    (r"\b(deadline|priorisation|gestion du temps|organisation du travail)\b", ("time management", 0.71, "transferable")),
]

_DIRECT_TECHNICAL_PATTERNS: list[tuple[str, tuple[list[str], float]]] = [
    (r"\b(admin sys|administration systeme|administration systemes|linux|serveur|systeme|infrastructure|infra|shell|bash)\b", (["linux", "bash"], 0.74)),
    (r"\b(reseau|reseaux|tcp/ip|dns|firewall|vpn|load balancer|proxy)\b", (["networking"], 0.7)),
    (r"\b(docker|conteneurisation|conteneur|container)\b", (["docker"], 0.8)),
    (r"\b(kubernetes|k8s|orchestration)\b", (["kubernetes"], 0.78)),
    (r"\b(ci/cd|ci cd|pipeline(s)?|github actions|gitlab ci|deploiement continu|integration continue|livraison continue)\b", (["ci/cd"], 0.81)),
    (r"\b(observabilite|monitoring|supervision|logs?|tracing|metrics?|alerting|fiabilite|sre)\b", (["observability"], 0.78)),
    (r"\b(symfony|api platform)\b", (["symfony", "php"], 0.84)),
    (r"\b(doctrine|orm|entity manager|repository pattern)\b", (["doctrine"], 0.86)),
    (r"\b(twig|templating|template engine|rendu serveur)\b", (["twig"], 0.84)),
    (r"\b(laravel|eloquent|blade)\b", (["laravel", "php"], 0.82)),
    (r"\b(frontend|front-end|integration responsive|interfaces?|ui|ux)\b", (["html", "css"], 0.66)),
    (r"\b(html5?|css3?)\b", (["html", "css"], 0.78)),
    (r"\b(javascript|ecmascript|spa)\b", (["javascript"], 0.76)),
    (r"\b(typescript)\b", (["typescript", "javascript"], 0.82)),
    (r"\b(react|react\.js|next\.js)\b", (["react", "javascript", "html", "css"], 0.82)),
    (r"\b(vue|vue\.js|nuxt)\b", (["vue.js", "javascript", "html", "css"], 0.8)),
    (r"\b(angular)\b", (["javascript", "html", "css"], 0.78)),
    (r"\b(node\.js|nodejs|express|nestjs)\b", (["node.js", "javascript"], 0.82)),
    (r"\b(api|api rest|api graphql|routes api|endpoint|endpoints|http|json|webservice|webservices|microservice|microservices)\b", (["api development"], 0.72)),
    (r"\b(sql|postgresql|mysql|base de donnees|bases de donnees|modelisation|schema|requete(s)? sql)\b", (["sql"], 0.74)),
    (r"\b(postgresql|postgres)\b", (["postgresql", "sql"], 0.82)),
    (r"\b(mysql|mariadb)\b", (["mysql", "sql"], 0.8)),
    (r"\b(redis|cache)\b", (["redis"], 0.74)),
    (r"\b(automatisation de tests|tests automatiques|qa|non regression|strategie de tests|playwright|cypress|selenium)\b", (["test automation"], 0.78)),
]

_BEGINNER_CONTEXT = re.compile(r"\b(projet universitaire|projet etudiant|projet scolaire|stage|alternance|initiation|decouverte|premiere experience|tp)\b")
_INTERMEDIATE_CONTEXT = re.compile(r"\b(maintenance|mise en production|industrialisation|optimisation|conception|architecture|developpement|integration|pilotage|automatisation|administration|production|scalabilite|fiabilisation)\b")
_ADVANCED_CONTEXT = re.compile(r"\b(senior|lead|expert|experte|architecte|referent technique|ownership technique|mentoring|pilotage technique)\b")

_SYMFONY_PATTERN = re.compile(r"\b(symfony|api platform)\b")
_LARAVEL_PATTERN = re.compile(r"\b(laravel|eloquent|blade)\b")
_PHP_PATTERN = re.compile(r"\b(php)\b")
_FRONTEND_FRAMEWORK_PATTERN = re.compile(r"\b(react|react\.js|next\.js|vue|vue\.js|nuxt|angular)\b")
_NODE_PATTERN = re.compile(r"\b(node\.js|nodejs|express|nestjs)\b")
_API_PATTERN = re.compile(r"\b(api|api rest|api graphql|routes api|endpoint|endpoints|http|json|webservice|webservices|microservice|microservices)\b")
_DATABASE_PATTERN = re.compile(r"\b(sql|postgresql|postgres|mysql|mariadb|base de donnees|bases de donnees|modelisation|schema|entity|entite|entites|repository)\b")
_WEB_APP_PATTERN = re.compile(r"\b(application web|application|site web|site|plateforme|produit|saas|portail|cms|outil interne|webapp)\b")
_ECOMMERCE_PATTERN = re.compile(r"\b(ecommerce|e-commerce|boutique en ligne|catalogue produit|catalogue|checkout|panier|commande(s)?|paiement|payment|marketplace)\b")
_BACK_OFFICE_PATTERN = re.compile(r"\b(back office|back-office|admin|administration|dashboard|tableau de bord|outil interne|interface d'administration)\b")
_DESIGN_SYSTEM_PATTERN = re.compile(r"\b(design system|component library|bibliotheque de composants|composants reutilisables|systeme de design)\b")
_UI_PATTERN = re.compile(r"\b(interface(s)?|ui|ux|responsive|accessibilite|parcours utilisateur|experience utilisateur)\b")
_DEVOPS_PATTERN = re.compile(r"\b(devops|plateforme|platform engineer|sre|delivery|cloud|infra|infrastructure)\b")
_CICD_PATTERN = re.compile(r"\b(ci/cd|ci cd|pipeline(s)?|github actions|gitlab ci|deploiement continu|integration continue|livraison continue)\b")
_OBSERVABILITY_PATTERN = re.compile(r"\b(observabilite|monitoring|supervision|logs?|tracing|metrics?|alerting|fiabilite|fiabilisation)\b")
_QA_PATTERN = re.compile(r"\b(qa|qualite logicielle|qualite|strategie de test|tests? end[- ]to[- ]end|e2e|non regression|validation fonctionnelle)\b")
_AUTOMATION_PATTERN = re.compile(r"\b(automatisation|tests automatiques|playwright|cypress|selenium)\b")


def infer_skills(text: str) -> tuple[list[str], list[str], list[dict[str, str | float]], dict[str, float], str]:
    normalized_text = normalize_text(text).lower()
    confidence: dict[str, float] = {}
    categories: dict[str, str] = {}
    inferred_technical_skills: dict[str, dict[str, str | float]] = {}

    for pattern, (skill, score, category) in _PATTERNS:
        if re.search(pattern, normalized_text):
            confidence[skill] = max(confidence.get(skill, 0.0), score)
            categories[skill] = category

    level, level_bonus = _infer_level(normalized_text)

    for pattern, (skills, base_confidence) in _DIRECT_TECHNICAL_PATTERNS:
        if not re.search(pattern, normalized_text):
            continue

        for skill in skills:
            _record_technical_skill(inferred_technical_skills, skill, base_confidence + level_bonus, level)

    _apply_derived_technical_rules(normalized_text, inferred_technical_skills, level, level_bonus)

    inferred_soft_skills = sorted([skill for skill, category in categories.items() if category == "soft"])
    inferred_transferable_skills = sorted([skill for skill, category in categories.items() if category == "transferable"])
    technical_skill_list = sorted(
        inferred_technical_skills.values(),
        key=lambda item: (-float(item["confidence"]), str(item["skill"])),
    )

    return inferred_soft_skills, inferred_transferable_skills, technical_skill_list, confidence, normalized_text


def _infer_level(normalized_text: str) -> tuple[str, float]:
    has_beginner_context = bool(_BEGINNER_CONTEXT.search(normalized_text))
    has_advanced_context = bool(_ADVANCED_CONTEXT.search(normalized_text))
    has_intermediate_context = bool(_INTERMEDIATE_CONTEXT.search(normalized_text))

    if has_advanced_context and not has_beginner_context:
        return "advanced", 0.1

    if has_intermediate_context and not has_beginner_context:
        return "intermediate", 0.08

    if has_beginner_context:
        return "beginner", 0.0

    return "beginner", 0.03


def _record_technical_skill(inferred_technical_skills: dict[str, dict[str, str | float]], skill: str, score: float, level: str) -> None:
    bounded_score = round(min(0.92, score), 2)
    existing = inferred_technical_skills.get(skill)
    if existing is not None and float(existing["confidence"]) >= bounded_score:
        return

    inferred_technical_skills[skill] = {
        "skill": skill,
        "level": level,
        "confidence": bounded_score,
    }


def _apply_derived_technical_rules(
    normalized_text: str,
    inferred_technical_skills: dict[str, dict[str, str | float]],
    level: str,
    level_bonus: float,
) -> None:
    has_symfony = bool(_SYMFONY_PATTERN.search(normalized_text))
    has_laravel = bool(_LARAVEL_PATTERN.search(normalized_text))
    has_php = bool(_PHP_PATTERN.search(normalized_text))
    has_frontend_framework = bool(_FRONTEND_FRAMEWORK_PATTERN.search(normalized_text))
    has_node = bool(_NODE_PATTERN.search(normalized_text))
    has_api = bool(_API_PATTERN.search(normalized_text))
    has_database = bool(_DATABASE_PATTERN.search(normalized_text))
    has_web_app = bool(_WEB_APP_PATTERN.search(normalized_text))
    has_ecommerce = bool(_ECOMMERCE_PATTERN.search(normalized_text))
    has_back_office = bool(_BACK_OFFICE_PATTERN.search(normalized_text))
    has_design_system = bool(_DESIGN_SYSTEM_PATTERN.search(normalized_text))
    has_ui = bool(_UI_PATTERN.search(normalized_text))
    has_devops = bool(_DEVOPS_PATTERN.search(normalized_text))
    has_ci_cd = bool(_CICD_PATTERN.search(normalized_text))
    has_observability = bool(_OBSERVABILITY_PATTERN.search(normalized_text))
    has_qa = bool(_QA_PATTERN.search(normalized_text))
    has_automation = bool(_AUTOMATION_PATTERN.search(normalized_text))

    if has_symfony:
        if has_api:
            _record_technical_skill(inferred_technical_skills, "api development", 0.82 + level_bonus, level)

        if has_api or has_database or has_web_app or has_ecommerce or has_back_office:
            _record_technical_skill(inferred_technical_skills, "doctrine", 0.74 + level_bonus, level)

        if has_web_app or has_ecommerce or has_back_office or has_ui:
            _record_technical_skill(inferred_technical_skills, "twig", 0.68 + level_bonus, level)

        if has_back_office or has_ecommerce:
            _record_technical_skill(inferred_technical_skills, "back office", 0.72 + level_bonus, level)

    if has_laravel and (has_web_app or has_ecommerce or has_back_office):
        _record_technical_skill(inferred_technical_skills, "back office", 0.68 + level_bonus, level)

    if has_ecommerce:
        _record_technical_skill(inferred_technical_skills, "back office", 0.78 + level_bonus, level)
        _record_technical_skill(inferred_technical_skills, "api development", 0.7 + level_bonus, level)

        if has_php or has_symfony or has_laravel or has_node or has_database:
            _record_technical_skill(inferred_technical_skills, "sql", 0.66 + level_bonus, level)

    if has_back_office:
        _record_technical_skill(inferred_technical_skills, "back office", 0.74 + level_bonus, level)

        if has_symfony or has_laravel or has_php:
            _record_technical_skill(inferred_technical_skills, "twig", 0.66 + level_bonus, level)

    if has_frontend_framework or has_design_system or has_ui:
        _record_technical_skill(inferred_technical_skills, "html", 0.7 + level_bonus, level)
        _record_technical_skill(inferred_technical_skills, "css", 0.7 + level_bonus, level)

    if has_frontend_framework and (has_design_system or has_ui or has_back_office):
        _record_technical_skill(inferred_technical_skills, "component architecture", 0.74 + level_bonus, level)

    if has_design_system:
        _record_technical_skill(inferred_technical_skills, "component architecture", 0.8 + level_bonus, level)

    if has_node and (has_api or has_web_app or has_ecommerce):
        _record_technical_skill(inferred_technical_skills, "api development", 0.76 + level_bonus, level)

    if has_devops or has_ci_cd:
        _record_technical_skill(inferred_technical_skills, "ci/cd", 0.78 + level_bonus, level)

    if has_observability or has_devops:
        _record_technical_skill(inferred_technical_skills, "observability", 0.72 + level_bonus, level)

    if has_qa or has_automation:
        _record_technical_skill(inferred_technical_skills, "test automation", 0.8 + level_bonus, level)
        _record_technical_skill(inferred_technical_skills, "non regression testing", 0.74 + level_bonus, level)

        if has_web_app or has_ui or has_api:
            _record_technical_skill(inferred_technical_skills, "e2e testing", 0.7 + level_bonus, level)