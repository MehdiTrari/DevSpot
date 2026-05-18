# Algorithme de matching DevSpot - Resume poster

Ce support presente les resultats principaux de l'algorithme dans un format court, avec un focus sur la fiabilite du ranking, les systemes d'evaluation et les signaux utilises pour calculer les scores.

## Tableau principal des resultats

| Axe evalue | Systeme d'evaluation | Resultats cles | Lecture rapide |
| --- | --- | --- | --- |
| Qualite offline avant amelioration | `matching-eval-before.json` | recall@1 `0.9222`, recall@3 `1.0`, recall@5 `1.0`, mrr `0.9611`, ndcg@5 `0.9371` | la base etait deja forte, mais le top du classement restait perfectible |
| Qualite offline apres amelioration | `matching-eval-after.json` | recall@1 `1.0`, recall@3 `1.0`, recall@5 `1.0`, mrr `1.0`, ndcg@5 `0.9991` | l'amelioration augmente surtout la fiabilite du haut de classement |
| Gain avant -> apres | comparaison offline | recall@1 `+0.0778`, mrr `+0.0389`, ndcg@5 `+0.0620` | le gain principal porte sur la qualite du rang 1 et de l'ordre des meilleurs profils |
| Fiabilite des trois scores actuels | `matching-eval-phase4-guardrail.json` | `baseline`, `semantic`, `enriched_proxy` a `1.0` sur recall@1, recall@3, recall@5, mrr, ndcg@5 | sur ce protocole offline, les trois variantes restent stables et sans regression |
| Score de production retenu | pipeline runtime actuel | classement final par score enrichi DevSpot | `enriched_proxy` reste le proxy offline de ce score dans les evaluations |
| Robustesse top-k | benchmark local `k=30`, `50`, `100` | part juniors top 5 `58.0%`, offres avec junior top 5 `90.0%`, top 1 junior `80.0%` pour les trois valeurs de `k` | la reduction du retrieval top-k ne degrade pas la representation junior sur cette coupe locale |
| Robustesse contrefactuelle | `matching-counterfactual-fairness.json` | `school` et `apparent_origin` : `0.0%` de changement ; `location` : effet concentre sur le `baseline` offline | les signaux identitaires directs testes n'affectent pas le pipeline semantique actuel |

## Systemes d'evaluation

| Systeme | Ce qu'il mesure | Metriques utilisees |
| --- | --- | --- |
| Evaluation offline de ranking | qualite du classement offre -> candidats sur un dataset annote heuristiquement | recall@1, recall@3, recall@5, mrr, ndcg@5 |
| Evaluation avant / apres | gain obtenu entre une version precedente et la version amelioree | deltas sur recall@1, mrr, ndcg@5 |
| Evaluation de robustesse fairness | stabilite de la representation junior et absence d'effet de certains attributs | part juniors top 5, top 1 junior, selection rate ratio, tests contrefactuels |

### Comment le protocole offline produit ses resultats

Dans ce document, `enriched_proxy` designe la variante offline employee pour approximer le score enrichi runtime expose par l'application.

1. chaque offre est comparee a l'ensemble des profils developpeurs du dataset ;
2. un label heuristique `weak_relevance` entre `0` et `3` sert de reference offline ;
3. ce label repose sur le recouvrement de familles de roles, de mots-cles metier et l'adequation d'experience ;
4. les metriques de ranking mesurent ensuite si les profils les plus pertinents remontent bien en tete du classement.

## Ce que l'algorithme utilise pour ses calculs

| Composant | Ce qu'il utilise | Comment il calcule | Role dans le systeme |
| --- | --- | --- | --- |
| Baseline | mots-cles, familles de roles, experience, et dans l'evaluation offline un signal de localisation | score additif borne entre `0` et `1` a partir des overlaps et de l'adequation d'experience | reference explicable et rapide |
| Score semantique | texte d'offre + texte candidat nettoye | embeddings CamemBERT puis similarite cosinus | comprehension du sens, des synonymes et du contexte |
| Score enrichi runtime / proxy offline `enriched_proxy` | score semantique + competences inferees + garde-fou de famille de roles | bonus borne ajoute au score semantique, puis cap sur profils hors famille | le runtime utilise le score enrichi DevSpot ; `enriched_proxy` sert de proxy dans les rapports offline |
| Audit fairness | scores par groupe junior / non-junior | moyennes, ratio d'impact, taux de selection a seuil `0.8` | verification d'equite sur les resultats |
| Tests contrefactuels | versions mutees des profils | recomparaison des rankings et des deltas de score | verification de robustesse sur localisation, ecole, origine apparente |

## Metriques d'evaluation

1. `recall@1` : part des offres pour lesquelles au moins un bon candidat est en premiere position
2. `recall@3` et `recall@5` : meme logique sur les `3` ou `5` premiers profils
3. `mrr` : qualite moyenne du premier bon rang trouve
4. `ndcg@5` : qualite globale de l'ordre des `5` premiers profils, en tenant compte du niveau de pertinence
5. `disparate_impact_ratio` : comparaison des scores moyens juniors / non-juniors
6. `selection_rate_ratio` : comparaison des taux de selection a seuil fixe entre juniors et non-juniors
7. `top5 overlap` et `top1 changed rate` : stabilite du classement entre deux variantes du pipeline

## Ce qu'il faut dire sur la fiabilite

1. la fiabilite du ranking s'ameliore clairement entre `before` et `after`, surtout sur le sommet du classement
2. le score enrichi reste le meilleur candidat comme score produit, car il combine sens semantique et competences implicites
3. sur le protocole actuel, la fairness mesuree ne montre pas de degradation de la representation junior sous reduction de `k`
4. les tests contrefactuels ne montrent pas d'effet direct de `schoolName`, `firstName` ou `lastName` sur le score semantique actuel

## Limites

1. les mesures offline reposent sur un label heuristique `weak_relevance`, pas sur une annotation humaine exhaustive
2. le dataset utilise ici reste un dataset demo de `90` offres et `192` profils publics
3. le benchmark top-k a ete lance localement avec `1` iteration
4. le resultat nul sur `ecole` et `origine apparente` ne vient pas d'un manque de donnees : ces champs sont renseignes, mais ils ne sont pas injectes dans le signal de matching mesure ici
5. la sensibilite residuelle a la localisation vient du `baseline` offline et non du pipeline runtime semantique principal

## Perspectives d'evolution

1. completer l'evaluation offline par davantage de revues humaines ou de labels plus fins
2. consolider le benchmark top-k sur plus d'offres et plus d'iterations
3. suivre les memes metriques sur un dataset moins demo et plus proche de la production
4. aligner explicitement le `baseline` offline avec le runtime si l'on veut neutraliser completement l'effet `location_score`
5. transformer ce support en trois visuels pour le poster : gains avant/apres, architecture de score, robustesse fairness