# DevSpot

DevSpot est une application Symfony de mise en relation entre recruteurs et développeurs, avec messagerie temps réel, gestion de profils/offres, matching enrichi par IA et outillage CI/CD.

## Stack

- Symfony 7.4 / PHP 8.3 en Docker et en CI (`composer.json` reste compatible `>=8.2`)
- PostgreSQL
- Tailwind CSS + AssetMapper
- Mercure pour le temps réel
- PHPUnit / ParaTest / Panther
- PHPStan / PHP CS Fixer
- Service ML Python dans `ml/` pour le matching sémantique

## Tutoriel d'installation locale

### Lien GitHub

```text
https://github.com/MehdiTrari/DevSpot.git
```

### Site de production

```text
https://devspot.software/
```

### Prérequis

- Docker + Docker Compose
- PHP 8.2+ si tu exécutes Composer ou `bin/console` sur l'hôte
- Composer
- Symfony CLI (optionnel)

### 1. Récupérer le projet

```bash
git clone https://github.com/MehdiTrari/DevSpot.git
cd DevSpot
```

### 2. Installer les dépendances PHP

```bash
composer install
```

Cette étape est utile si tu exécutes aussi les commandes Composer, PHPUnit ou PHPStan sur l'hôte. Si tu travailles uniquement via Docker, le conteneur `app` peut installer ses dépendances au démarrage.

### 3. Lancer l'environnement Docker

```bash
docker compose up -d --build
```

Cette commande démarre l'application Symfony, Caddy, PostgreSQL, Mercure, Mailpit, le service ML, la documentation MkDocs et Adminer.

Vérifier que les conteneurs sont bien démarrés :

```bash
docker compose ps
```

### 4. Préparer la base de données

Vérifier l'état des migrations :

```bash
docker compose exec app php bin/console doctrine:migrations:status
```

Appliquer les migrations :

```bash
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. Accéder à l'application

Une fois la stack démarrée, l'application est disponible ici :

- `http://devspot.localhost`
- `http://localhost:8000` en URL de compatibilité

Les autres services locaux utiles :

- Documentation : `http://localhost:8002/DevSpot/`
- Adminer : `http://localhost:8081`
- Mailpit : `http://localhost:8025`
- Service ML : `http://localhost:8001`
- Santé du service ML : `http://localhost:8001/health`

### 6. Commandes utiles

Voir les conteneurs :

```bash
docker compose ps
```

Suivre les logs :

```bash
docker compose logs -f
```

Arrêter la stack :

```bash
docker compose down
```

Vider le cache Symfony :

```bash
docker compose exec app php bin/console cache:clear
```

Lancer Tailwind en watch si tu modifies le style :

```bash
docker compose exec app php bin/console tailwind:build --watch
```

Réinitialiser le frontend local si le rendu est cassé ou incohérent :

```bash
composer reset:front
```

Cette commande purge les assets compilés locaux puis relance `cache:clear`, `cache:warmup`, `tailwind:build` et `asset-map:compile`.

## Temps réel Mercure

Démarrer uniquement Mercure :

```bash
docker compose up -d mercure
```

Vérifier son état :

```bash
docker compose ps mercure
```

Suivre les logs :

```bash
docker compose logs -f mercure
```

Arrêter Mercure :

```bash
docker compose stop mercure
```

## Qualité et tests

### Qualité statique

```bash
composer lint:cs
composer lint:cs:fix
composer lint:stan
```

### Tests applicatifs rapides

```bash
composer test
composer test:parallel
```

### Couverture de code

```bash
composer test:coverage
```

Cette commande nécessite un driver de coverage actif, typiquement `Xdebug`.

### Tests E2E

Le projet utilise Panther pour les tests end-to-end.

Installation locale des drivers navigateur :

```bash
vendor/bin/bdi detect drivers
```

Exécution :

```bash
php bin/console tailwind:build
php bin/console asset-map:compile
composer test:e2e
```

Debug visuel :

```bash
composer test:e2e:debug
```

## Accès locaux

- Application : http://devspot.localhost
- Application compatibilité : http://localhost:8000
- Mercure public : http://mercure.devspot.localhost/.well-known/mercure
- Mercure public compatibilité : http://localhost:3000/.well-known/mercure
- Service ML : http://localhost:8001
- Santé du service ML : http://localhost:8001/health
- Documentation MkDocs : http://localhost:8002/DevSpot/ (`http://localhost:8002` redirige vers cette URL)
- Adminer : http://localhost:8081
- Mailpit : http://localhost:8025
- SMTP Mailpit : `localhost:1025`
- PostgreSQL : `localhost:5433`

Les équivalents en `127.0.0.1` fonctionnent également pour les services exposés par port.

## Architecture locale Docker

