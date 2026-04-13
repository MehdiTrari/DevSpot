# DevSpot

## Lancer le projet en local

### Prérequis
- PHP 8.2+
- Composer
- Symfony CLI
- Docker + Docker Compose

### Ordre de démarrage
Ouvre plusieurs terminaux à la racine du projet puis exécute les commandes dans cet ordre.

#### 1. Installer les dépendances PHP
```bash
composer install
```

#### 2. Démarrer les services Docker
```bash
docker compose up -d database adminer mailer
```

#### 3. Lancer le serveur Symfony
```bash
symfony serve
```

#### 4. Vider le cache
```bash
php bin/console cache:clear
```

#### 5. Vérifier le statut des migrations
```bash
php bin/console doctrine:migrations:status
```

#### 6. Exécuter les migrations
```bash
php bin/console doctrine:migrations:migrate
```

#### 7. Lancer Tailwind en watch
```bash
php bin/console tailwind:build --watch
```

## Tests E2E

Le mode recommandé par Symfony pour les tests end-to-end est Panther.

### Installation locale du driver navigateur
```bash
vendor/bin/bdi detect drivers
```

### Lancer les tests E2E
```bash
php bin/console tailwind:build
php bin/console asset-map:compile
composer test:e2e
```

### Debug visuel
```bash
composer test:e2e:debug
```

En mode debug, Panther ouvre un vrai navigateur au lieu du mode headless.

## Accès locaux
- Application : http://127.0.0.1:8000
- Adminer : http://127.0.0.1:8081
- Mailpit : http://127.0.0.1:32771
- PostgreSQL : `127.0.0.1:5433`

## Notes
- La base utilisée en local est configurée via `.env.local`.
- Si le schéma est à jour, `doctrine:migrations:status` doit indiquer `Already at latest version`.
- L'affichage de l'accueil dépend des profils publics avec portfolio généré présents en base.
