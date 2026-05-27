# Système & déploiement

Cette page présente l'architecture technique actuelle de DevSpot, le rôle des services Docker en local ainsi que la cible de déploiement retenue pour le projet.

## Vue d'ensemble

DevSpot repose sur une architecture de type **modular monolith + services spécialisés** :

- **Symfony** porte le coeur métier, le rendu HTML, la sécurité, la persistance et l'orchestration applicative.
- **PostgreSQL** stocke les données métier.
- **Mercure** gère les mises à jour temps réel.
- **Mailpit** sert d'outillage local pour les emails.
- **FastAPI / CamemBERT** porte le service de matching sémantique et d'inférence de compétences.
- **MkDocs** sert la documentation locale et la publication documentaire.

## Topologie locale

```text
Navigateur
   │
   ├── http://devspot.localhost -> Caddy -> Symfony
   ├── http://mercure.devspot.localhost -> Caddy -> Mercure
   ├── http://localhost:8000  -> Caddy -> Symfony
   ├── http://localhost:3000  -> Caddy -> Mercure
   ├── http://localhost:8002/DevSpot/  -> MkDocs
   ├── http://localhost:8081  -> Adminer
   ├── http://localhost:8025  -> Mailpit
   └── http://localhost:8001  -> ML FastAPI

Symfony
   ├── PostgreSQL  -> persistance
   ├── Mercure     -> temps réel
   ├── Mailpit     -> email local
   └── ML FastAPI  -> matching IA / inférence
```

## Services Docker locaux

### Services coeur produit

- **reverse-proxy** : Caddy local, point d'entrée HTTP de Symfony et Mercure
- **app** : application Symfony dockerisée, exposée via Caddy sur `devspot.localhost`
- **database** : PostgreSQL, accessible sur `localhost:5433`
- **mercure** : hub temps réel, exposé via Caddy sur `mercure.devspot.localhost`
- **ml** : service FastAPI / CamemBERT, accessible sur `localhost:8001`

### Services de support local

- **mailer** : Mailpit, SMTP local sur `localhost:1025`, interface web sur `localhost:8025`
- **adminer** : inspection SQL locale sur `localhost:8081`
- **docs** : documentation MkDocs locale sur `localhost:8002/DevSpot/` (`localhost:8002` redirige vers cette URL)

## Pourquoi nous n'utilisons pas de Compose profiles ici

Les **Compose profiles** permettent de rendre certains services optionnels, par exemple :

- `docs` uniquement quand on travaille sur la documentation,
- `adminer` uniquement pour l'inspection SQL,
- un service de tests E2E ou d'observabilité ponctuel.

Dans DevSpot, nous **n'utilisons pas de profiles pour les services coeur** pour les raisons suivantes :

- le service **ML est obligatoire** au fonctionnement métier,
- la stack doit être lançable simplement avec une seule commande,
- le projet a un enjeu académique où la documentation doit aussi être facile à montrer.

En conséquence, `docker compose up -d --build` lance l'ensemble de la stack locale utile au projet. Si, à terme, le temps de démarrage devenait pénalisant, `docs` et `adminer` constitueraient les premiers candidats naturels à un passage en profile optionnel.

## Pourquoi nous utilisons aussi Caddy en local ?

Le projet utilise désormais **Caddy également en local** afin de réduire l'écart entre développement et production.

Ce choix permet de :

- tester dès le développement une architecture avec point d'entrée frontal,
- garder les mêmes responsabilités réseau qu'en production,
- préparer proprement la gestion future des headers, du TLS et du routage,
- éviter un changement d'architecture trop brutal au moment du déploiement.

En local, l'objectif n'est pas de reproduire intégralement la production, mais de se rapprocher de sa **forme réseau**.

## Domaines locaux recommandés

Dans le cadre du projet, les URLs suivantes sont retenues en local :

- `http://devspot.localhost`
- `http://mercure.devspot.localhost/.well-known/mercure`

Pourquoi ce choix :

- `.localhost` est résolu automatiquement vers la machine locale sur les environnements modernes,
- cela permet d'introduire des noms d'hôtes explicites sans configuration DNS dédiée,
- cela rapproche le développement des conventions de la production.

Les accès `localhost:8000` et `localhost:3000` sont conservés pour compatibilité et transition.

## Choix d'architecture

Dans l'état actuel du projet, cette architecture permet de séparer clairement :

- le **métier et l'orchestration** dans Symfony,
- la **spécialisation IA** dans un service Python isolé,
- les **services techniques** dans des conteneurs dédiés.

Cela évite de transformer prématurément le projet en microservices, tout en gardant une frontière nette autour du moteur ML.

## Cible de production retenue

Pour la production, nous retenons la même séparation logique qu'en local, avec un renforcement des contraintes d'exposition réseau et d'exploitation :

1. **reverse proxy en frontal**
   - Nginx, Caddy ou Traefik devant Symfony et Mercure
   - terminaison TLS centralisée

2. **Symfony en conteneur dédié**
   - image immuable construite en CI
   - variables d'environnement injectées au déploiement
   - migrations lancées explicitement dans la release, pas à chaque boot

3. **PostgreSQL managé ou isolé**
   - volume persistant, sauvegardes, supervision
   - accès non exposé publiquement

4. **service ML isolé et monitoré**
   - CPU ou GPU selon la charge
   - healthchecks, logs, limitation mémoire
   - cache HuggingFace persistant

5. **Mercure protégé**
   - publication non exposée publiquement
   - URL publique distincte de l'URL interne si nécessaire

6. **documentation séparée du runtime applicatif**
   - publication via GitHub Pages ou hébergement statique
   - pas nécessaire dans la stack de production applicative

Pour le détail des choix de déploiement, du reverse proxy et de la cible de production, voir [../ops/deployment-production.md](../ops/deployment-production.md).

## Implémentation présente dans le dépôt

Le dépôt contient maintenant deux approches distinctes :

- **local** : `compose.yaml` + `compose.override.yaml`
- **production** : `compose.prod.yaml`

### Local

Le local privilégie la simplicité et la démonstration :

- toute la stack démarre avec une seule commande,
- Caddy sert déjà de reverse proxy frontal pour Symfony et Mercure,
- la documentation et les outils d'administration restent disponibles,
- l'application Symfony utilise une image orientée développement.

### Production

La base de production fournie dans le dépôt formalise les choix retenus pour le projet :

- reverse proxy **Caddy** en frontal,
- Symfony dans une image dédiée de production,
- PostgreSQL, ML et Mercure sur réseau privé,
- documentation séparée du runtime applicatif,
- configuration via `.env.prod`.

## Perspectives d'évolution

### Court terme

- conserver `docker compose up -d --build` comme point d'entrée unique en local,
- documenter les variables d'environnement Docker importantes,
- vérifier que les migrations et données minimales permettent un démarrage sans friction.

### Moyen terme

- consolider l'homogénéité des URLs et conventions réseau entre local et production autour de Caddy,
- produire une image Symfony plus proche de la prod (PHP-FPM + serveur web),
- distinguer clairement `compose` local et déploiement production.

### Production

- sortir `docs`, `adminer` et `mailer` du périmètre runtime public,
- centraliser logs et métriques,
- prévoir sauvegardes base de données, rotation des secrets et stratégie de rollback.
