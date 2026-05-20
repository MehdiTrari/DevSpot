# Phase 4 - Resultats avant / apres

Ce document regroupe les resultats les plus faciles a reutiliser dans un poster pour illustrer la Phase 4 fairness.

## Protocole commun

1. dataset principal de cette campagne : `docs/matching-demo-dataset-v2-clean.json`
2. taille de cette campagne : `90` offres, `192` profils publics demo
3. des jeux plus recents existent maintenant dans le depot en `320` offres × `1000` profils (`v3 expanded` et `v4 hard negatives`), mais les chiffres ci-dessous correspondent bien a la coupe historique `v2`
4. modele semantique : `camembert-base`
5. projection : `ml/models/devspot-matching-projection.pt`
6. methodes comparees selon les cas : `baseline`, `semantic`, `enriched_proxy`

## 1. Nettoyage du texte candidat : avant / apres

Reference : `docs/matching-cleaning-junior-impact.json`

Comparaison :

1. avant = `legacy_raw`
2. apres = `cleaned`

| Metrique | Avant (`legacy_raw`) | Apres (`cleaned`) | Delta |
| --- | ---: | ---: | ---: |
| Score moyen junior | 0.2425 | 0.4437 | +0.2012 |
| Score moyen non-junior | 0.2884 | 0.4707 | +0.1823 |
| Ratio d'impact junior / non-junior | 0.8408 | 0.9427 | +0.1019 |
| Ratio de selection | 0.8027 | 0.9247 | +0.1220 |
| Ecart moyen junior - non-junior | -0.0459 | -0.0270 | +0.0189 |

Lecture poster :

1. le nettoyage fait monter les scores des deux groupes ;
2. l'effet relatif est un peu plus favorable aux juniors ;
3. l'ecart junior / non-junior se reduit apres nettoyage.

## 2. Retrieval top-k : reference vs reduction de k

Reference : benchmark local du 3 mai 2026 sur `10` offres demo et `192` profils demo.

| Configuration | Temps moyen / offre | Overlap top 5 vs `k=100` | Top 1 identique vs `k=100` | Part juniors top 5 | Offres avec junior top 5 | Top 1 junior |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `k = 30` | 2525.4 ms | 100.0% | 100.0% | 58.0% | 90.0% | 80.0% |
| `k = 50` | 2320.8 ms | 100.0% | 100.0% | 58.0% | 90.0% | 80.0% |
| `k = 100` | 2378.7 ms | reference | reference | 58.0% | 90.0% | 80.0% |

Lecture poster :

1. sur cette coupe demo, reduire `k` ne change pas la representation junior ;
2. le top 5 et le top 1 restent stables entre `30`, `50` et `100` ;
3. la question fairness est donc plus sensible au contenu du score qu'au top-k sur ce sous-ensemble.

## 3. Tests contrefactuels : original vs profil mute

Reference : `docs/matching-counterfactual-fairness.json`

Comparaison :

1. `location` : permutation du `locationType`
2. `school` : remplacement de `schoolName`
3. `apparent_origin` : remplacement de `firstName/lastName`

| Variante | Methode | Paires modifiees | Top 1 modifies | Overlap top 5 | Delta score junior | Delta score non-junior |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `location` | `baseline` | 98.86% | 63.33% | 46.0% | -0.0125 | -0.0062 |
| `location` | `semantic` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `location` | `enriched_proxy` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `school` | `baseline` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `school` | `semantic` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `school` | `enriched_proxy` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `apparent_origin` | `baseline` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `apparent_origin` | `semantic` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |
| `apparent_origin` | `enriched_proxy` | 0.0% | 0.0% | 100.0% | 0.0 | 0.0 |

Lecture poster :

1. l'ecole et l'origine apparente n'ont plus d'effet observable dans le protocole actuel ;
2. la localisation ne pese plus sur `semantic` ni sur `enriched_proxy` ;
3. la seule sensibilite residuelle est concentree dans le `baseline` offline a cause du `location_score`.

## 4. Messages clefs a afficher

1. apres nettoyage, le signal reste plus favorable aux juniors qu'avant ;
2. reduire le retrieval top-k ne degrade pas la representation junior sur la coupe locale testee ;
3. les tests contrefactuels ne montrent plus d'effet de l'ecole ni de l'origine apparente ;
4. la localisation n'affecte plus que le baseline offline, pas le pipeline semantique nettoye.

## 5. Limites a mentionner sur le poster

1. plusieurs mesures restent bornees a un dataset demo ;
2. les benchmarks top-k ont ete faits localement avec `1` iteration ;
3. les conclusions contrefactuelles portent sur le protocole actuel, pas sur tous les futurs signaux produit.