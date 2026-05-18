# Analyse detaillee de la methodologie de matching DevSpot

## Objet du document

Ce document compare :

1. la methodologie cible initialement envisagee,
2. le pipeline effectivement implemente dans le depot,
3. les ecarts entre les deux,
4. ce qui peut encore etre modifie si l'objectif est de revenir vers une version plus rigoureuse du point de vue IA, cybersecurite, fairness et memoire de recherche.

L'objectif n'est pas de dire que le systeme actuel est "mauvais".
Le systeme actuel est plutot un **MVP hybride pragmatique**.
En revanche, il ne correspond pas exactement au pipeline theorique "anonymisation forte -> vectorisation neutre -> matching semantique equite-aware" tel qu'il avait ete formule au depart.

---

## 1. Methodologie cible de depart

La version de reference que vous aviez en tete peut etre reformulee ainsi :

### 1.1 Pipeline technique cible

1. **Anonymisation forte du CV**
   - transformation du CV brut en profil neutre ;
   - suppression des donnees identifiantes ;
   - retrait des noms, prenoms, emails, telephones, adresses, ecoles et autres indices susceptibles d'introduire des biais ;
   - anonymisation ideale via NER, donc detection contextuelle des entites.

2. **Vectorisation du CV anonymise**
   - encodage semantique du texte neutre ;
   - production d'un embedding dense ;
   - hypothese initiale evoquee : vecteurs en 1536 dimensions.

3. **Appariement vectoriel**
   - comparaison entre l'offre et le CV via similarite cosinus ;
   - classement des profils sur la proximite semantique ;
   - logique de matching principalement fondee sur les vecteurs.

### 1.2 Protocole experimental cible

1. **Corpus**
   - dataset synthetique ;
   - pas de donnees personnelles reelles ;
   - objectif : eviter les contraintes RGPD et simuler des cas complexes.

2. **Test A/B**
   - Systeme A : baseline par mots-cles ;
   - Systeme B : pipeline IA complet avec anonymisation + vecteurs + enrichissements.

3. **KPIs cibles**
   - pertinence par notation humaine ou experte ;
   - equite sur plusieurs axes de biais ;
   - reduction de l'asymetrie d'information, notamment sur les competences implicites ou transversales.

---

## 2. Ce qui est reellement implemente aujourd'hui

## 2.1 Vue d'ensemble

Le systeme actuel n'est pas un pipeline "anonymisation pure puis matching vectoriel pur".
C'est un systeme **hybride en trois couches** :

1. une **baseline PHP** par overlap de competences ;
2. un **score semantique CamemBERT** ;
3. un **score enrichi** qui ajoute un bonus d'inference de competences implicites.

Le point important est le suivant :

- le classement final ne repose pas sur le seul score CamemBERT ;
- l'anonymisation existe, mais elle est partielle ;
- les identites des candidats restent visibles cote recruteur ;
- l'evaluation actuelle repose surtout sur des metriques offline de ranking, pas sur une notation experte 1 a 5.

## 2.2 Pipeline applicatif reel

Le pipeline runtime, en simplifiant, est aujourd'hui :

1. construction d'un texte candidat a partir du profil ;
2. anonymisation partielle de ce texte ;
3. extraction de competences explicites ;
4. calcul d'un score baseline par overlap ;
5. calcul d'un score semantique CamemBERT ;
6. inference de competences implicites par regles/regex ;
7. ajout d'un bonus d'enrichissement ;
8. classement final par score enrichi, puis score semantique, puis baseline.

Autrement dit, la logique actuelle est :

`baseline metier + semantique CamemBERT + enrichissement heuristique`

et non :

`anonymisation forte + embeddings seuls + matching purement vectoriel`

---

## 3. Comparaison detaillee entre la cible et l'existant

## 3.1 Etape 1 - Anonymisation

### Cible initiale

La cible etait une anonymisation forte du profil :

- suppression des PII ;
- retrait des noms et prenoms ;
- neutralisation des indices de biais ;
- si possible, mise en oeuvre via NER ;
- identite revelee seulement apres decision de contact.

### Realite actuelle

Depuis la phase 1 d'anonymisation implemente dans l'interface recruteur, la situation a evolue.

L'anonymisation reste **regex-based**, mais elle est desormais plus large :

- le texte candidat envoye a l'IA n'est plus prefixe par `firstName` et `lastName` ;
- l'email, le telephone et l'adresse postale sont masques ;
- le slug public et les URLs de portfolio / GitHub / LinkedIn sont retires ;
- les liens bruts eventuels presents dans les champs texte sont neutralises.

La logique actuelle correspond donc a une **de-identification applicative utile**, superieure au simple masquage PII minimal, mais encore inferieure a une anonymisation forte complete.

### Ce qui a change concretement

1. **Le prenom et le nom ne sont plus injectes dans le texte de matching**
   - le texte utilise pour l'IA est construit sans prefixe nominatif ;
   - on a donc supprime la fuite la plus directe vers l'embedding.

2. **L'anonymiseur masque davantage que les trois PII historiques**
   - il couvre maintenant aussi le slug et les URLs publiques ;
   - cela reduit les re-identifications triviales par lien ou handle.

3. **Le recruteur ne voit plus l'identite au premier affichage du matching**
   - l'interface recoit une carte anonyme avec `candidateLabel`, scores, experience et competences ;
   - `fullName`, `headline`, `profileUrl`, `developerId` et favoris sont caches tant qu'aucune conversation n'existe.

4. **La revelation est maintenant conditionnelle**
   - l'identite est revelee apres contact reussi ;
   - ou immediatement si une conversation recruteur ↔ candidat existe deja.

### Consequence methodologique

On ne peut toujours pas dire aujourd'hui que :

