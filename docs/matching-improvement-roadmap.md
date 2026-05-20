# Roadmap d'amelioration du matching DevSpot

## Objet

Ce document sert de plan de travail incremental pour faire evoluer le matching sans casser le produit.

Note de lecture : plusieurs mesures historiques de cette roadmap ont ete produites sur le dataset demo `docs/matching-demo-dataset-v2-clean.json` (`90` offres, `192` profils). Le depot contient maintenant aussi des jeux plus larges, `matching-demo-dataset-v3-expanded-bias-audit.json` et `matching-demo-dataset-v4-hard-negatives-bias-audit.json`, tous deux en `320` offres x `1000` profils.

L'idee est de traiter les ameliorations **une par une**, avec un ordre de priorite compatible avec l'etat actuel du projet :

1. reduire l'ecart sur le **nettoyage runtime** ;
2. optimiser le **matching et le temps de calcul** ;
3. consolider ensuite la **pertinence** et la **fairness** ;
4. conserver l'indication et la prise en compte des **juniors** dans les futures evolutions.

---

## Phase 1 - Nettoyage runtime des donnees

**Statut actuel : terminee**

### Objectif

Supprimer l'ecart moyen actuel sur le nettoyage en ajoutant un vrai pipeline runtime avant anonymisation et embedding.

### Cible fonctionnelle

Le matching live doit utiliser un texte candidat :

- propre ;
- stable ;
- dedoublonne ;
- lexicalement harmonise ;
- identique en logique a ce qui est attendu dans les scripts offline.

### To do

1. [x] creer un service `CandidateTextPreprocessor`
2. [x] definir un ordre de construction canonique du texte candidat
3. [x] ajouter la normalisation Unicode et espaces
4. [x] ajouter une premiere canonicalisation des technologies et postes frequents
5. [x] dedoublonner les competences, technos et fragments repetes
6. [x] produire un texte runtime stable avant anonymisation
7. [x] brancher ce service dans le pipeline de matching
8. [x] aligner les scripts offline sur les memes regles de nettoyage
9. [x] ajouter des tests unitaires sur les cas de canonicalisation et dedoublonnage

### Iteration 1 realisee

La premiere implementation de la phase 1 est desormais en place :

1. le texte candidat n'est plus construit comme un simple bloc `headline + bio + experiences brutes` ;
2. le matching utilise un texte structure par sections :
   - `Headline`
   - `Summary`
   - `Target roles`
   - `Core skills`
   - `Soft skills`
   - `Experience`
   - `Education`
3. les emails, telephones, URLs et labels de contact parasites sont retires avant anonymisation ;
4. les noms d'entreprise et d'ecole ne sont plus injectes dans le texte de matching ;
5. plusieurs variantes frequentes sont harmonisees :
   - `reactjs` -> `React`
   - `react.js` -> `React`
   - `vuejs` -> `Vue.js`
   - `postgres` -> `PostgreSQL`
   - `api platform` -> `API Platform`
   - `ci cd` -> `CI/CD`
6. le texte produit est plus stable pour le reranking semantique et l'inference.

### Raffinements non bloquants apres Phase 1

1. factoriser davantage les regles communes entre PHP runtime et scripts Python ;
2. elargir la table de canonicalisation sur les variantes encore visibles dans le dataset ;
3. ajouter des tests de non-regression sur des profils "bruyants" reels du dataset demo ;
4. mesurer l'impact du preprocessor sur les embeddings et sur le top 5.

### Livrables attendus

- un composant PHP dedie ;
- une suite de tests unitaires ;
- une mise a jour de la documentation du pipeline.

### Critere de fin

On peut dire honnetement que le matching live ne repose plus seulement sur une normalisation legere, mais sur une vraie etape de nettoyage runtime centralisee.

Ce critere est maintenant rempli car :

1. le runtime produit un texte candidat structure et nettoye ;
2. l'offline produit des textes de matching alignes sur la meme logique ;
3. des tests de non-regression existent en PHP et en Python sur un fixture bruite partage.

---

## Phase 2 - Optimisation du matching

**Statut actuel : terminee**

### Objectif

Eviter le calcul complet sur tous les profils a chaque lancement de matching, tout en gardant le score enrichi final et l'indication junior.

### Principe cible

