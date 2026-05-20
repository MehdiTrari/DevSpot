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
