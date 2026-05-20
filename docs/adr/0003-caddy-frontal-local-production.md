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