Le pipeline doit devenir :

1. preselection / retrieval ;
2. top-k ;
3. reranking enrichi.

### To do

1. pre-calculer les embeddings des profils publics
2. definir la strategie de recalcul a la mise a jour du profil
3. stocker ces embeddings de maniere exploitable
4. calculer le retrieval initial sur le pool disponible
5. limiter le reranking complet a un top-k
6. comparer plusieurs valeurs de `k` :
   - 30
   - 50
   - 100
7. mesurer le gain de temps et l'impact sur la qualite du top 5
8. garder le score enrichi et l'indication junior dans la phase de reranking
9. documenter clairement la nouvelle architecture retrieval + reranking

### Premier increment deja implemente

Un premier pas pragmatique a ete mis en place dans `OfferMatchingService` :

1. le **score baseline** continue d'etre calcule sur tous les profils publics ;
2. les calculs **semantiques** et **enrichis** ne s'executent plus que sur un **top-k baseline** ;
3. la limite par defaut est actuellement fixee a **50 profils** ;
4. les profils hors top-k restent presents dans le resultat final, mais sans score semantique ni enrichi ;
5. la logique **junior-aware** est conservee dans le score final.

Cet increment ne termine pas la phase 2 :

1. il n'y a pas encore de **retrieval vectoriel pre-calcule** ;
2. les embeddings candidats ne sont pas encore **persistes** ;
3. le top-k actuel repose encore sur une **preselection baseline**, pas sur une recherche vectorielle dediee.

### Deuxieme increment deja implemente

Le stockage des embeddings candidats est maintenant present :

1. chaque `DeveloperProfile` peut stocker :
   - son embedding de matching,
   - sa dimension,
   - le hash du texte de matching,
   - la date de mise a jour ;
2. un service de refresh en batch recalcule uniquement les embeddings devenus obsoletes ;
3. une commande `app:matching:refresh-embeddings` permet de backfiller le stock ;
4. le score semantique du reranking reutilise en priorite les embeddings stockes.

La phase 2 reste cependant ouverte :

1. le **retrieval initial** n'utilise pas encore ce stock ;
2. la preselection reste faite par le **score baseline** ;
3. les valeurs de `k` ne sont pas encore comparees de maniere mesuree ;
4. le gain de performance n'est pas encore documente quantitativement.

### Troisieme increment deja implemente

La preselection du reranking a maintenant bascule vers un **retrieval vectoriel** quand le stock d'embeddings est disponible :

1. l'offre est embeddee une seule fois ;
2. les embeddings candidats stockes sont compares par cosinus ;
3. le reranking semantique / enrichi se fait ensuite sur le **top vectoriel** ;
4. si certains profils n'ont pas encore d'embedding exploitable, un **complement baseline** est conserve pour remplir le sous-ensemble ;
5. le backfill des embeddings est maintenant securise par **batchs bornes** pour eviter les timeouts trop agressifs.

Avec cet increment, la phase 2 est quasiment complete sur le plan technique.

### Ce qu'il reste avant de clore la phase 2

1. comparer plusieurs valeurs de `k` de maniere explicite ;
2. mesurer le gain de temps reel sur le matching recruteur ;
3. documenter ce resultat avec quelques chiffres simples.

### Mesure locale de cloture

Une mesure locale de sanity check a ete effectuee sur le **28 avril 2026** avec :

1. `1` offre demo active ;
2. `192` profils publics demo ;
3. `101` profils demo disposant deja d'un embedding stocke ;
4. `1` iteration par configuration ;
5. comparaison `k = 30`, `50`, `100`.

Resultat observe :

1. `k = 30` : `2321.1 ms/offre`, overlap top 5 de `20.0%` vs `k = 100` ;
2. `k = 50` : `7878.6 ms/offre`, overlap top 5 de `60.0%` vs `k = 100` ;
3. `k = 100` : `36354.0 ms/offre`, reference.

Interpretation pragmatique :

1. le gain de temps est massif quand on reduit `k` ;
2. `k = 30` est trop agressif sur ce jeu local ;
3. `k = 50` offre deja un meilleur compromis, mais peut encore modifier fortement le top 5 ;
4. dans l'etat actuel, **`k = 50` reste un choix pragmatique defensable** pour continuer.

