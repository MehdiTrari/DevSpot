# ADR 0001 — Service ML Python séparé

## Contexte

DevSpot est construit principalement autour de Symfony, qui porte le coeur métier, le rendu HTML, la persistance et l'orchestration applicative.

Le projet devait cependant intégrer une brique NLP spécialisée autour de CamemBERT pour le matching sémantique et l'inférence de compétences. Cette brique implique :

- un écosystème Python naturel pour HuggingFace et PyTorch ;
- des dépendances lourdes et spécifiques au ML ;
- des besoins d'exécution distincts du runtime PHP classique.

Une première option aurait consisté à intégrer directement ces traitements dans l'application PHP, au prix d'un couplage fort entre le métier Symfony et l'infrastructure ML.

## Décision

Nous isolons la brique IA dans un **service Python FastAPI dédié**, consommé par Symfony via HTTP interne.

Répartition retenue :

- **Symfony** : orchestration métier, anonymisation, cache, scoring global, présentation des résultats ;
- **service ML FastAPI** : embeddings CamemBERT, similarité sémantique, inférence de compétences ;
- **Docker Compose** : exécution coordonnée des deux briques dans la même stack projet.

## Conséquences

Conséquences positives :

- séparation claire des responsabilités ;
- dépendances ML isolées de l'application PHP ;
- évolution plus simple du modèle sans perturber le coeur web ;
- déploiement plus lisible pour un projet mêlant produit web et expérimentation IA.

Contraintes assumées :

- ajout d'un service réseau supplémentaire à exploiter ;
- gestion d'erreurs inter-services et de timeouts ;
- besoin de documenter explicitement les URLs, healthchecks et variables d'environnement.

## Alternatives écartées

### Intégrer toute la logique IA dans Symfony

Écarté car cela aurait artificiellement complexifié l'environnement PHP, tout en rendant plus difficile l'usage des bibliothèques ML adaptées.

### Extraire plusieurs microservices spécialisés

Écarté à ce stade car le projet n'a pas besoin d'une granularité plus fine. Le compromis retenu reste celui d'un **modular monolith + service ML spécialisé**.

*** Add File: /home/mehdi/DevSpot/docs/adr/0002-pipeline-matching-hybride.md
# ADR 0002 — Pipeline de matching hybride

## Contexte

Le projet devait produire un matching recruteur/candidat à la fois :

- explicable ;
- plus robuste qu'un simple overlap de mots-clés ;
- compatible avec un contexte académique où il faut pouvoir comparer plusieurs approches.

Une approche purement lexicale était trop limitée pour capter la proximité sémantique entre intitulés, stack technique et formulations variées. À l'inverse, une approche uniquement fondée sur un score vectoriel aurait été moins explicable et moins confortable à présenter fonctionnellement.

## Décision

Nous retenons un **pipeline de matching hybride** avec trois scores calculés en parallèle :

- **baseline** : overlap explicite de compétences ;
- **sémantique** : similarité CamemBERT ;
- **enrichi DevSpot** : score sémantique complété par l'inférence de compétences.

Le **score enrichi** est utilisé pour le classement final dans l'application, tandis que les autres scores restent visibles pour comparaison et explicabilité.

## Conséquences

Conséquences positives :

- meilleure lisibilité du comportement du système ;
- possibilité de comparer baseline, sémantique et enrichi dans la documentation et les évaluations ;
- compromis pragmatique entre performance métier, interprétabilité et valeur académique.

Contraintes assumées :

- pipeline plus riche à documenter ;
- calibrage nécessaire entre les différentes composantes ;
- distinction indispensable entre ce qui relève du runtime produit et ce qui relève des évaluations offline.

## Alternatives écartées

### Baseline uniquement

Écarté car trop sensible aux formulations exactes et insuffisant pour valoriser la proximité sémantique.

### Score sémantique seul

Écarté car moins explicable et moins riche pour l'expérience produit.

### Reranker en production

Non retenu dans l'état actuel du projet. Le reranker existe comme piste d'évaluation offline, mais il n'est pas intégré au runtime Symfony car il repose sur une chaîne expérimentale distincte et sur des artefacts d'entraînement dédiés.

*** Add File: /home/mehdi/DevSpot/docs/adr/0003-caddy-frontal-local-production.md
# ADR 0003 — Caddy en frontal local et production

## Contexte

DevSpot devait être démontrable facilement en local tout en préparant une mise en production cohérente.

Une architecture locale sans reverse proxy aurait été plus simple au départ, mais elle aurait créé un écart inutile avec la cible de production, notamment pour :

- le routage HTTP ;
- l'exposition de Mercure ;
- la centralisation des responsabilités réseau ;
- la lisibilité de l'architecture présentée à un jury.

## Décision

Nous utilisons **Caddy comme frontal HTTP** :

- en **local**, devant Symfony et Mercure ;
- en **production**, comme base de reverse proxy public dans la stack fournie.

Les accès `localhost` historiques restent tolérés en compatibilité, mais les URLs recommandées s'appuient sur `devspot.localhost` et `mercure.devspot.localhost`.

## Conséquences

Conséquences positives :

- réduction de l'écart entre développement et production ;
- architecture réseau plus lisible ;
- centralisation du routage et préparation du TLS ;
- démonstration plus propre du rôle du frontal dans la stack.

Contraintes assumées :

- un conteneur supplémentaire dans l'environnement local ;
- configuration Caddy à maintenir ;
- besoin de documenter clairement les URLs utiles et les points d'entrée.

## Alternatives écartées

### Exposer directement Symfony en local

Écarté car cela aurait retardé la mise en place de la forme réseau cible et déplacé le coût d'alignement au moment de la production.

### Utiliser Nginx dès maintenant

Possible techniquement, mais non retenu à ce stade. Caddy offre un compromis plus simple et plus lisible pour le périmètre actuel du projet.