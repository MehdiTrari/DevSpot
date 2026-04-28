# DevSpot — Documentation

Bienvenue dans la documentation centralisée de DevSpot.

Cette base documentaire a pour objectif de présenter le projet de manière structurée à trois niveaux :

- **onboarding rapide** pour lancer et comprendre le dépôt,
- **documentation technique** pour développer et maintenir l'application,
- **documentation de recherche** pour les briques IA, le matching et le travail de master.

## Entrées recommandées

- Pour démarrer le projet : utiliser le `README.md` à la racine du dépôt
- Pour comprendre l'architecture système : [docs/architecture/system-overview.md](architecture/system-overview.md)
- Pour comprendre le matching IA côté produit : [docs/matching-ia-documentation.md](matching-ia-documentation.md)
- Pour le détail de l'algorithme CamemBERT : [docs/camembert-algorithme-technique.md](camembert-algorithme-technique.md)
- Pour l'analyse des écarts méthodologiques et de l'anonymisation : [docs/analyse-methodologie-matching.md](analyse-methodologie-matching.md)
- Pour la feuille de route d'amélioration du matching : [docs/matching-improvement-roadmap.md](matching-improvement-roadmap.md)
- Pour la chaîne qualité / CI : [docs/ci-cd-github-actions.md](ci-cd-github-actions.md)
- Pour la cible de production : [docs/ops/deployment-production.md](ops/deployment-production.md)

Dans l'environnement local du projet, les URLs de travail retenues sont les suivantes :

- `http://devspot.localhost`
- `http://mercure.devspot.localhost/.well-known/mercure`

## Structure documentaire

- [Architecture](architecture/index.md) : vision technique globale et documents cœur produit
- [Guide du dépôt](guide.md) : structure et règles de maintenance documentaire
- [Développement](dev/index.md) : pratiques de contribution et conventions de travail
- [Ops](ops/index.md) : qualité, CI/CD, performance, exploitation
- [Recherche](research/index.md) : documents liés au matching IA, à l'évaluation et aux pistes de mémoire
- [ADR](adr/index.md) : décisions d'architecture importantes
- [Archive](archive/index.md) : anciens documents conservés à titre historique

## Philosophie

Le dépôt Git reste la **source de vérité**. Cette documentation ajoute :

- une **navigation claire**, 
- une **hiérarchie des contenus**,
- une base prête à être **publiée automatiquement** via GitHub Pages,
- une trace explicite des **choix d'architecture locale et production**.
