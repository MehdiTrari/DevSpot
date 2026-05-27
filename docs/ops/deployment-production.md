# Déploiement & production

Cette page décrit la cible de déploiement retenue pour DevSpot et explicite les choix d'architecture liés à la production dans le cadre du projet.

## Pourquoi un reverse proxy ?

Un **reverse proxy** est le point d'entrée HTTP/HTTPS placé devant les services applicatifs.

Concrètement, au lieu d'exposer directement Symfony, Mercure ou d'autres composants sur Internet, on expose un seul service frontal qui :

- reçoit les requêtes entrantes,
- termine le **TLS** (HTTPS),
- route les requêtes vers le bon service interne,
- applique des règles de sécurité et de performance.

## Ce qu'il apporte dans DevSpot

Dans DevSpot, le reverse proxy remplit les fonctions suivantes :

1. **centraliser l'accès public**
   - un point d'entrée unique pour l'application,
   - moins de ports exposés publiquement,
   - configuration réseau plus simple.

2. **gérer HTTPS proprement**
   - certificats TLS au même endroit,
   - redirection HTTP vers HTTPS,
   - renouvellement automatisable selon l'outil choisi.

3. **protéger les services internes**
   - PostgreSQL ne doit jamais être exposé publiquement,
   - le service ML ne doit idéalement pas être accessible directement depuis Internet,
   - l'URL de publication Mercure peut être distincte de son URL interne.

4. **uniformiser le routage**
   - application web sur le domaine principal,
   - éventuellement Mercure sous un sous-domaine ou un chemin dédié,
   - règles cohérentes pour les headers, le cache et la compression.

5. **préparer la montée en charge**
   - limitation de débit,
   - timeouts homogènes,
   - logs d'accès centralisés,
   - possibilité future de répartir le trafic vers plusieurs instances.

## Ce qui est fait en local maintenant

Le dépôt utilise désormais **Caddy aussi dans la stack locale** :

- `devspot.localhost` passe par Caddy avant Symfony,
- `mercure.devspot.localhost` passe par Caddy avant Mercure,
- `localhost:8000` et `localhost:3000` restent disponibles en compatibilité,
- la forme réseau locale se rapproche donc déjà de la production.

L'objectif est de réduire l'écart d'architecture entre développement et production tout en conservant des URLs simples pour l'équipe.

## Choix retenu pour DevSpot

Pour DevSpot, l'approche retenue est la suivante :

- **Caddy** ou **Traefik** si vous voulez une configuration Docker-friendly et simple à maintenir,
- **Nginx** si vous préférez l'approche la plus classique et documentée.

Dans le cadre du projet, **Caddy** constitue un compromis pertinent :

- configuration lisible,
- TLS simple,
- bonne intégration avec des déploiements modestes,
- moins de friction qu'une config Nginx très détaillée.

## Ce qui est implémenté dans le dépôt

La base de production ajoutée dans le dépôt s'appuie sur les fichiers suivants :

- `compose.prod.yaml` : stack de production distincte du local
- `docker/caddy/Caddyfile.prod` : reverse proxy frontal et routage public
- `docker/symfony/Dockerfile.prod` : image Symfony orientée production
- `docker/symfony/entrypoint-prod.sh` : bootstrap minimal de conteneur de production
- `.env.prod.example` : variables d'environnement attendues

Cette implémentation correspond à une **base de déploiement** : elle structure la stack de manière cohérente et prépare la mise en production, sans prétendre couvrir à elle seule l'ensemble de l'outillage d'exploitation réel.

## Topologie cible de production

```text
Internet
   │
   ▼
Reverse proxy (TLS, routage, sécurité)
   │
   ├── Symfony app
   ├── Mercure
   └── éventuellement endpoint public contrôlé

Réseau privé Docker / infra
   ├── PostgreSQL
   ├── Service ML FastAPI
   └── services d'administration non exposés publiquement
```

Dans l'implémentation du dépôt :

- **Caddy** est le seul service exposé publiquement,
- **Symfony** reste derrière Caddy,
- **Mercure** est publié derrière Caddy sur un hôte dédié,
- **PostgreSQL** et **ML** restent uniquement joignables en interne.

## Services à exposer ou non

### À exposer publiquement

- **Symfony** : oui
- **Mercure** : oui, mais via une URL publique contrôlée et sécurisée
- **Reverse proxy** : oui, c'est le point d'entrée externe

### À garder privés

- **PostgreSQL** : jamais public
- **Service ML** : privé autant que possible, consommé par Symfony via réseau interne
- **Adminer** : non en production
- **Mailpit** : non en production
- **MkDocs** : séparé du runtime applicatif, publication statique recommandée

## Principes de configuration retenus

### 1. Images applicatives immuables

En production, nous évitons les conteneurs qui installent des dépendances au démarrage.

Le principe retenu est le suivant :

- image Symfony construite en CI,
- dépendances déjà installées dans l'image,
- variables d'environnement injectées au déploiement,
- pas de `composer install` au démarrage en production.

### 2. Symfony plus proche d'une stack prod

Le mode `php -S` reste acceptable pour le local, mais ne constitue pas la cible retenue pour la production.

Pour la production, nous visons :