- le matching est calcule sur un CV pleinement neutre au sens recherche ;
- l'anonymisation couvre de maniere fiable les ecoles, entreprises, villes ou marqueurs sociaux ;
- le systeme prouve une reduction generale des biais identitaires.

En revanche, on peut maintenant dire que :

- le texte de matching passe a l'IA ne contient plus les identifiants directs les plus evidents ;
- l'interface de matching recruteur masque bien l'identite jusqu'au contact ;
- la plateforme implemente une vraie **anonymisation de premiere intention** et non plus seulement un masquage PII symbolique.

### Ce qu'on peut encore changer

#### Ce qui a deja ete corrige

1. retrait de `firstName` et `lastName` du texte de matching ;
2. masquage par defaut de `fullName`, `headline` et `profileUrl` dans la vue recruteur initiale ;
3. affichage d'un identifiant neutre du type `Candidat #3` ;
4. revelation de l'identite au moment du contact ou si une conversation existe deja.

#### Niveau intermediaire

1. ajouter le masquage des noms d'ecole, de certaines entreprises et de certains marqueurs geographiques ;
2. separer deux representations :
   - `candidate_matching_text` neutre pour l'IA,
   - `candidate_display_profile` riche pour l'interface apres debloquage.
3. mesurer l'impact metrique du passage "texte non anonymise" -> "texte anonymise".

#### Niveau recherche / cyber

1. remplacer les regex par un vrai composant NER ;
2. tester une chaine du type :
   - detecteur NER francophone,
   - politique de masquage,
   - journal d'audit des entites masquees ;
3. comparer precision / rappel du masquage ;
4. documenter les risques de fuite d'identite residuelle.

### Remarque sur "avec CamemBERT si c'est le plus optimal"

Techniquement, CamemBERT peut servir de base a une tache NER, mais dans l'etat actuel du repo, il n'est **pas du tout** utilise comme NER.

Si l'objectif est purement operationnel, le plus pragmatique serait souvent :

- un modele NER francophone specialise,
- ou un moteur type Presidio/spaCy avec extension FR,
- ou un fine-tuning specifique NER si vous voulez un angle recherche plus fort.

Donc :

- **oui**, un modele base CamemBERT peut etre pertinent pour NER ;
- **non**, ce n'est pas ce qui est implemente aujourd'hui ;
- **non**, on ne peut pas affirmer actuellement que "CamemBERT anonymise le CV".

---

## 3.2 Etape 2 - Nettoyage et normalisation des donnees

### Cible initiale implicite

Avant anonymisation et vectorisation, il etait logique d'imaginer un nettoyage global :

- harmonisation des textes ;
- suppression de bruit ;
- dedoublonnage ;
- correction de variantes lexicales ;
- normalisation des champs metier.

### Realite actuelle

Il faut distinguer **runtime** et **offline**.

#### Runtime applicatif

La phase 1 de nettoyage runtime est maintenant terminee au sens du critere retenu dans la roadmap.

Le matching live ne part plus d'un simple texte concatene "headline + bio + experiences brutes".

Il passe par un composant dedie de type `CandidateTextPreprocessor` qui :

- construit un texte candidat **structure** ;
- normalise les espaces et le bruit de surface ;
- harmonise plusieurs variantes frequentes de technologies et de postes ;
- dedoublonne des fragments repetes ;
- retire une partie du bruit identitaire inutile avant anonymisation ;
- omet les noms d'entreprise et d'ecole du texte envoye au matching.

Le texte runtime utilise maintenant des sections stables du type :

- `Headline`
- `Summary`
- `Target roles`
- `Core skills`
- `Soft skills`
- `Experience`
- `Education`

On a donc depasse la simple "normalisation legere".

Le point important est que le **texte candidat de matching offline** suit maintenant le meme contrat fonctionnel :

- memes sections ;
- memes omissions d'identite indirecte ;
- meme logique de debruitage ;
- meme ordre de construction ;
- memes cas de non-regression sur un fixture bruite partage.

Il reste des limites, mais elles ne bloquent plus la cloture de la phase 1 :

- la table de canonicalisation reste partielle ;
- le nettoyage dataset global contient encore des heuristiques propres a l'offline ;
- il n'existe pas encore de journal explicite des transformations appliquees.

#### Offline / preparation dataset

En revanche, il existe un vrai pipeline de nettoyage pour le dataset experimental :

- corrections textuelles ;
- canonicalisation de skills, technologies et positions ;
- dedoublonnage ;
- remplissage de bio manquante ;
- reequilibrage de seniorite ;
- reequilibrage d'experience cote offres.

### Ce qui a change

La logique de nettoyage n'est donc plus uniquement deplacee vers les scripts offline.
Elle a ete recentralisee autour d'un contrat runtime devenu la reference du texte candidat de matching.

Autrement dit :

- le **dataset de recherche** est nettoye ;
- le **matching live** et le **texte candidat offline de matching** sont maintenant alignes sur une meme logique utile de construction.

### Consequence methodologique

On peut dire :

- "nous avons un dataset synthetique nettoye pour l'experimentation",

mais pas encore :

- "l'ensemble du pipeline offline et runtime est parfaitement factorise, exhaustif et trace".

### Ce qu'on peut encore changer

1. etendre la canonicalisation a d'autres variantes visibles dans le dataset ;
2. factoriser davantage les regles communes entre PHP runtime et scripts Python ;
3. journaliser les transformations appliquees ;
4. mesurer l'impact concret de ce nettoyage sur le top 5 et sur les embeddings.

### Nettoyage runtime recommande

Si l'objectif est de faire disparaitre l'ecart moyen sur le nettoyage, il faut ajouter une vraie etape runtime explicite, en amont de l'anonymisation et de l'embedding.

La chaine recommande serait :

1. **normalisation de base**
   - Unicode NFKC ;
   - normalisation des espaces ;
   - suppression des artefacts HTML ou ponctuations parasites.

