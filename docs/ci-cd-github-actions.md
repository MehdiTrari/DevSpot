# CI/CD GitHub Actions

Cette page centralise l'état actuel de l'automatisation autour du dépôt DevSpot.

## Objectifs

- fiabiliser les contrôles qualité avant intégration,
- automatiser le build de la documentation,
- automatiser le déploiement applicatif sur le serveur de production.

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

### Déploiement production

Le workflow `.github/workflows/deploy.yml` complète désormais la chaîne avec une CD applicative simple :

1. il se déclenche manuellement, ou automatiquement après succès du workflow `Tests` sur `main` ou `master`,
2. il installe Ansible sur le runner GitHub,
3. il reconstruit un inventaire temporaire à partir des secrets GitHub,
4. il injecte le contenu du secret `PROD_ENV_FILE` dans un fichier `.env.prod` temporaire,
5. il exécute `ansible/playbooks/deploy.yml` sur le VPS cible via SSH.

Les secrets attendus côté GitHub sont les suivants :

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PORT`
- `DEPLOY_SSH_KEY`
- `PROD_ENV_FILE`

## Ce qui n'est pas encore automatisé

- il n'y a pas encore de rollback automatisé,
- il n'y a pas encore de déploiement d'infrastructure provider-managed,
- la publication documentaire dépend encore d'un flag explicite côté GitHub.

## Pistes de structuration

1. conserver la séparation actuelle entre workflow `docs` et workflow `tests`,
2. garder Ansible pour un déploiement VPS simple tant que l'infrastructure reste mono-serveur,
3. documenter la stratégie d'activation de `ENABLE_GITHUB_PAGES`,
4. introduire Terraform plus tard si le projet passe sur une infra multi-ressources ou un cloud provider piloté par API.

## Références utiles

- workflow documentation : `.github/workflows/docs.yml`
- workflow qualité/tests : `.github/workflows/tests.yml`
- workflow déploiement : `.github/workflows/deploy.yml`
- guide du dépôt : [guide.md](guide.md)
- section ops : [ops/index.md](ops/index.md)