Au vu de cette mesure et des increments techniques deja realises, la **phase 2 peut etre consideree comme terminee** a l'echelle actuelle du projet.

### Version pragmatique recommandee

Avant une vraie base vectorielle :

1. embeddings candidats pre-calcules ;
2. retrieval cosinus sur ce stock ;
3. reranking enrichi sur top 50 ou top 100.

### Critere de fin

Le premier matching recruteur doit devenir significativement plus rapide sans perte evidente sur le top 5 et sans supprimer la logique junior-aware.

---

## Phase 3 - Pertinence

**Statut actuel : terminee pour le protocole actuel**

### Objectif

Renforcer la validite du score final et reduire l'ecart actuel sur la preuve de pertinence.

### To do

1. [x] garder les metriques offline actuelles :
   - Recall@1
   - Recall@3
   - Recall@5
   - MRR
   - nDCG@5
2. [x] ajouter une evaluation qualitative humaine sur un echantillon
3. [x] faire noter des top 5 par un expert ou un recruteur
4. [x] comparer :
   - baseline
   - semantique
   - enrichi
5. [ ] isoler l'effet du nettoyage runtime sur la pertinence
6. [ ] isoler l'effet du retrieval top-k sur la pertinence
7. [x] documenter les cas ou le bonus heuristique aide vraiment les juniors

### Premier increment en cours

Le premier increment de la phase 3 met en place un protocole offline plus lisible sans modifier le pipeline live :

1. `scripts/evaluate_matching_model.py` compare maintenant plusieurs methodes dans un meme rapport :
   - `baseline` : score lexical / metier offline ;
   - `semantic` : similarite CamemBERT / projection ;
   - `enriched_proxy` : approximation offline du score enrichi, documentee comme proxy ;
2. `weak_relevance()` reste uniquement le label faible d'evaluation et n'est pas utilise comme score baseline ;
3. un export de revue humaine anonymisee peut etre genere avec `--review-output` ;
4. les offres de revue sont selectionnees en priorite sur les plus forts desaccords entre methodes ;
5. des tests Python couvrent les metriques, la stabilite du ranking de revue et l'absence de champs identifiants dans le pack.

Commandes utiles :

```bash
python3 scripts/evaluate_matching_model.py \
  --dataset docs/matching-demo-dataset-v2-clean.json \
  --model camembert-base \
  --projection ml/models/devspot-matching-projection.pt \
  --methods baseline,semantic,enriched_proxy \
  --output docs/matching-eval-phase3.json \
   --review-output docs/matching-human-review-summary.md
```

Ce premier increment cloture la phase 3 pour le protocole actuel : il prepare puis analyse la revue humaine. Une note vide a ete exclue explicitement du calcul.

### Mesure Phase 3 du 3 mai 2026

Une evaluation complete a ete generee avec :

1. dataset : `docs/matching-demo-dataset-v2-clean.json` ;
2. modele : `camembert-base` ;
3. projection : `ml/models/devspot-matching-projection.pt` ;
4. methodes : `baseline`, `semantic`, `enriched_proxy` ;
5. sortie : `docs/matching-eval-phase3.json` ;
6. synthese humaine : `docs/matching-human-review-summary.md`.

Resultat des metriques faibles :

| Methode | Recall@1 | Recall@3 | Recall@5 | MRR | nDCG@5 |
|---|---:|---:|---:|---:|---:|
| baseline | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |
| semantic | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |
| enriched_proxy | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |

Interpretation :

1. les labels faibles sont trop permissifs sur le dataset actuel ;
2. les metriques IR ne discriminent plus les methodes ;
3. la prochaine action utile est donc la **notation humaine du top 5**, dont la synthese est conservee dans `docs/matching-human-review-summary.md`.

### Revue humaine du 3 mai 2026

La revue humaine a ensuite ete analysee dans `docs/matching-human-review-summary.md`.

Jeu relu :

1. `10` offres ;
2. `50` candidats proposes ;
3. `49` notes humaines exploitables ;
4. `1` candidat exclu explicitement du calcul : Offre 5 / Candidat D.

Resultats principaux :