2. **normalisation metier**
   - harmonisation des technologies :
     - `react.js` -> `react`,
     - `node` -> `node.js`,
     - `postgres` -> `postgresql`,
     - `ts` -> `typescript` ;
   - harmonisation des variantes de postes :
     - `dev backend` -> `developpeur backend`,
     - `full-stack` -> `full stack`.

3. **dedoublonnage**
   - suppression des repetitions entre :
     - skills declares,
     - technologies d'experience,
     - bio,
     - headline ;
   - dedoublonnage insensible a la casse.

4. **construction d'un texte stable**
   - ordre fixe des blocs :
     - headline,
     - bio,
     - experiences,
     - education,
     - skills ;
   - labels textuels homogenes ;
   - texte deterministe pour stabiliser le cache.

5. **post-traitement avant IA**
   - passage dans l'anonymiseur ;
   - emission d'un `candidate_matching_text` unique et propre.

### Pourquoi ce nettoyage est important

Sans cette etape runtime, trois problemes persistent :

1. deux profils semantiquement proches peuvent produire des textes de qualite tres differente ;
2. le cache d'embeddings est moins stable car de petites variations textuelles changent le hash ;
3. l'evaluation offline et le matching live ne reposent pas sur exactement la meme qualite de donnees.

### Etat d'avancement

La phase 1 est maintenant cloturee dans son perimetre initial :

1. un preprocesseur runtime dedie existe ;
2. il est branche dans `OfferMatchingService` ;
3. le texte offline de matching suit maintenant le meme contrat fonctionnel ;
4. des tests PHP et Python couvrent la canonicalisation, le dedoublonnage, les omissions d'identite indirecte et la stabilite du texte.

Les ameliorations restantes relevent plutot du raffinement :

1. enrichir la table de normalisation ;
2. mieux factoriser les regles communes ;
3. mesurer l'effet sur le ranking ;
4. preparer la phase 2 sur l'optimisation du matching.

### Ce qui a ete mis en oeuvre

Le choix pragmatique finalement retenu a ete :

1. creer un service `CandidateTextPreprocessor` ;
2. lui faire produire un texte candidat structure avant anonymisation ;
3. aligner `build_candidate_text()` cote scripts offline sur cette meme logique ;
4. couvrir ce contrat par des tests de non-regression PHP et Python.

---

## 3.3 Etape 3 - Vectorisation

### Cible initiale

La cible mentionnee etait :

- vectorisation du CV anonymise ;
- embedding de 1536 dimensions.

### Realite actuelle

Le systeme utilise **CamemBERT base** avec :

- tokenizer HuggingFace ;
- `AutoModel` ;
- mean pooling ;
- normalisation L2 ;
- projection lineaire optionnelle.

La dimension native est **768**, pas 1536.

La specialisation actuelle n'est pas un fine-tuning complet du modele.
C'est une **projection lineaire 768 x 768** apprise au-dessus d'embeddings CamemBERT geles.

### Ce qui a change

Le projet a pris une direction plus locale, plus francaise et plus maitrisable :

1. choix de `camembert-base` pour un corpus francophone ;
2. abandon implicite d'une logique 1536 dims type embeddings externes ;
3. ajout d'une projection legere pour specialiser l'espace vectoriel sans cout massif.

### Pourquoi ce changement est coherent

Ce changement est defendable techniquement :

- CamemBERT est plus logique pour du francais ;
- 1536 dimensions ne sont pas un objectif en soi ;
- la qualite d'un embedding depend plus du modele et de l'adaptation au domaine que du nombre brut de dimensions ;
- une inference locale evite des dependances externes et des questions de souverainete des donnees.

### Ce qu'on ne peut pas dire

On ne peut pas dire aujourd'hui :

- "nous encodons en 1536 dimensions" ;
- "nous avons fine-tune CamemBERT de bout en bout" ;
- "la vectorisation repose sur un modele anonymisation-aware".

### Ce qu'on peut encore changer

#### Option 1 - Garder l'architecture actuelle et la durcir

1. conserver CamemBERT ;
2. garder la projection lineaire ;
3. mieux evaluer sur des cas plus difficiles ;
4. aligner runtime et offline sur les memes longueurs de sequence.

#### Option 2 - Passer a une specialisation plus forte

1. fine-tuning supervise du bi-encoder ;
2. apprentissage contrastif offre/candidat ;
3. creation d'un modele sentence-transformer francophone specialise recrutement tech.

#### Option 3 - Changer de modele d'embedding

1. passer a un modele plus recent si besoin ;
2. tester une architecture plus adaptee au retrieval ;
3. envisager une dimension differente si elle vient naturellement du modele.

Important :
si vous passez a 1536 dimensions uniquement "pour coller a la methode ecrite", ce n'est pas un gain scientifique.
La bonne formulation est :

- "nous avons retenu un modele francophone localement heberge, produisant des embeddings de 768 dimensions, puis une adaptation lineaire au domaine".

Cette formulation est plus honnete et plus solide.

---

## 3.4 Etape 4 - Appariement

### Cible initiale

La cible etait un matching fonde sur :

- stockage vectoriel ;
- similarite cosinus ;
- recherche par proximite entre offre et profil.

### Realite actuelle

Il y a bien une composante cosinus.
En revanche, il n'y a pas aujourd'hui de vraie couche de **vector database** ou d'index ANN dedie.

Le fonctionnement actuel est plutot :

1. generation des embeddings a la demande ;
2. cache applicatif sur les appels d'embedding ;
3. score cosinus ;
4. re-etalonnage du score ;
5. combinaison avec d'autres signaux.

### Ce qui a change

Le matching actuel est un **matching hybride reranque** et non un simple moteur de recherche vectorielle.

Le classement final utilise :

