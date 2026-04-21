# ADR — Architecture Decision Records

Ce dossier est réservé aux **décisions d'architecture importantes**.

## Quand créer un ADR

Créer un ADR quand vous prenez une décision structurante, par exemple :

- introduire un service ML Python au lieu d'intégrer le NLP dans PHP,
- choisir GitHub Actions comme chaîne CI/CD,
- garder un matching hybride (baseline + sémantique + enrichi),
- privilégier des tests unitaires ciblés pour le coverage plutôt qu'une inflation d'E2E.

## Format conseillé

Nom de fichier :

```text
0001-service-ml-separe.md
0002-strategie-tests-coverage.md
```

Structure simple :

1. Contexte
2. Décision
3. Conséquences
4. Alternatives écartées

Les ADR sont très utiles dans un projet de master, car ils montrent la maturité du raisonnement technique, pas seulement le résultat final.