| Methode | Correlation avec note humaine | Note humaine moyenne du top 1 choisi | Top humain retrouve |
|---|---:|---:|---:|
| baseline | 0.540 | 4.13 / 5 | 5 / 10 |
| semantic | -0.441 | 3.25 / 5 | 1 / 10 |
| enriched_proxy | -0.404 | 3.85 / 5 | 1 / 10 |

Interpretation :

1. la baseline offline est la plus proche du jugement humain sur ce pack difficile ;
2. le semantique brut est trop permissif sur des signaux de qualite, livraison ou collaboration ;
3. `enriched_proxy` amplifie certains faux positifs, notamment des profils QA / DevOps sur des offres developpeur ;
4. les commentaires humains montrent que l'experience, le mode de travail et la famille metier doivent peser davantage.

Decision :

1. Phase 3 apporte maintenant une preuve qualitative humaine, donc l'ecart de pertinence est reduit ;
2. la phase est cloturee pour le protocole actuel, avec exclusion explicite de la note manquante ;
3. les ajustements fins du score enrichi deviennent une future iteration produit, pas un pre-requis pour passer a la Phase 4.

### Critere de fin

La pertinence du systeme n'est plus seulement defendue par des labels heuristiques offline, mais aussi par une lecture qualitative humaine.

---

## Phase 4 - Fairness

**Statut actuel : en cours**

### Objectif

Renforcer la fairness sans perdre la logique de valorisation des juniors.

### Contrainte importante

Le projet doit **garder l'indication junior** et la logique de soutien aux profils peu experimentes.

L'objectif n'est donc pas de supprimer la dimension junior-aware, mais de mieux la mesurer et la justifier.

### To do

1. [x] conserver l'audit junior / non-junior actuel
2. [x] expliciter clairement le sens du bonus junior et ses limites
3. [x] mesurer l'effet du nettoyage sur les scores juniors
4. [x] mesurer l'effet du retrieval top-k sur la representation des juniors
5. [x] ajouter des tests contrefactuels simples sur l'anonymisation
6. [x] etudier ensuite des axes supplementaires :
   - [x] ecole
   - [x] origine apparente
   - [x] localisation
7. [x] documenter la difference entre :
   - aide aux juniors,
   - preuve de fairness generale

### Premier increment Phase 4

Le premier increment renforce la mesure sans modifier le ranking :

1. `FairnessAuditor` conserve les metriques existantes :
   - `junior_avg_score` ;
   - `non_junior_avg_score` ;
   - `disparate_impact_ratio` ;
2. il ajoute des indicateurs plus interpretables :
   - effectifs juniors / non-juniors ;
   - taux de selection a seuil `0.8` ;
   - ratio de taux de selection ;
   - ecart moyen de score ;
   - statut de lecture (`balanced_selection_rate`, `junior_under_selected`, `junior_over_selected`, `insufficient_comparison_population`) ;
3. un test contrefactuel verifie que deux CV identiques sauf identite directe produisent le meme texte anonymise.

Important : ce n'est pas encore une preuve de fairness generale. C'est une mesure plus transparente de la dimension junior / non-junior, plus un premier garde-fou sur l'identite directe.

### Lien avec la revue humaine Phase 3

La revue humaine n'a pas seulement servi a "valider" le systeme. Elle a surtout montre ou le score avance se trompe :

1. profils QA / qualite trop bien remontes sur des offres developpeur ;
2. profils DevOps ou mobile parfois proches lexicalement mais hors famille metier ;
3. experience cible et mode de travail trop peu discriminants.

La suite produit probable est donc d'ajuster l'algo avance, mais pas dans ce premier increment fairness :

1. ajouter des garde-fous de famille metier avant d'appliquer un bonus enrichi ;
2. penaliser plus explicitement les incompatibilites fortes de mode de travail ;
3. calibrer le bonus junior pour aider les juniors pertinents sans pousser des profils hors cible.

### Garde-fou faux positifs Phase 4

Un garde-fou metier a ete ajoute sans retirer CamemBERT :

1. le score semantique CamemBERT reste calcule ;
2. le score enrichi garde l'inference de competences ;
3. si l'offre et le candidat appartiennent a des familles metier incompatibles, le bonus enrichi est neutralise ;
4. dans ce cas, le score enrichi final est plafonne a `0.74` pour eviter qu'un faux positif QA / DevOps / mobile remonte au-dessus de bons profils developpeur ;
5. le proxy offline `enriched_proxy` applique le meme principe pour garder l'evaluation coherente.