1. score enrichi ;
2. puis score semantique ;
3. puis score baseline.

Donc le systeme final :

- n'est pas purement vectoriel ;
- n'est pas un moteur ANN ;
- n'est pas un nearest-neighbor store industrialise.

### Consequence methodologique

On peut dire :

- "nous utilisons la similarite cosinus sur embeddings CamemBERT",

mais il faut eviter de dire :

- "nous avons un moteur de recherche vectorielle complet",

si par la on entend :

- index dedie ;
- pre-calcul des embeddings ;
- retrieval haute performance ;
- top-k approxime a grande echelle.

### Ce qu'on peut encore changer

#### Version pragmatique

1. pre-calculer les embeddings candidats ;
2. les stocker en base ou dans un magasin dedie ;
3. recalculer seulement a la mise a jour du profil ;
4. faire le matching offre -> top-k via vecteurs preexistants.

#### Version plus industrialisee

1. ajouter une base vectorielle ou un index ANN ;
2. separer retrieval et reranking ;
3. faire :
   - retrieval vectoriel rapide ;
   - reranking enrichi sur le top N.

Cette architecture serait beaucoup plus propre si le nombre de profils monte.

### Optimisation recommandee a court terme

Il faut bien distinguer deux idees :

1. **ne plus reranker tous les profils** ;
2. **ne pas couper arbitrairement a 30 ou 50 sans etape de retrieval**.

Dire "on ne calcule le matching que sur les 30 meilleurs profils" n'est valide que si l'on dispose d'une premiere phase qui produit deja un **pool candidat raisonnable**.

La bonne architecture cible est donc :

1. **preselection**
   - filtrage par profils publics ;
   - filtrage metier minimal :
     - role,
     - experience,
     - localisation si necessaire ;
   - eventuellement score baseline rapide.

2. **retrieval**
   - embeddings candidats pre-calcules ;
   - similarite cosinus offre -> candidats ;
   - recuperation d'un top-k initial, par exemple :
     - top 50,
     - top 100.

3. **reranking enrichi**
   - calcul complet seulement sur ce sous-ensemble ;
   - bonus d'inference ;
   - explications et tri final.

### Pourquoi cette strategie est meilleure

Elle permet de :

1. reduire fortement le temps de calcul du premier matching ;
2. garder la qualite du score final sur les profils les plus prometteurs ;
3. separer proprement :
   - le **retrieval rapide**,
   - le **reranking metier plus cher**.

### Version realiste pour DevSpot

Sans introduire tout de suite une vraie base vectorielle, la trajectoire la plus raisonnable serait :

1. pre-calculer les embeddings des profils publics ;
2. les recalculer uniquement quand le profil change ;
3. lancer un retrieval sur tous les embeddings disponibles ;
4. ne faire le score enrichi complet que sur un top 50 ou top 100 ;
5. garder le score junior-aware actuel dans la phase de reranking enrichi.

### Etat actuel apres le premier increment de phase 2

Une premiere optimisation pragmatique a d'abord ete mise en place :

1. le **score baseline** continue d'etre calcule sur l'ensemble des profils publics ;
2. le **score semantique** et le **score enrichi** ne sont plus calcules que sur un **top-k baseline** ;
3. la valeur par defaut du top-k est actuellement **50** ;
4. les profils hors top-k restent visibles dans les resultats, mais sans score semantique ni enrichi.

Cette etape etait utile car elle reduisait deja le cout du reranking le plus cher, tout en gardant le comportement produit actuel.

À ce stade du premier increment, il ne fallait toutefois pas la confondre avec l'architecture cible :

1. il n'existait pas encore de **stock d'embeddings candidats pre-calcules** ;
2. il n'y avait pas encore de **retrieval vectoriel dedie** avant reranking ;
3. la phase 2 etait donc encore **en cours**, pas terminee.

### Etat actuel apres le deuxieme increment de phase 2

Le pipeline a ensuite ete renforce avec un stockage persistant des embeddings candidats :

1. les profils developpeur peuvent maintenant conserver un **embedding de matching** en base ;
2. cet embedding est associe a un **hash du texte candidat nettoye** ;
3. si le texte de matching change, l'embedding est considere comme obsolete et recalcule ;
4. une commande de backfill permet de reconstruire ce stock hors ligne ;
5. le scoring semantique du reranking reutilise desormais ce stock quand il est disponible.

Ce point reduisait deja une partie du cout recurrent lie au matching semantique, mais il ne cloturait pas encore la phase 2, car :

1. la **preselection initiale** reposait toujours sur la baseline ;
2. le **retrieval vectoriel** exploitant directement les embeddings stockes n'etait pas encore en place ;
3. la comparaison formelle des valeurs de top-k et des gains de performance reste a faire.

### Etat actuel apres le troisieme increment de phase 2

Le retrieval vectoriel est maintenant actif dans la preselection du reranking :

1. les embeddings candidats stockes sont compares a l'embedding de l'offre ;
2. la selection du sous-ensemble reranke ne depend plus uniquement de la baseline ;
3. un complement baseline reste possible pour ne pas exclure brutalement les profils sans embedding frais ;
4. le refresh des embeddings a ete borne en petits batchs afin de limiter les timeouts du service IA.

On peut donc dire que la cible technique principale de la phase 2 est maintenant atteinte :

1. stock d'embeddings candidats ;
2. preselection vectorielle ;
3. reranking enrichi sur sous-ensemble.

Il reste toutefois un petit lot de cloture avant de passer proprement a la phase 3 :

1. fixer ou comparer une ou deux valeurs de `k` ;
2. mesurer sommairement le gain de temps ;
3. consigner ces chiffres dans la documentation.

### Mesure locale de cloture de phase 2

Une mesure locale a ensuite ete realisee pour fermer la phase 2 de maniere raisonnable, sans pretendre a un benchmark de production complet.

