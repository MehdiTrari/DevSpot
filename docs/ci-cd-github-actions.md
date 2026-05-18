# CI/CD GitHub Actions

Cette page centralise l'état actuel de l'automatisation autour du dépôt DevSpot.

## Objectifs

- fiabiliser les contrôles qualité avant intégration,
- automatiser le build de la documentation,
- conserver une base claire pour une future chaîne de déploiement applicative.

## Workflows présents

### Documentation

Le workflow `.github/workflows/docs.yml` :

- se déclenche sur `push` vers `main`, `master` et `develop`, ainsi qu'en `workflow_dispatch`,
- installe Python 3.11 puis `mkdocs-material`,
- exécute `mkdocs build --strict`,
- prépare et publie l'artefact GitHub Pages uniquement sur `main` ou `master` si la variable de dépôt `ENABLE_GITHUB_PAGES` vaut `true`.

Autrement dit, le build de documentation est systématiquement vérifié, mais la publication Pages reste volontairement conditionnelle.

### Qualité et tests applicatifs

Le workflow `.github/workflows/tests.yml` se déclenche sur `push` et `pull_request` et exécute quatre jobs successifs :

1. `quality` : installation Composer, puis `composer lint:cs` et `composer lint:stan`.
2. `phpunit` : exécution des tests applicatifs via `composer test:parallel`.
3. `e2e` : installation des drivers navigateur, build des assets, puis `composer test:e2e` avec upload des captures Panther en artefact si besoin.
4. `coverage` : génération du rapport de couverture via `composer test:coverage`, après succès des jobs précédents.

## Ce qui n'est pas encore automatisé

- la CD applicative complète reste à formaliser,
- il n'y a pas encore de workflow de release, rollback ou déploiement d'infrastructure,
- la publication documentaire dépend encore d'un flag explicite côté GitHub.

## Pistes de structuration

1. conserver la séparation actuelle entre workflow `docs` et workflow `tests`,
2. ajouter plus tard une vraie chaîne de release/déploiement applicatif,
3. documenter la stratégie d'activation de `ENABLE_GITHUB_PAGES`,
4. introduire des environnements cibles lorsque la stratégie de déploiement sera validée.

## Références utiles

- workflow documentation : `.github/workflows/docs.yml`
- workflow qualité/tests : `.github/workflows/tests.yml`
- guide du dépôt : [guide.md](guide.md)
- section ops : [ops/index.md](ops/index.md)