- un conteneur applicatif dédié,
- reverse proxy devant (Caddy/Nginx/Traefik),
- workers ou cron séparés si besoin plus tard.

Dans le dépôt, la base prod fournie utilise **Apache + PHP** dans le conteneur Symfony derrière **Caddy**. Ce n'est pas la seule option possible, mais c'est celle que nous retenons à ce stade car elle reste pragmatique, lisible et simple à faire évoluer.

Une évolution ultérieure possible sera de passer à **PHP-FPM** derrière Caddy ou Nginx si nous voulons nous rapprocher d'un modèle encore plus classique de production.

### 3. Migrations explicites

Les migrations doivent être lancées pendant le déploiement, pas automatiquement à chaque redémarrage de conteneur.

Le déroulé visé est le suivant :

- build image,
- déployer,
- lancer `doctrine:migrations:migrate`,
- faire le switch de trafic ou redémarrage contrôlé.

### 4. Secrets et variables d'environnement

Dans la cible de production, les secrets doivent être entièrement sortis du dépôt.

À gérer proprement :

- `APP_SECRET`
- `DATABASE_URL`
- `MERCURE_JWT_SECRET`
- credentials SMTP réels
- clés API éventuelles

Selon l'hébergement :

- variables d'environnement de la plateforme,
- Docker secrets,
- coffre-fort de secrets.

### 5. Persistance et sauvegardes

La base PostgreSQL et les éventuels fichiers utilisateur doivent avoir :

- volumes persistants,
- sauvegardes régulières,
- procédure de restauration testée,
- monitoring de l'espace disque.

### 6. Observabilité minimale

Avant la prod réelle, prévoir au minimum :

- logs applicatifs centralisés,
- logs d'accès reverse proxy,
- healthchecks,
- métriques de base CPU / RAM / disque,
- alerte si base ou service ML indisponible.

## Environnements recommandés

### Local

- stack Docker complète,
- docs incluses pour la soutenance et le travail mémoire,
- outils d'administration visibles.

### Préproduction

- proche de la production,
- sans Adminer/Mailpit publics,
- avec vraies variables d'environnement de déploiement,
- avec vérification manuelle avant release.

### Production

- reverse proxy frontal,
- Symfony + Mercure exposés proprement,
- PostgreSQL et ML privés,
- docs publiées séparément,
- supervision et sauvegardes en place.

## Priorités retenues pour le projet

À ce stade du projet, les priorités structurantes retenues sont les suivantes :

1. garder une stack Docker locale complète et simple à lancer,
2. conserver une **stack de production distincte** du local,
3. garder un **reverse proxy** frontal devant Symfony et Mercure,
4. faire évoluer Symfony vers une image plus prod (`php-fpm` + proxy) si le déploiement est industrialisé davantage,
5. maintenir la documentation hors du runtime applicatif de production,
6. documenter une procédure de release simple et répétable.

## Lecture des choix d'architecture

### Pourquoi une stack prod séparée ?

Parce que le local et la production n'ont pas les mêmes objectifs :

- le **local** privilégie la vitesse d'usage, l'observabilité manuelle et la démonstration,
- la **production** privilégie la stabilité, la sécurité réseau et la maîtrise des surfaces exposées.

### Pourquoi garder Caddy dans les deux mondes ?

Ce choix permet de stabiliser très tôt :

- les responsabilités réseau,
- le point d'entrée HTTP,
- la manière dont Symfony et Mercure sont exposés,
- la documentation d'architecture.

On garde toutefois une séparation nette entre :

- un **Caddy local de développement**, simple et orienté confort,
- un **Caddy de production**, orienté exposition publique, TLS et sécurité.

### Pourquoi ne pas embarquer la doc en production ?

Parce que la documentation n'est pas un composant du runtime métier. Elle doit rester :

- publiée séparément,
- simple à déployer,
- indépendante des incidents applicatifs.

### Pourquoi garder le ML dans un service séparé ?

Parce que cette séparation permet :

- d'isoler les dépendances Python et HuggingFace,
- d'ajuster séparément la mémoire et le CPU,
- de faire évoluer ou remplacer le moteur ML sans déstabiliser Symfony.

### Pourquoi garder Mercure séparé ?

Parce que Mercure a un rôle d'infrastructure temps réel transverse. Le garder isolé :

- clarifie les responsabilités,
- facilite les réglages réseau,
- évite de mélanger publication temps réel et logique métier HTTP classique.

## Limites actuelles et prochaines étapes

La base fournie reste volontairement sobre. Pour aller vers une production pleinement opérationnelle, il restera à ajouter :

- une stratégie de sauvegarde documentée,
- une procédure de release / rollback,
- une supervision minimale,
- la gestion des certificats et du DNS réels,
- éventuellement une préproduction dédiée.

## Décision retenue

Pour DevSpot, la stratégie retenue est la suivante :

- **local** : `docker compose up -d` lance toute la stack utile au projet,
- **production** : stack dédiée, plus stricte, avec reverse proxy frontal, sans outils de dev ni docs runtime,
- **documentation** : hébergée séparément via GitHub Pages ou hébergement statique.

Cette approche permet de conserver une bonne expérience de développement sans confondre les contraintes du local et celles de la production.