Configuration mesuree :

1. date de mesure : **28 avril 2026** ;
2. jeu local : `1` offre demo active ;
3. pool candidat : `192` profils publics demo ;
4. embeddings deja stockes : `101` profils demo ;
5. comparaison : `k = 30`, `50`, `100` ;
6. `1` iteration par configuration.

Resultats observes :

1. `k = 30` : `2321.1 ms/offre`, overlap top 5 de `20.0%` vs `k = 100` ;
2. `k = 50` : `7878.6 ms/offre`, overlap top 5 de `60.0%` vs `k = 100` ;
3. `k = 100` : `36354.0 ms/offre`, configuration de reference.

Lecture de ces chiffres :

1. la reduction de `k` diminue tres fortement le temps de calcul ;
2. un `k` trop bas degrade sensiblement le top 5 ;
3. `k = 50` apparait comme un compromis local plausible ;
4. la mesure reste **indicative**, car elle porte sur un echantillon local restreint et un stock d'embeddings encore partiel.

Malgre cette limite, l'objectif de phase 2 peut etre considere comme atteint :

1. embeddings candidats stockes ;
2. retrieval vectoriel actif ;
3. reranking enrichi borne ;
4. premiere mesure locale documentee.

### Impact sur la suite

Cette optimisation est importante non seulement pour la performance, mais aussi pour la suite du projet :

1. elle rend le pipeline plus propre scientifiquement ;
2. elle prepare un protocole plus solide pour evaluer la pertinence ;
3. elle n'oblige pas a supprimer l'indication ou le traitement des juniors ;
4. elle prepare mieux une future etape fairness / pertinence.

---

## 3.5 Etape 5 - Score final et logique metier

### Cible initiale

Dans l'idee initiale, le matching devait etre principalement :

- anonymisation ;
- vecteurs ;
- cosinus ;
- classement.

### Realite actuelle

Le systeme a ajoute deux briques majeures :

1. une **baseline explicable** par overlap ;
2. une **inference de competences implicites** par regles/regex.

Le score final du produit est donc plus riche, mais aussi plus eloigne du schema initial.

### Ce qui a change

Le matching a bascule d'un pipeline purement semantique vers un pipeline **hybride metier + IA**.

Cette evolution est tres comprensible :

- elle augmente l'explicabilite ;
- elle rassure pour un MVP ;
- elle donne un levier specifique pour mieux valoriser les juniors ;
- elle rend l'interface plus demonstrable.

### Limite importante

L'inference de competences implicites n'est pas aujourd'hui realisee par CamemBERT.
Elle repose surtout sur des patterns et des heuristiques.

Donc la bonne formulation est :

- "CamemBERT pour la proximite semantique",
- "regles d'inference pour les competences implicites",
- "bonus enrichi pour le classement final".

Ce n'est pas :

- "CamemBERT fait l'encodage puis CamemBERT fait tout le matching enrichi".

### Ce qu'on peut encore changer

1. conserver l'hybride mais mieux le formaliser ;
2. transformer l'inference regex en vrai classifieur multi-label ;
3. separer strictement :
   - retrieval semantique,
   - enrichment metier,
   - reranking final.

---

## 4. Protocole experimental - cible versus realite

## 4.1 Le corpus

### Cible initiale

Le corpus devait etre :

- synthetique ;
- RGPD-safe ;
- construit pour simuler des cas realistes et des situations de biais.

### Realite actuelle

Le depot contient bien un corpus de demonstration / experimentation nettoye.
Il est de type **synthetique ou synthetise** et sert clairement a l'experimentation offline.

Le corpus propre mentionne dans la documentation contient :

- 90 offres ;
- 192 profils developpeurs.

### Ce qui a change

La direction generale est restee proche de la cible :

- jeu de donnees maitrise ;
- nettoyage offline ;
- experimentation reproductible.

Le vrai ecart vient surtout de la **labellisation** :

- elle est faiblement supervisee ;
- elle n'est pas une annotation humaine exhaustive ;
- elle est generee par heuristiques metier.

### Consequence methodologique

On peut dire :

- "le corpus est maitrise, nettoye, exploitable et compatible avec une experimentation RGPD prudente",

mais il faut aussi dire :

- "les labels de pertinence ne sont pas totalement independants d'heuristiques metier".

---

## 4.2 Le test A/B

### Cible initiale

Le schema de preuve cible etait :

- Systeme A = mots-cles ;
- Systeme B = anonymisation + vecteurs + enrichissements.

### Realite actuelle

Une comparaison existe bien, mais elle est plus complexe que ce schema binaire.

Aujourd'hui, vous avez en pratique un protocole offline **A/B/C** :

1. **Baseline**
   - score lexical / metier offline ;
   - base sur les mots-cles, familles de role et adequation d'experience ;
   - ne reutilise pas directement le label faible `weak_relevance()`.

2. **Semantique brut**
   - CamemBERT + cosinus ;
   - avec projection optionnelle.

3. **Enrichi proxy offline**
   - score semantique + bonus borne sur competences proches ou implicites ;
   - proxy documentaire du score enrichi runtime, pas reproduction exacte du service live.

Le script `scripts/evaluate_matching_model.py` peut maintenant produire ces trois variantes dans un meme rapport JSON.

### Ce qui manque encore

Ce qui manque par rapport a un vrai protocole A/B robuste :

1. un protocole humain d'evaluation a l'aveugle ;
2. un panel d'experts qui note les resultats ;
3. un jeu de test separe et dur ;
4. une mesure specifique de l'impact de l'anonymisation seule.

### Premier increment Phase 3

Un premier increment de phase 3 est en place :