Ce garde-fou cible directement les erreurs observees dans `docs/matching-human-review-summary.md`.

### Instrumentation top-k / juniors

Le benchmark `app:matching:benchmark` expose maintenant un premier jeu de signaux pour mesurer l'effet du top-k sur la representation des juniors dans le resultat final :

1. part moyenne des juniors dans le top 5 final ;
2. taux d'offres avec au moins un junior dans le top 5 ;
3. taux d'offres avec un junior en top 1.

Dans cette lecture, un junior reste defini comme un profil a `<= 2` ans d'experience, de maniere coherente avec `FairnessAuditor`.

Cet increment ne clot pas encore le point roadmap : il ajoute l'instrumentation necessaire pour lancer ensuite une vraie mesure chiffrée sur plusieurs valeurs de `k`.

### Premiere mesure locale top-k / juniors

Une premiere mesure locale de sanity check a ete lancee le **3 mai 2026** avec :

1. `1` offre demo active ;
2. `192` profils publics demo ;
3. `1` iteration par configuration ;
4. comparaison `k = 30`, `50`, `100`.

Resultat observe :

1. `k = 30` : `2336.2 ms/offre`, overlap top 5 de `60.0%` vs `k = 100`, part juniors top 5 de `40.0%`, offres avec junior top 5 de `100.0%`, top 1 junior de `0.0%` ;
2. `k = 50` : `2320.6 ms/offre`, overlap top 5 de `60.0%` vs `k = 100`, part juniors top 5 de `40.0%`, offres avec junior top 5 de `100.0%`, top 1 junior de `0.0%` ;
3. `k = 100` : `8455.4 ms/offre`, reference, part juniors top 5 de `40.0%`, offres avec junior top 5 de `100.0%`, top 1 junior de `0.0%`.

Interpretation prudente :

1. sur cette coupe locale tres reduite, la representation junior du top 5 ne varie pas entre `30`, `50` et `100` ;
2. le gain de temps reste important pour `30` et `50` ;
3. cette mesure est insuffisante pour clore le sujet fairness, car elle ne porte que sur `1` offre et ne capture pas encore une variabilite produit plus large.

### Mesure locale elargie top-k / juniors

Une seconde mesure locale a ensuite ete lancee le **3 mai 2026** sur une coupe un peu moins etroite avec :

1. `5` offres demo actives ;
2. `192` profils publics demo ;
3. `1` iteration par configuration ;
4. comparaison `k = 30`, `50`, `100`.

Resultat observe :

1. `k = 30` : `2504.0 ms/offre`, overlap top 5 de `100.0%` vs `k = 100`, same top 1 de `100.0%`, part juniors top 5 de `36.0%`, offres avec junior top 5 de `80.0%`, top 1 junior de `60.0%` ;
2. `k = 50` : `2314.8 ms/offre`, overlap top 5 de `100.0%` vs `k = 100`, same top 1 de `100.0%`, part juniors top 5 de `36.0%`, offres avec junior top 5 de `80.0%`, top 1 junior de `60.0%` ;
3. `k = 100` : `2392.6 ms/offre`, reference, part juniors top 5 de `36.0%`, offres avec junior top 5 de `80.0%`, top 1 junior de `60.0%`.

Interpretation pragmatique :

1. sur cette coupe de `5` offres, la representation junior reste strictement identique entre `30`, `50` et `100` ;
2. le top 5 et le top 1 restent egalement inchanges sur cette mesure ;
3. contrairement a la cloture Phase 2, cette coupe ne montre pas ici de gain temps massif quand `k` baisse ;
4. a ce stade, le signal utile pour la fairness est surtout l'absence de degradation visible de la representation junior sur ce sous-ensemble ;
5. la mesure reste locale et ne suffit pas encore a elle seule pour clore completement le point roadmap.

### Mesure locale top-k / juniors sur 10 offres

Une mesure un peu plus solide a ensuite ete lancee le **3 mai 2026** avec :

1. `10` offres demo actives ;
2. `192` profils publics demo ;
3. `1` iteration par configuration ;
4. comparaison `k = 30`, `50`, `100`.

Resultat observe :

