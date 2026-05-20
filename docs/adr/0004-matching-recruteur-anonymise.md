# ADR 0004 — Matching recruteur anonymisé par défaut

## Contexte

Le matching DevSpot ne devait pas seulement classer des candidats ; il devait aussi limiter l'exposition immédiate d'indices identitaires côté recruteur.

Deux exigences devaient coexister :

- conserver une expérience produit exploitable avec scores, expérience et compétences ;
- éviter d'exposer d'emblée l'identité complète du candidat avant toute intention réelle de contact.

Une exposition nominale immédiate aurait simplifié l'interface, mais aurait contredit l'objectif du projet autour de la réduction des biais et de l'anonymisation de première intention.

## Décision

Nous retenons un **matching recruteur anonymisé par défaut**.

Au premier affichage, la carte recruteur expose une vue réduite :

- `candidateLabel` ;
- scores de matching ;
- années d'expérience ;
- compétences correspondantes et inférées.

L'identité complète n'est révélée que dans deux cas :

- une conversation recruteur ↔ candidat existe déjà ;
- le recruteur initie un premier contact depuis l'interface de matching.

## Conséquences

Conséquences positives :

- cohérence avec le positionnement fairness du projet ;
- réduction de l'exposition directe du nom, du headline et du profil au premier tri ;
- narration plus solide pour la soutenance entre matching, anonymisation et révélation progressive.

Contraintes assumées :

- logique produit plus riche à maintenir ;
- nécessité de recalculer l'état révélé via l'existence d'une conversation ;
- anonymisation encore pragmatique et non équivalente à une anonymisation forte par NER.

## Alternatives écartées

### Révéler tous les profils immédiatement

Écarté car contraire à l'objectif de réduction des biais au premier niveau de sélection.

### Gérer un statut révélé dans une table dédiée

Non retenu. Le projet s'appuie sur une logique plus simple : l'existence de la conversation suffit à déterminer l'état révélé sans ajouter de persistance spécifique.