1. les trois variantes `baseline`, `semantic`, `enriched_proxy` sont comparables offline ;
2. les labels faibles restent separes des scores testes ;
3. un pack de revue humaine anonymisee peut etre genere ;
4. les offres de revue sont choisies en priorite quand les methodes divergent dans leur top 5.

Ce qui a ete fait ensuite :

1. le pack a ete note humainement sur une echelle `1-5` ;
2. les notes ont ete synthetisees dans `docs/matching-human-review-summary.md` ;
3. les commentaires humains ont ete utilises pour identifier les ecarts metier.

Ce qui est considere comme hors perimetre de cloture Phase 3 :

1. ajouter une variante supplementaire :
   - D = semantique sur texte non anonymise,
   - E = semantique sur texte anonymise,
   pour mesurer l'effet reel de l'anonymisation ;
2. transformer les enseignements en ajustements du score enrichi.

---

## 4.3 Les metriques de pertinence

### Cible initiale

Le KPI cible etait :

- un score de pertinence 1 a 5 attribue par un expert.

### Realite actuelle

Les metriques principales du depot sont :

- Recall@1 ;
- Recall@3 ;
- Recall@5 ;
- MRR ;
- nDCG@5.

Ces metriques sont calculees offline a partir de labels heuristiques, pas de notes expertes humaines 1 a 5.

Depuis le premier increment de phase 3, ces metriques peuvent etre calculees par methode :

- `baseline` ;
- `semantic` ;
- `enriched_proxy`.

Le label faible reste `weak_relevance()`, mais il sert seulement a evaluer les classements. Il ne doit pas etre confondu avec un score produit ni avec une annotation humaine.

### Mesure Phase 3 du 3 mai 2026

Le rapport `docs/matching-eval-phase3.json` compare les trois methodes sur `90` offres et `192` profils.

| Methode | Recall@1 | Recall@3 | Recall@5 | MRR | nDCG@5 |
|---|---:|---:|---:|---:|---:|
| baseline | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |
| semantic | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |
| enriched_proxy | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |

Cette mesure est utile mais elle montre surtout une limite : les labels faibles sont trop faciles sur ce dataset. Le fait que les trois methodes atteignent `1.0000` ne prouve pas que les methodes sont equivalentes ; cela signifie que le protocole heuristique ne discrimine plus assez les tops 5.

La suite methodologiquement correcte est donc d'utiliser un pack interne de revue humaine contenant des offres selectionnees par fort desaccord entre methodes, puis de noter manuellement les candidats sur une echelle `1-5`.

### Revue humaine Phase 3

La revue humaine contient :

- `10` offres ;
- `50` candidats proposes ;
- `49` notes exploitables ;
- `1` candidat exclu explicitement du calcul : Offre 5 / Candidat D.

Synthese des resultats :

| Methode | Correlation avec note humaine | Note humaine moyenne du top 1 choisi | Top humain retrouve |
|---|---:|---:|---:|
| baseline | 0.540 | 4.13 / 5 | 5 / 10 |
| semantic | -0.441 | 3.25 / 5 | 1 / 10 |
| enriched_proxy | -0.404 | 3.85 / 5 | 1 / 10 |

Lecture :

- la baseline offline est la plus proche du jugement humain sur cet echantillon difficile ;
- le semantique brut est trop permissif sur des profils partageant le vocabulaire de qualite, livraison ou collaboration ;
- `enriched_proxy` remonte des profils parfois utiles mais amplifie aussi des faux positifs QA / DevOps sur des offres developpeur ;
- l'experience cible, le mode de travail et la famille metier ressortent comme criteres humains importants.

### Ce que cela change

Le projet n'est plus uniquement dans une **evaluation IR / ranking** heuristique.

Il dispose maintenant d'une premiere lecture humaine qualitative, ce qui reduit l'ecart initial sur la pertinence.
Cette preuve reste limitee, car l'echantillon est petit et un candidat est exclu du calcul, mais elle est beaucoup plus informative que les metriques faibles saturees.

Une consequence directe a ete appliquee en Phase 4 : le score avance conserve CamemBERT, mais ajoute un garde-fou de famille metier. Quand l'offre et le candidat sont hors famille compatible, le bonus enrichi est neutralise et le score enrichi est plafonne pour limiter les faux positifs QA / DevOps / mobile observes dans la revue humaine.

### Ce qu'on peut encore changer

1. refaire une revue humaine sur un second pack apres garde-fou de famille metier ;
2. faire peser plus clairement le mode de travail et l'experience cible ;
3. calibrer ensuite le bonus junior sur les cas vraiment compatibles.

La combinaison des deux est beaucoup plus solide pour un memoire.

---

## 4.4 Les metriques d'equite

### Cible initiale

La cible evoquait une fairness sur :

- l'age,
- l'origine,
- l'ecole,
- les biais introduits par l'identite ou le parcours.

### Realite actuelle

La fairness implementee mesure surtout :

- **junior vs non-junior** ;
- sur la base des annees d'experience ;
- avec moyenne des scores, `disparate_impact_ratio`, effectifs, taux de selection et ecart moyen.

Depuis le premier increment Phase 4, `FairnessAuditor` expose aussi :

- `junior_count` ;
- `non_junior_count` ;
- `junior_selection_rate` ;
- `non_junior_selection_rate` ;
- `selection_rate_ratio` ;
- `score_gap` ;
- `assessment`.

### Ce qui a change

L'equite a ete reduite a une dimension beaucoup plus simple :

- experience / seniorite,

et non :

- biais nom/prenom,
- biais ecole,
- biais geographiques,
- biais d'origine apparente,
- biais de genre.

### Consequence methodologique

On ne peut pas dire actuellement :

- "notre anonymisation prouve une reduction des biais d'age, d'origine ou d'ecole".

On peut dire plus modestement :

- "nous auditons un biais potentiel junior / non-junior".

Ce que l'on peut ajouter depuis Phase 4 :

