# CI/CD GitHub Actions

Cette page centralise l'état actuel de l'automatisation autour du dépôt DevSpot.

## Objectifs

- fiabiliser les contrôles qualité avant intégration,
- automatiser la génération de la documentation,
- préparer une base pour une future chaîne de déploiement.

## État actuel

- un workflow de documentation publie le site MkDocs,
- la qualité applicative peut s'appuyer sur les commandes de tests et de lint du projet,
- la CD applicative complète reste à formaliser.

## Pistes de structuration

1. exécuter les contrôles qualité sur chaque pull request,
2. construire et publier la documentation automatiquement,
3. distinguer clairement les workflows de CI, de documentation et de déploiement,
4. ajouter des environnements cibles lorsque la stratégie de déploiement sera validée.

## Références utiles

- workflow documentation : `.github/workflows/docs.yml`
- guide du dépôt : [guide.md](guide.md)
- section ops : [ops/index.md](ops/index.md)