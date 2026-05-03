# Synthese de la revue humaine Phase 3

Date : 3 mai 2026

## Jeu relu

- Source : `docs/matching-human-review-pack.md`
- Offres relues : 10
- Candidats proposes : 50
- Notes humaines exploitables : 49
- Note exclue du calcul : Offre 5 / Candidat D

Le pack a ete construit a partir des offres ou les methodes `baseline`, `semantic` et `enriched_proxy` divergeaient le plus dans leur top 5. Il ne s'agit donc pas d'un echantillon aleatoire : il vise volontairement les cas difficiles. La note vide de l'Offre 5 / Candidat D est exclue explicitement du calcul, afin de ne pas inventer de jugement humain.

## Resultats globaux

| Indicateur | Valeur |
|---|---:|
| Moyenne des notes humaines | 3.63 / 5 |
| Mediane des notes humaines | 4.00 / 5 |
| Offres relues | 10 |
| Candidats notes | 49 |

## Alignement avec les notes humaines

Les correlations ci-dessous sont calculees sur les candidats presents dans le pack de revue humaine.

| Methode | Correlation avec note humaine | Erreur moyenne apres mise a l'echelle 0-5 |
|---|---:|---:|
| baseline | 0.540 | 0.777 |
| semantic | -0.441 | 1.028 |
| enriched_proxy | -0.404 | 1.104 |

Lecture :

- la baseline offline est la plus coherente avec la revue humaine sur cet echantillon ;
- le score semantique brut remonte plusieurs profils lexicalement proches mais metier moins pertinents ;
- `enriched_proxy` amplifie certains faux positifs, notamment des profils QA / qualite ou DevOps sur des offres developpeur.

## Top 1 par methode dans le pack

| Methode | Note humaine moyenne du candidat choisi en top 1 | Top humain retrouve |
|---|---:|---:|
| baseline | 4.13 / 5 | 5 / 10 offres |
| semantic | 3.25 / 5 | 1 / 10 offres |
| enriched_proxy | 3.85 / 5 | 1 / 10 offres |

Lecture :

- la baseline choisit plus souvent le candidat juge humainement le meilleur parmi les 5 proposes ;
- `enriched_proxy` garde une note moyenne correcte, mais il ne place pas souvent le meilleur candidat humain en premier ;
- le semantique pur est trop permissif sur certains signaux de qualite, livraison, tests ou collaboration.

## Cas observes

Les commentaires humains indiquent trois causes frequentes d'ecart :

1. **Experience** : les profils juniors peuvent etre competents mais trop eloignes d'une cible a 5 ou 6 ans.
2. **Mode de travail** : remote / hybrid / onsite pese fortement dans la decision humaine.
3. **Famille metier** : QA, DevOps ou mobile peuvent partager des mots-cles utiles mais rester hors sujet pour une offre developpeur PHP ou full stack.

## Conclusion Phase 3

Les metriques heuristiques automatiques etaient saturees a `1.0000` pour toutes les methodes. La revue humaine apporte donc une information beaucoup plus discriminante.

Conclusion pragmatique :

- la preuve de pertinence est maintenant plus solide qu'avant, car elle ne repose plus uniquement sur `weak_relevance()` ;
- le score final enrichi doit etre ajuste avant de servir de reference qualitative ;
- il faut renforcer la penalisation des familles metier hors cible et du mode de travail incompatible ;
- la Phase 3 est terminee pour le protocole actuel, avec 49 notes humaines exploitables et 1 candidat exclu explicitement du calcul.

## Suite appliquee en Phase 4

Une premiere correction a ete appliquee au score avance :

- CamemBERT reste conserve comme base semantique ;
- le bonus enrichi est neutralise quand la famille metier offre / candidat est incompatible ;
- le score enrichi est plafonne a `0.74` dans ces cas pour limiter les faux positifs QA / DevOps / mobile observes dans la revue humaine ;
- le proxy offline `enriched_proxy` applique le meme garde-fou.