- "nous distinguons maintenant l'aide aux juniors d'une preuve de fairness generale" ;
- "nous mesurons aussi si les juniors sont moins souvent selectionnes au-dessus d'un seuil de score favorable" ;
- "nous avons un premier test contrefactuel verifiant que deux CV identiques sauf identite directe donnent le meme texte anonymise".

### Ce qu'on peut encore changer

#### Minimum credible

1. [x] ajouter un test "avant anonymisation / apres anonymisation" sur les noms ;
2. [x] injecter des profils synthetiques differant seulement par identite directe ;
3. [ ] mesurer si le ranking varie sur des profils contrefactuels complets.

#### Niveau intermediaire

1. creer des couples de profils quasi identiques avec :
   - noms differents,
   - ecoles differentes,
   - villes differentes ;
2. comparer les variations de score ;
3. documenter ces ecarts.

#### Niveau recherche fort

1. construire un protocole contrefactuel ;
2. mesurer la sensibilite du ranking a chaque attribut proxy ;
3. produire une section fairness beaucoup plus solide scientifiquement.

---

## 4.5 Reduction de l'asymetrie d'information

### Cible initiale

L'objectif etait que le systeme detecte des competences transversales ou implicites, par exemple :

- esprit d'equipe via sport,
- leadership via projets,
- autonomie via experiences,
- competences techniques indirectes sans mot-cle exact.

### Realite actuelle

Cet objectif est partiellement pris en compte.
Le projet a deja ajoute une vraie brique utile :

- inference de soft skills ;
- inference de transferable skills ;
- inference de technical skills proches.

Mais cette inference est aujourd'hui surtout basee sur :

- regex ;
- patterns ;
- regles derivees.

### Ce qui a change

Le besoin metier a ete conserve, mais la solution retenue est plus simple et plus explicable que ce qu'on pourrait attendre d'un moteur d'inference profond.

### Consequence methodologique

On peut dire :

- "le systeme commence a reduire l'asymetrie d'information en valorisant des competences implicites",

mais il faut preciser :

- "cette reduction passe aujourd'hui par des heuristiques metier, pas par un modele d'inference general puissant".

### Ce qu'on peut encore changer

1. garder la version regex comme baseline d'inference ;
2. annoter un sous-corpus de competences implicites ;
3. entrainer un classifieur multi-label ;
4. comparer regex vs modele ;
5. mesurer si des juniors remontent mieux grace a cette brique.

---

## 5. Resume des ecarts majeurs

| Sujet | Cible initiale | Etat actuel | Ecart principal |
|---|---|---|---|
| Anonymisation | Forte, neutre, NER, identite cachee | De-identification regex elargie + identite cachee au premier niveau | Ecart moyen |
| Noms / prenoms dans l'IA | Supprimes | Retires du texte de matching | Ecart largement reduit |
| Affichage identite | Revelee apres intention de contact | Cachee par defaut, revelee apres contact ou conversation existante | Ecart largement reduit |
| Nettoyage runtime | Pipeline riche | Texte candidat runtime/offline aligne + preprocesseur structure + tests croises | Ecart faible |
| Nettoyage dataset | Attendu | Bien present offline | Conforme partiellement |
| Embeddings | 1536 dims envisagees | CamemBERT 768 dims | Ecart formel mais pas forcement negatif |
| Matching | Vectoriel pur + cosinus | Hybride baseline + semantique + enrichi | Ecart structurel |
| Stockage vectoriel | Souhaite / implicite | Pas de vraie base vectorielle dediee | Ecart moyen |
| Test A/B | Baseline vs IA | Baseline vs semantique vs enrichi | Evolution plutot positive |
| Pertinence | Notes expertes 1-5 | Recall, MRR, nDCG sur labels heuristiques | Ecart fort |
| Fairness | Age/origine/ecole/biais identitaires | Junior vs non-junior | Ecart fort |
| Asymetrie d'information | Detection de competences implicites | Partiellement implemente via regex | Conforme partiellement |

---

## 6. Pourquoi le projet a probablement derive de cette facon

La derive observee est logique pour un projet reel.
Elle s'explique probablement par cinq facteurs :

1. **besoin de livrer un MVP** ;
2. **cout eleve d'une vraie anonymisation NER robuste** ;
3. **manque de donnees annotees pour un fine-tuning profond** ;
4. **volonte de garder de l'explicabilite** ;
5. **necessite de montrer vite des resultats visibles dans l'interface**.

En clair :

- la baseline apporte l'explicabilite ;
- CamemBERT apporte la semantique ;
- les heuristiques apportent le gain metier rapide ;
- la fairness a ete simplifiee pour rester mesurable.

Cette trajectoire est defendable.
Mais elle doit etre decrite honnetement.

---

## 7. Ce qu'on peut encore changer, par priorite

## 7.1 Priorite 1 - Corrections indispensables si vous voulez aligner le discours et le produit

1. documenter clairement que l'etat actuel est une **de-identification applicative** et non une anonymisation forte NER ;
2. etendre le masquage a des signaux indirects restants :
   - ecoles,
   - certaines entreprises,
   - localisations,
   - marqueurs sociaux trop identifiants ;
3. formaliser une mesure "avant anonymisation / apres anonymisation" pour quantifier l'effet reel de la de-identification ;
4. reformuler la fairness comme **audit junior / non-junior**, pas comme preuve generale d'absence de biais ;
5. expliciter dans la doc que l'interface recruteur est anonyme par defaut, mais que le texte reste seulement partiellement neutralise.

Les changements les plus visibles cote anonymisation ont deja ete faits.
L'urgence s'est donc deplacee :

- du **correctif produit evident** vers la **consolidation methodologique** ;
- de la fuite directe d'identite vers la reduction des indices residuels et la preuve experimentale.