- `docker compose up -d --build` lance toute la stack utile au projet en local
- Caddy fronte Symfony sur `devspot.localhost` et Mercure sur `mercure.devspot.localhost`
- Les URLs historiques `localhost:8000` et `localhost:3000` restent disponibles pour compatibilité
- Symfony communique avec PostgreSQL, Mercure, Mailpit et le service ML via le réseau Docker
- Le service `docs` est conservé dans l'override local
- La cible de production doit garder la même séparation logique, mais avec une exposition réseau plus stricte et un reverse proxy dédié

## Base de production Docker

Une base de stack de production est fournie avec :

- `compose.prod.yaml`
- `docker/caddy/Caddyfile.prod`
- `docker/symfony/Dockerfile.prod`
- `.env.prod.example`

Exemple de démarrage :

```bash
cp .env.prod.example .env.prod
docker compose -f compose.prod.yaml --env-file .env.prod up -d --build
```

Cette stack de production expose uniquement le reverse proxy en frontal. PostgreSQL et le service ML restent sur le réseau interne.

Pour automatiser un déploiement sur VPS avec votre domaine, voir aussi :

- `ansible/playbooks/bootstrap.yml`
- `ansible/playbooks/deploy.yml`
- [docs/ops/runbook-vps-namecom.md](docs/ops/runbook-vps-namecom.md)

## Documentation du projet

Pour une lecture rapide et structurée de la documentation publiée, commencer par [docs/index.md](docs/index.md).

Les documents de référence actuellement conservés dans le dépôt sont :

- [docs/matching-ia-documentation.md](docs/matching-ia-documentation.md) — documentation technique du pipeline de matching IA
- [docs/camembert-algorithme-technique.md](docs/camembert-algorithme-technique.md) — détail de l'algorithme vectoriel CamemBERT, projection, cosinus et scoring
- [docs/analyse-methodologie-matching.md](docs/analyse-methodologie-matching.md) — comparaison entre la méthodologie cible, l'implémentation réelle et les écarts restants
- [docs/matching-improvement-roadmap.md](docs/matching-improvement-roadmap.md) — feuille de route incrémentale pour le nettoyage, l'optimisation, la pertinence et la fairness
- [docs/matching-human-review-summary.md](docs/matching-human-review-summary.md) — synthèse de la revue humaine Phase 3 sur la pertinence du matching
- [docs/ci-cd-github-actions.md](docs/ci-cd-github-actions.md) — workflows GitHub Actions actuels pour la documentation, la qualité, les tests et la couverture
- [docs/problème-performance.md](docs/problème-performance.md) — optimisation performance réalisée sur l'application
- [docs/junior-skill-inference-implementation.md](docs/junior-skill-inference-implementation.md) — note de conception détaillée sur l'inférence de compétences juniors
- [docs/plan-finetuning-camembert.md](docs/plan-finetuning-camembert.md) — feuille de route de fine-tuning CamemBERT

## Conseils de lecture

### Lecture globale

- Pour lancer le projet et situer le périmètre : commencer par ce `README`
- Pour une vue d'ensemble structurée de la documentation : lire [docs/index.md](docs/index.md)
- Pour comprendre l'architecture locale et la cible d'exploitation : lire [docs/architecture/system-overview.md](docs/architecture/system-overview.md)
- Pour la cible de mise en production : lire [docs/ops/deployment-production.md](docs/ops/deployment-production.md)
- Pour l'automatisation qualité et documentation : lire [docs/ci-cd-github-actions.md](docs/ci-cd-github-actions.md)

### Lecture technique détaillée

- Pour comprendre le matching IA côté produit : lire [docs/matching-ia-documentation.md](docs/matching-ia-documentation.md)
- Pour comprendre l'algorithme CamemBERT en détail : lire [docs/camembert-algorithme-technique.md](docs/camembert-algorithme-technique.md)
- Pour comprendre l'état réel de l'anonymisation et les écarts méthodologiques : lire [docs/analyse-methodologie-matching.md](docs/analyse-methodologie-matching.md)
- Pour suivre les améliorations prévues : lire [docs/matching-improvement-roadmap.md](docs/matching-improvement-roadmap.md)
- Pour lire la synthèse de pertinence humaine : lire [docs/matching-human-review-summary.md](docs/matching-human-review-summary.md)

## Notes

- La base utilisée en local est configurée via `.env.local`.
- Si le schéma est à jour, `doctrine:migrations:status` doit indiquer `Already at latest version`.
- L'affichage de l'accueil dépend des profils publics avec portfolio généré présents en base.
- Le dossier `ml/` contient les briques Python liées au matching sémantique et aux expérimentations NLP.
- La CI GitHub exécute actuellement les jobs `quality`, `phpunit`, `e2e` et `coverage` définis dans `.github/workflows/tests.yml`.