1. `k = 30` : `2525.4 ms/offre`, overlap top 5 de `100.0%` vs `k = 100`, same top 1 de `100.0%`, part juniors top 5 de `58.0%`, offres avec junior top 5 de `90.0%`, top 1 junior de `80.0%` ;
2. `k = 50` : `2320.8 ms/offre`, overlap top 5 de `100.0%` vs `k = 100`, same top 1 de `100.0%`, part juniors top 5 de `58.0%`, offres avec junior top 5 de `90.0%`, top 1 junior de `80.0%` ;
3. `k = 100` : `2378.7 ms/offre`, reference, part juniors top 5 de `58.0%`, offres avec junior top 5 de `90.0%`, top 1 junior de `80.0%`.

Interpretation pragmatique :

1. sur cette coupe de `10` offres demo, la representation junior ne varie toujours pas entre `30`, `50` et `100` ;
2. le top 5 et le top 1 restent egalement identiques a la reference `k = 100` ;
3. pour ce sous-ensemble local, la reduction de `k` ne semble donc pas penaliser la presence des juniors dans les premiers rangs ;
4. le signal fairness devient plus rassurant qu'avec la coupe `1` offre, meme s'il reste borne a un dataset demo et a `1` iteration ;
5. au vu de cette mesure, le point roadmap "effet du retrieval top-k sur la representation des juniors" est maintenant beaucoup mieux documente, mais peut encore etre consolide si besoin par une campagne plus large.

### Mesure locale nettoyage / scores juniors

Une premiere mesure de l'effet du nettoyage runtime sur les scores juniors a ete generee le **3 mai 2026** dans `docs/matching-cleaning-junior-impact.json` avec :

1. dataset : `docs/matching-demo-dataset-v2-clean.json` ;
2. modele : `camembert-base` ;
3. projection : `ml/models/devspot-matching-projection.pt` ;
4. comparaison entre deux variantes de texte candidat :
   - `cleaned` : texte structure et nettoye aligne sur le preprocessor runtime ;
   - `legacy_raw` : construction plus brute proche de l'ancien schema `headline + bio + experiences brutes`.

Important : cette lecture porte sur l'agrégat des scores semantiques offre/candidat, pas sur un jugement humain direct ni sur un ranking live complet.

Resultat observe :

1. variante `cleaned` : score moyen junior `0.4437`, score moyen non-junior `0.4707`, ratio d'impact `0.9427`, taux de selection junior `0.0660`, taux de selection non-junior `0.0714`, ratio de selection `0.9247`, ecart moyen `-0.0270` ;
2. variante `legacy_raw` : score moyen junior `0.2425`, score moyen non-junior `0.2884`, ratio d'impact `0.8408`, taux de selection junior `0.0810`, taux de selection non-junior `0.1008`, ratio de selection `0.8027`, ecart moyen `-0.0459` ;
3. delta `cleaned - legacy_raw` : score moyen junior `+0.2012`, score moyen non-junior `+0.1823`, ratio d'impact `+0.1019`, ratio de selection `+0.1220`, ecart moyen `+0.0189`.

Interpretation pragmatique :

1. le nettoyage n'augmente pas seulement les scores des juniors ; il releve aussi ceux des non-juniors ;
2. cependant, l'effet est un peu plus favorable aux juniors en relatif, car le ratio d'impact passe de `0.8408` a `0.9427` ;
3. l'ecart moyen junior / non-junior se reduit nettement, de `-0.0459` a `-0.0270` ;
4. les taux de selection a seuil `0.8` baissent dans les deux groupes, ce qui suggere surtout un changement de distribution des scores plutot qu'une simple hausse uniforme ;
5. sur cette premiere lecture, le nettoyage runtime parait donc compatible avec l'objectif fairness junior, et meme plutot ameliorer l'equilibre relatif entre juniors et non-juniors.

### Mesure locale contrefactuelle / localisation et ecole

Une mesure contrefactuelle complementaire a ensuite ete generee le **3 mai 2026** dans `docs/matching-counterfactual-fairness.json` avec :