## 7.2 Priorite 2 - Corrections methodologiques pour un memoire solide

1. formaliser un protocole A/B/C ;
2. ajouter une evaluation humaine qualitative ;
3. isoler l'effet de l'anonymisation ;
4. documenter la weak supervision et ses limites ;
5. clarifier que l'inference de competences implicites est heuristique.

## 7.3 Priorite 3 - Evolutions IA plus ambitieuses

1. NER francophone de production pour la de-identification ;
2. fine-tuning ou apprentissage contrastif pour les embeddings ;
3. classifieur multi-label pour les competences implicites ;
4. retrieval vectoriel pre-calcule + reranking enrichi ;
5. protocole fairness contrefactuel multi-attributs.

---

## 8. Pipeline cible recommande si vous voulez une version plus propre

Si l'objectif est de rapprocher le produit de l'intention de depart sans tout casser, la meilleure architecture cible serait :

1. **Ingestion du profil**
   - headline, bio, experiences, positions, skills.

2. **Nettoyage runtime**
   - normalisation texte ;
   - dedoublonnage ;
   - harmonisation de competences.

3. **De-identification forte**
   - noms, prenoms, emails, telephone, adresse, URLs, ecoles, entreprises si necessaire ;
   - texte candidat neutre.

4. **Extraction explicite**
   - hard skills ;
   - soft skills ;
   - contexte experience.

5. **Encodage semantique**
   - modele francophone ;
   - embedding du texte neutre ;
   - eventuellement projection ou fine-tuning.

6. **Retrieval vectoriel**
   - cosinus sur embeddings pre-calcules ;
   - top-k candidats.

7. **Reranking enrichi**
   - bonus de competences implicites ;
   - bonus / malus metier ;
   - explications.

8. **Audit fairness**
   - junior / non-junior ;
   - tests contrefactuels identitaires ;
   - analyses de sensibilite.

9. **UI preservee contre les biais**
   - identite cachee au premier niveau ;
   - competences visibles ;
   - identite revelee uniquement apres intention de contact.

Ce pipeline est beaucoup plus coherent avec la promesse initiale.

---

## 9. Formulation honnete que vous pouvez utiliser des maintenant

Si vous devez decrire le systeme actuel sans survendre ce qui est fait, la formulation la plus juste est :

> DevSpot implemente aujourd'hui un matching hybride compose d'une baseline par recouvrement de competences, d'un score semantique fonde sur des embeddings CamemBERT et d'un score enrichi par inference heuristique de competences implicites. Les textes candidats font l'objet d'une anonymisation partielle des PII directes avant traitement, mais la de-identification n'est pas encore complete. L'evaluation actuelle repose principalement sur un corpus synthetique nettoye et sur des metriques de ranking offline, completees par un audit de fairness junior / non-junior.

Cette phrase est precise, defendable et coherent avec l'etat reel du depot.

---

## 10. Formulation cible si vous faites les corrections importantes

Si vous appliquez les changements les plus importants, vous pourrez dire quelque chose de beaucoup plus fort :

> DevSpot utilise un pipeline de matching equite-aware dans lequel les profils sont d'abord de-identifies afin de reduire l'exposition aux biais identitaires, puis encodes dans un espace semantique francophone avant retrieval vectoriel et reranking metier. Le systeme est compare a une baseline lexicale et evalue selon des metriques de ranking, des analyses de fairness et une evaluation qualitative expertisee.

Cette version serait beaucoup plus proche de l'ambition initiale.

---

## 11. Conclusion generale

La methodologie initialement visee et le systeme actuel se recouvrent partiellement, mais ils ne sont pas equivalents.

### Ce qui est deja en place

- un dataset experimental propre et utile ;
- une baseline metier claire ;
- un matching semantique CamemBERT reel ;
- une adaptation lineaire du modele ;
- une inference de competences implicites deja interessante ;
- un audit de fairness simplifie.

### Ce qui a fortement derive

- l'anonymisation forte promise n'est pas encore la ;
- l'identite est encore presente dans le texte IA et dans l'interface ;
- la vectorisation n'est pas en 1536 dimensions mais en 768 ;
- le classement final est hybride, pas purement vectoriel ;
- la preuve experimentale repose sur du ranking offline plutot que sur une notation experte et une fairness multi-biais.

### Ce qu'il faut retenir

Le projet actuel est une bonne base technique, mais il faut choisir entre deux positions :

1. **assumer le systeme actuel tel qu'il est**, en le decrivant correctement ;
2. **faire evoluer le produit** pour l'aligner vraiment avec la promesse de depart.

La pire option serait de decrire le systeme comme si l'anonymisation neutre, la fairness multi-biais et le matching vectoriel pur etaient deja acquis.

La meilleure option est soit :

- la transparence methodologique,

soit :

- la correction des ecarts les plus critiques.

---

## 12. References internes utiles dans le repo

- `src/Service/OfferMatchingService.php`
- `src/Service/SemanticMatchingService.php`
- `src/Service/EnrichedMatchingService.php`
- `src/Service/CandidateSkillInferenceService.php`
- `src/Matching/Service/CvAnonymizer.php`
- `src/Matching/Service/FairnessAuditor.php`
- `src/Matching/Service/SkillMatcher.php`
- `src/Controller/RecruiterController.php`
- `assets/controllers/recruiter_offer_matching_controller.js`
- `ml/app/inference.py`
- `ml/app/preprocessing.py`
- `ml/app/skill_inference.py`
- `scripts/matching_dataset_tools.py`
- `scripts/evaluate_matching_model.py`
- `scripts/train_matching_projection.py`
- `docs/matching-ia-documentation.md`
- `docs/camembert-algorithme-technique.md`
- `docs/junior-skill-inference-implementation.md`
- `docs/plan-finetuning-camembert.md`
