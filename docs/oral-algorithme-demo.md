# Présentation orale - Poster et démo DevSpot

## Objectif en 5-7 minutes

L'objectif est de présenter le poster sans réciter le mémoire. La structure à suivre est celle du poster :

1. problématique ;
2. principe général ;
3. fonctionnement en 4 étapes ;
4. protocole expérimental ;
5. résultats ;
6. limites et perspectives ;
7. courte démo DevSpot.

Phrase d'ouverture :

« Notre sujet porte sur l'IA dans le recrutement automatisé. Le point de départ est le suivant : beaucoup de systèmes classent encore les candidats avec des mots-clés. Cela peut écarter des profils pertinents, surtout juniors, lorsqu'ils n'utilisent pas exactement le vocabulaire attendu par l'offre. »

## 1. Problématique

À dire en pointant la partie gauche du poster :

« La problématique est : comment une architecture de recrutement basée sur l'IA peut-elle optimiser l'appariement des profils tout en garantissant l'anonymisation des données et la détection des compétences transversales ? »

Explication courte :

- les filtres lexicaux sont simples mais limités ;
- ils peuvent introduire des biais, notamment contre les juniors ;
- un profil peut avoir des compétences réelles sans les formuler avec les bons mots-clés ;
- notre objectif est donc d'améliorer le classement, pas de remplacer le recruteur.

Transition :

« Pour répondre à cela, on a construit une approche hybride, visible dans le bloc "Principe général" du poster. »

## 2. Principe général

Le poster résume l'approche en trois briques :

- **analyse métier par compétences explicites** : ce que le candidat et l'offre déclarent clairement ;
- **analyse sémantique basée sur CamemBERT** : compréhension du sens des textes ;
- **enrichissement par détection de compétences implicites** : compétences visibles à travers les projets, expériences ou formulations indirectes.

À dire :

« Le but est de réduire les biais dans le recrutement, de ne pas pénaliser les profils juniors, d'améliorer la compréhension sémantique des profils et de rester cohérent avec une logique RGPD en limitant les données identifiantes utilisées. »

Phrase synthèse :

« Notre pipeline ne dépend pas d'un seul score. Il combine du lexical, du sémantique, des règles métier et un reclassement supervisé. »

## 3. Fonctionnement en 4 étapes

Reprendre exactement les quatre étapes du poster.

### Étape 1 - Prétraitement

« On nettoie le texte, on le normalise et on construit un profil candidat exploitable par le système. »

Exemples :

- bio ;
- compétences ;
- expériences ;
- formations ;
- postes recherchés.

### Étape 2 - Anonymisation

« On supprime ou masque les informations directement identifiantes : mail, adresse, téléphone et données sensibles visibles. »

Nuance importante :

« On parle ici d'anonymisation partielle ou de désidentification. Cela réduit l'exposition des données personnelles, mais cela ne supprime pas tous les signaux indirects comme la localisation ou certains parcours. »

### Étape 3 - Vectorisation sémantique

« On transforme le texte de l'offre et le texte candidat en vecteurs avec CamemBERT. Les embeddings ont 768 dimensions. Ensuite, on compare les vecteurs avec une similarité cosinus. »

À ne pas survendre :

« CamemBERT n'est pas fine-tuné de bout en bout sur notre dataset. On l'utilise comme encodeur sémantique francophone. »

### Étape 4 - Matching hybride

« Le classement final combine plusieurs signaux : overlap de compétences, similarité cosinus, inférence de compétences implicites, puis reranker supervisé. »

Définir le reranker simplement :

« Le reranker est un modèle supervisé léger, tabulaire. Il prend les scores déjà calculés et d'autres signaux métier, comme l'alignement du rôle ou l'expérience, pour apprendre à mieux ordonner les candidats. Ce n'est pas un nouveau Transformer. »

## 4. Protocole expérimental

À dire en pointant le bloc "Protocole" :

« Pour évaluer le système, on a utilisé un benchmark de 320 offres et 1000 profils. Les méthodes comparées sont la baseline lexicale, le score sémantique CamemBERT, le score enrichi et le reranker. »

Chiffres du poster :

