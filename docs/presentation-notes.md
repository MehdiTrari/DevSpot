# Notes de presentation technique

Ce document sert de support pour expliquer les composants et les choix techniques de DevSpot pendant la soutenance.

## Socle technique (definitions courtes)

### Symfony 7
- Framework PHP utilise pour le coeur de l'application web (routing, controleurs, templates, securite, formulaires, services, etc.).
- Structure le code en couches claires (controllers, services, entities, repositories) et facilite la maintenance.

### FastAPI + CamemBERT
- FastAPI : framework Python rapide pour exposer des API REST, utilise ici pour la partie IA.
- CamemBERT : modele de langue francais (transformer) pour la similarite semantique entre offres et profils.
- Separation des responsabilites : Symfony gere le produit, FastAPI isole la couche IA.

### PostgreSQL
- Base de donnees relationnelle robuste, fiable pour les donnees metier (utilisateurs, offres, profils, messages, logs).
- Bon support des jointures, indexes, transactions et contraintes d'integrite.

### Mercure
- Hub de publication/abonnement (SSE) pour le temps reel dans Symfony.
- Sert a pousser des messages instantanes (chat, notifications) sans polling.

### Docker
- Conteneurisation pour isoler les services (app, DB, Mercure, etc.).
- Simplifie les environnements locaux et la cible de production.

### Caddy
- Reverse proxy et serveur web moderne.
- Gere TLS, routing vers les services, et integration avec Mercure.

### MkDocs
- Generateur de documentation statique.
- Utilise pour la documentation technique, centralisee et versionnee.

### Tests
- Tests applicatifs et checks techniques pour limiter les regressions.
- Permet de valider les parcours critiques (candidat, recruteur, admin).

### CI/CD
- Automatisation des controles (tests, lint, build, analyse statique) et des livraisons.
- Objectif : fiabiliser et accelerer le cycle de developpement.

### Logs
- Traces techniques pour diagnostiquer les erreurs et suivre les actions importantes.
- Indispensable pour la maintenance et le support.

### Securite
- Gestion des roles, protection des routes, validation des donnees, CSRF, et gestion des fichiers.
- Voir la section detaillee plus bas.

### Documentation technique
- Documentation de l'architecture, des services, et des procedures.
- Permet de transmettre et de maintenir le projet dans le temps.

## Notification vs log d'audit

- Notification : message destine a l'utilisateur (ex. nouveau message, action sur une offre). Objectif : informer.
- Log d'audit : trace technique ou metier pour garder un historique des actions sensibles (ex. changement de role, validation d'un compte). Objectif : controler et tracer.

## Parcours sensibles controles (exemples)

- Acces aux pages admin ou moderation (roles requis, routes protegees).
- Actions critiques : suppression d'offres, changement de statut, desactivation d'utilisateurs.
- Upload de fichiers : verification du type, taille, et controle serveur.

## Pourquoi Mercure a ete un point dur

Mercure a exige plusieurs ajustements :
- URLs publiques / internes :
  - Dans Docker, un service a une URL interne (ex. http://mercure:3000).
  - Cote navigateur, il faut une URL publique (ex. https://localhost/.well-known/mercure).
  - Il faut donc bien distinguer ces URLs dans la config.
- Caddy :
  - Caddy joue le role de reverse proxy, il doit exposer Mercure et gerer les headers.
- Topics :
  - Les topics doivent etre coherents entre le serveur et le client pour recevoir les bons evenements.
- Authentification :
  - Mercure protege l'acces via JWT, il faut gerer emission et verification correctement.
- Compatibilite locale :
  - TLS local, CORS, et les differences entre Docker et l'execution locale ont demande des ajustements.

## CSRF (definition simple)

CSRF (Cross-Site Request Forgery) : attaque qui force un utilisateur connecte a executer une action non voulue.
Protection : token CSRF unique dans les formulaires, verifie cote serveur.

## Securite : detail pour la soutenance

### Roles
- Exemple : ROLE_CANDIDAT, ROLE_RECRUTEUR, ROLE_ADMIN.
- Chaque role donne acces a des routes et actions specifiquees.

### Pages protegees
- Les routes sensibles sont restreintes par role.
- Les utilisateurs non autorises sont rediriges ou bloques.

### CSRF
- Tokens CSRF integres aux formulaires.
- Verification serveur obligatoire pour toute action sensible.

### Validation serveur
- Les donnees sont valideses cote backend (formats, champs requis, contraintes metier).
- Evite les incoherences, injection ou contournement des validations front.

### Fichiers uploades
- Controle du type MIME et de la taille.
- Stockage securise, nommage safe, et filtrage des extensions.

## Formulation simple pour expliquer

- "La securite repose sur des roles clairs, des routes protegees, des validations serveur et une protection CSRF."
- "Les fichiers uploades sont controles en taille et en type pour eviter les risques."
- "Mercure a ete delicat a brancher car il faut gerer deux URLs, l'authentification, et la compatibilite locale avec Caddy."