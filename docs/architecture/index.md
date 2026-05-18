# Architecture

Cette section rassemble les documents décrivant la structure technique de DevSpot et sert de point d'entrée vers les choix d'architecture détaillés.

## Lecture globale de l'architecture

- [Système & déploiement](system-overview.md) : vue d'ensemble Docker locale, responsabilités des services et cible de production
- [Déploiement & production](../ops/deployment-production.md) : principes de mise en production, reverse proxy, exposition réseau et priorités d'exploitation
- [CI/CD GitHub Actions](../ci-cd-github-actions.md) : chaîne qualité actuelle et périmètre automatisé

## Lecture technique détaillée

- [Schema BDD](database-schema.md) : inventaire complet des tables, colonnes, contraintes, enums et relations du schema PostgreSQL / Doctrine
- [Matching IA](../matching-ia-documentation.md) : pipeline complet de matching, scoring, anonymisation recruteur, cache, endpoints et intégration ML
- [Algorithme CamemBERT détaillé](../camembert-algorithme-technique.md) : embeddings, projection, similarité cosinus, rescoring et exécution Python / PHP
- [ADR](../adr/index.md) : décisions d'architecture formalisées
