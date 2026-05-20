# DevSpot — Documentation

Bienvenue dans la documentation centralisée de DevSpot.

Cette base documentaire présente DevSpot selon deux parcours de lecture complémentaires :

- une **vue globale** pour comprendre rapidement le projet, ses objectifs et sa cible d'architecture,
- une **vue technique détaillée** pour entrer dans les choix d'architecture, le matching, la base de données et l'exploitation,
- une **documentation de recherche** pour les briques IA, l'évaluation et le travail académique associé.

## Parcours recommandés

### Lecture globale du projet

- Pour démarrer et situer le périmètre : utiliser le `README.md` à la racine du dépôt
- Pour comprendre l'architecture locale et la cible d'exploitation : [docs/architecture/system-overview.md](architecture/system-overview.md)
- Pour voir la cible de mise en production et les choix d'exposition réseau : [docs/ops/deployment-production.md](ops/deployment-production.md)
- Pour comprendre l'état de la qualité et de l'intégration continue : [docs/ci-cd-github-actions.md](ci-cd-github-actions.md)
- Pour lire les synthèses de résultats côté matching : [docs/research/index.md](research/index.md)

### Lecture technique détaillée

- Pour la porte d'entrée architecture : [docs/architecture/index.md](architecture/index.md)
- Pour le pipeline de matching côté produit : [docs/matching-ia-documentation.md](matching-ia-documentation.md)
- Pour le détail de l'algorithme vectoriel : [docs/camembert-algorithme-technique.md](camembert-algorithme-technique.md)
- Pour l'analyse des écarts méthodologiques et de l'anonymisation : [docs/analyse-methodologie-matching.md](analyse-methodologie-matching.md)
- Pour les décisions d'architecture : [docs/adr/index.md](adr/index.md)

Dans l'environnement local du projet, les URLs de travail retenues sont les suivantes :

- `http://devspot.localhost`
- `http://mercure.devspot.localhost/.well-known/mercure`

## Structure documentaire

- [Architecture](architecture/index.md) : vision technique globale et documents cœur produit
- [Guide du dépôt](guide.md) : structure et règles de maintenance documentaire
- [Développement](dev/index.md) : pratiques de contribution et conventions de travail
- [Ops](ops/index.md) : qualité, CI/CD, performance, exploitation
- [Recherche](research/index.md) : documents liés au matching IA, à l'évaluation et aux synthèses académiques
- [ADR](adr/index.md) : décisions d'architecture importantes
- [Archive](archive/index.md) : anciens documents conservés à titre historique

## Philosophie

Le dépôt Git reste la **source de vérité**. Cette documentation ajoute :

- une **navigation claire**, 
- une **hiérarchie des contenus**,
- une base prête à être **buildée automatiquement** via le workflow `docs`, avec publication GitHub Pages conditionnelle,
- une trace explicite des **choix d'architecture locale et production**.