- dataset : **320 offres / 1000 profils** ;
- modèle sémantique : **CamemBERT base** ;
- projection : utilisée pour adapter l'espace vectoriel ;
- méthodes comparées : **Baseline / Sémantique / Enrichi / Reranker** ;
- candidats positifs explicites par offre : **12** ;
- candidats négatifs explicites par offre : **12** ;
- taux de positifs : **1,2 %**.

Métriques :

- **Recall@K** : est-ce qu'un bon profil apparaît dans les K premiers ?
- **MRR** : qualité du premier bon résultat ;
- **nDCG@5** : qualité globale du top 5 ;
- **fairness** : comparaison juniors / non-juniors, localisation, âge, école.

Phrase utile :

« Dans un contexte de recrutement, les métriques de classement sont plus pertinentes qu'une simple accuracy, parce que le recruteur regarde surtout les premiers résultats. »

## 5. Résultats du poster

Le poster affiche les résultats sur l'ensemble complet du benchmark.

| Méthode | Recall@1 | Recall@3 | Recall@5 | MRR | nDCG@5 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Baseline | 0.1406 | 0.2156 | 0.2875 | 0.2164 | 0.1021 |
| Sémantique | 0.0375 | 0.1500 | 0.2250 | 0.1285 | 0.0555 |
| Enrichi | 0.1437 | 0.2687 | 0.3531 | 0.2460 | 0.1097 |
| Reranker | 0.7562 | 0.9719 | 0.9875 | 0.8675 | 0.6990 |

Note orale si le jury lit le poster :

« Sur le poster, le nDCG@5 sémantique apparaît comme 0.555. Dans le rapport d'évaluation, la valeur est 0.0555 ; c'est cette valeur qu'on retient pour l'interprétation. »

Interprétation :

« La baseline est utile mais limitée. Le score sémantique seul ne suffit pas, parce qu'un modèle généraliste comprend le sens global mais distingue mal des profils techniques très proches. Le score enrichi améliore le top 3 et le top 5, car il ajoute des compétences implicites. Le meilleur classement vient du reranker, parce qu'il combine les signaux lexicaux, sémantiques et métier. »

Phrase clé :

« Le résultat principal n'est pas "CamemBERT gagne". Le résultat principal est qu'une architecture hybride fonctionne mieux qu'un score unique. »

Nuance hold-out si question :

« Sur le split de test séparé, donc avec 48 offres non vues à l'entraînement, le reranker reste le meilleur : Recall@1 = 0.6458 et Recall@3 = 0.9583. »

## 6. Limites et perspectives

Reprendre les cases du bas du poster :

- les tests reposent sur les protocoles actuels ;
- il reste une sensibilité possible à la localisation ;
- l'évaluation offline repose sur des labels heuristiques ;
- il faut augmenter les revues humaines ;
- il faut améliorer le dataset ;
- il faut neutraliser davantage l'effet `location_score` ;
- il faut consolider le benchmark top-k.

À dire clairement :

« L'outil reste une aide à la décision. Les résultats offline sont encourageants, mais ils ne remplacent pas une validation humaine, surtout dans un domaine sensible comme le recrutement. »

## 7. Démo DevSpot

Sur `/matching/demo`, montrer une offre et un profil.

Ordre conseillé :

1. Montrer les compétences extraites de l'offre.
2. Montrer le CV/profil anonymisé utilisé par le matching.
3. Montrer les quatre scores :
   - baseline ;
   - CamemBERT ;
   - score enrichi ;
   - reranker supervisé.
4. Montrer les compétences implicites inférées.
5. Expliquer que le classement final utilise le reranker quand il est disponible, sinon le système retombe sur le score enrichi.

Phrase de démo :

« Ici, on voit que le candidat n'est pas seulement évalué sur des mots-clés exacts. Le système part d'un texte prétraité et anonymisé, calcule une proximité sémantique, ajoute des compétences implicites, puis utilise le reranker pour produire un classement final. »

## Conclusion courte

« Notre contribution est d'avoir construit et évalué un pipeline hybride de recrutement automatisé. Il combine anonymisation, analyse de compétences, CamemBERT, inférence de compétences implicites et reranking supervisé. Les résultats montrent que le classement s'améliore surtout quand on combine plusieurs signaux, tout en gardant une supervision humaine et une vigilance sur les biais. »