1. dataset : `docs/matching-demo-dataset-v2-clean.json` ;
2. modele : `camembert-base` ;
3. projection : `ml/models/devspot-matching-projection.pt` ;
4. comparaison profil original vs variantes contrefactuelles appliquees a tous les candidats :
   - `location` : bascule `remote <-> onsite` et `hybrid -> remote` ;
   - `school` : remplacement de `schoolName` par `Counterfactual School`.
   - `apparent_origin` : remplacement de `firstName/lastName` par `Aminata Diallo`.

Resultat observe :

1. variante `location` sur `baseline` : `98.86%` des paires offre/candidat changent, `63.33%` des top 1 changent, overlap moyen top 5 de `46.0%`, delta score moyen junior `-0.0125`, delta score moyen non-junior `-0.0062` ;
2. variante `location` sur `semantic` : aucun changement de score ni de ranking (`0.0%` de paires modifiees, `0.0%` de top 1 modifies, overlap top 5 `100.0%`) ;
3. variante `location` sur `enriched_proxy` : aucun changement de score ni de ranking (`0.0%` de paires modifiees, `0.0%` de top 1 modifies, overlap top 5 `100.0%`) ;
4. variante `school` : aucun changement observe sur `baseline`, `semantic` et `enriched_proxy` (`0.0%` de paires modifiees, `0.0%` de top 1 modifies, overlap top 5 `100.0%`) ;
5. variante `apparent_origin` : aucun changement observe sur `baseline`, `semantic` et `enriched_proxy` (`0.0%` de paires modifiees, `0.0%` de top 1 modifies, overlap top 5 `100.0%`) ;
6. sur cette coupe, l'ecart junior / non-junior reste nul pour `school` et `apparent_origin`, et nul aussi pour `location` des que l'on sort du baseline offline.

Interpretation pragmatique :

1. la sensibilite a la localisation est aujourd'hui concentree dans le baseline offline, via le `location_score`, pas dans le pipeline semantique nettoye ni dans le score enrichi ;
2. l'ecole n'influence plus le matching mesure ici, ce qui est coherent avec le fait que `schoolName` n'est pas injecte dans le texte candidat nettoye ;
3. l'origine apparente n'influence pas non plus le matching mesure ici, ce qui est coherent avec le fait que `firstName/lastName` ne sont pas injectes dans le texte candidat nettoye ni dans le baseline offline ;
4. cette mesure ferme donc pratiquement les axes `ecole`, `origine apparente` et `localisation` pour le protocole actuel ;
5. si l'on veut aligner strictement le baseline offline avec le runtime actuel, le prochain arbitrage n'est plus sur ces axes fairness mais plutot sur la place a donner au `location_score` dans ce protocole d'evaluation.

### Support poster / avant-apres Phase 4

Une synthese plus compacte et exploitable en poster a ete ajoutee dans `docs/matching-phase4-before-after.md` pour regrouper :

1. le contraste `legacy_raw` vs `cleaned` sur les scores juniors ;
2. la stabilite du top-k sur la representation junior ;
3. l'effet contrefactuel nul sur `ecole` et `origine apparente`, et concentre sur le baseline offline pour `localisation`.

Un support encore plus court, calibre poster avec un tableau principal unique puis des blocs `protocole`, `metriques`, `limites` et `perspectives`, a aussi ete ajoute dans `docs/matching-phase4-poster-summary.md`.

### Critere de fin

La fairness est mieux documentee, plus mesurable, et reste compatible avec un positionnement produit qui continue de soutenir les juniors.

---

## Ordre de mise en oeuvre recommande

1. Phase 1 - Nettoyage runtime
2. Phase 2 - Optimisation retrieval + reranking
3. Phase 3 - Pertinence
4. Phase 4 - Fairness

Cet ordre est le plus logique car :

1. il stabilise d'abord la qualite des entrees ;
2. il reduit ensuite le cout du calcul ;
3. il permet ensuite d'evaluer la pertinence sur un pipeline plus propre ;
4. il rend enfin la fairness plus interpretable.

---

## Premier lot concret a lancer

Le prochain sprint devrait se limiter a ceci :

1. introduire `CandidateTextPreprocessor`
2. brancher ce preprocessor dans le matching
3. ajouter les tests unitaires de nettoyage
4. documenter les regles de nettoyage
5. preparer ensuite le chantier embeddings pre-calcules

Ce premier lot est suffisamment petit pour etre faisable rapidement, tout en reduisant deja un ecart methodologique reel.
