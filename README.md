# DevSpot

DevSpot est une application Symfony de mise en relation entre recruteurs et développeurs, avec messagerie temps réel, gestion de profils/offres, matching enrichi par IA et outillage CI/CD.

## Stack

- Symfony 7.4 / PHP 8.3+
- PostgreSQL
- Tailwind CSS + AssetMapper
- Mercure pour le temps réel
- PHPUnit / ParaTest / Panther
- PHPStan / PHP CS Fixer
- Service ML Python dans `ml/` pour le matching sémantique

## Démarrage local

### Prérequis

- PHP 8.3+
- Composer
- Symfony CLI
- Docker + Docker Compose

### Installation

1. Installer les dépendances PHP

```bash
composer install
```

2. Démarrer toute la stack locale

```bash
docker compose up -d --build
```

Cette commande démarre l'application Symfony, Caddy, PostgreSQL, Mercure, Mailpit, le service ML et la documentation MkDocs.

3. Lancer le serveur Symfony

```bash
docker compose ps
```

3. Préparer la base si nécessaire

```bash
php bin/console cache:clear
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate
```

4. Lancer Tailwind en watch si tu modifies le style

```bash
php bin/console tailwind:build --watch
```

### Réinitialiser le frontend local

Si le rendu front est cassé ou incohérent localement :

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
- Documentation MkDocs : http://localhost:8002
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

## Documentation du projet

Les documents de référence actuellement conservés dans le dépôt sont :

- [docs/matching-ia-documentation.md](docs/matching-ia-documentation.md) — documentation technique du pipeline de matching IA
- [docs/camembert-algorithme-technique.md](docs/camembert-algorithme-technique.md) — détail de l'algorithme vectoriel CamemBERT, projection, cosinus et scoring
- [docs/analyse-methodologie-matching.md](docs/analyse-methodologie-matching.md) — comparaison entre la méthodologie cible, l'implémentation réelle et les écarts restants
- [docs/matching-improvement-roadmap.md](docs/matching-improvement-roadmap.md) — feuille de route incrémentale pour le nettoyage, l'optimisation, la pertinence et la fairness
- [docs/ci-cd-github-actions.md](docs/ci-cd-github-actions.md) — CI actuelle et cible CD via GitHub Actions
- [docs/US15-performance.md](docs/US15-performance.md) — optimisation performance réalisée sur l'application
- [docs/junior-skill-inference-implementation.md](docs/junior-skill-inference-implementation.md) — note de conception détaillée sur l'inférence de compétences juniors
- [docs/plan-finetuning-camembert.md](docs/plan-finetuning-camembert.md) — feuille de route de fine-tuning CamemBERT

## Conseils de lecture

- Pour lancer le projet : commencer par ce `README`
- Pour comprendre le matching IA côté produit : lire [docs/matching-ia-documentation.md](docs/matching-ia-documentation.md)
- Pour comprendre l'algorithme CamemBERT en détail : lire [docs/camembert-algorithme-technique.md](docs/camembert-algorithme-technique.md)
- Pour comprendre l'état réel de l'anonymisation et les écarts méthodologiques : lire [docs/analyse-methodologie-matching.md](docs/analyse-methodologie-matching.md)
- Pour suivre les améliorations prévues : lire [docs/matching-improvement-roadmap.md](docs/matching-improvement-roadmap.md)
- Pour la chaîne qualité/tests/CD : lire [docs/ci-cd-github-actions.md](docs/ci-cd-github-actions.md)

## Notes

- La base utilisée en local est configurée via `.env.local`.
- Si le schéma est à jour, `doctrine:migrations:status` doit indiquer `Already at latest version`.
- L'affichage de l'accueil dépend des profils publics avec portfolio généré présents en base.
- Le dossier `ml/` contient les briques Python liées au matching sémantique et aux expérimentations NLP.
