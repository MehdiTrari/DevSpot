# Conception et evaluation d'un systeme de matching semantique pour le recrutement tech base sur CamemBERT

**Master Cyber 2024 - 2026**  
Trari Mehdi  

---

## Resume

Ce travail de recherche presente la conception et l'evaluation d'un systeme de matching semantique applique au recrutement dans les metiers du numerique. Le projet s'inscrit dans le cadre de DevSpot, une plateforme de recrutement visant a reduire les biais de selection tout en ameliorant la pertinence de l'appariement entre offres d'emploi et profils developpeurs. L'etude se concentre sur l'utilisation de CamemBERT, modele de langue pre-entraine sur le francais, pour encoder semantiquement les offres et les CV, puis calculer un score de similarite entre les deux textes.

La demarche retenue repose sur une comparaison entre plusieurs niveaux d'approche : une baseline par recouvrement de competences, un matching semantique base sur CamemBERT, puis une variante optimisee par projection lineaire apprise sur un jeu de donnees interne. Le protocole experimental s'appuie sur un dataset nettoye compose de 90 offres et 192 profils developpeurs, evalues au moyen de metriques de ranking telles que Recall@1, Recall@3, MRR et nDCG@5.

Les resultats montrent que l'approche semantique obtient deja des performances elevees sur le dataset considere, et que l'ajout d'une couche de projection specialisee permet encore d'ameliorer le classement. Toutefois, l'etude met egalement en evidence plusieurs limites, notamment la taille reduite du jeu de donnees, la faible independance de certaines paires et le caractere partiellement heuristique de la labellisation. Ces constats conduisent a envisager, comme perspective, un fine-tuning progressif de CamemBERT sur des donnees plus riches et mieux annotees.

## Abstract

This research report presents the design and evaluation of a semantic matching system for recruitment in the digital and software engineering sector. The study is carried out within the DevSpot project, a recruitment platform whose objective is both to reduce selection biases and to improve the relevance of candidate-job matching. The work focuses on the use of CamemBERT, a French pre-trained language model, to build semantic representations of job offers and developer resumes and then compute a similarity score between both texts.

The proposed approach compares several levels of matching: a keyword-overlap baseline, a semantic matching pipeline based on CamemBERT embeddings, and an optimized variant relying on a learned linear projection trained on an internal dataset. The experimental protocol is based on a cleaned dataset composed of 90 job offers and 192 developer profiles, evaluated through ranking metrics such as Recall@1, Recall@3, Mean Reciprocal Rank, and nDCG@5.

The results indicate that the semantic approach already performs strongly on the considered dataset, while the learned projection further improves ranking quality. However, the study also highlights several limitations, including the small size of the dataset, the partial dependence between generated pairs, and the weakly supervised nature of the labels. These findings suggest that the next research step should be a progressive specialization of CamemBERT through fine-tuning on richer and better annotated data.

## Mots cles

- matching semantique
- recrutement algorithmique
- CamemBERT
- NLP en francais
- recommandation de candidats

## Keywords

- semantic matching
- algorithmic recruitment
- CamemBERT
- French NLP
- candidate ranking

---

## Introduction

Le recrutement dans les metiers du numerique repose encore tres souvent sur des logiques de filtrage par mots-cles, d'experience declaree ou de correspondance stricte entre intitulés de poste et competences listees. Or, cette approche montre rapidement ses limites. D'une part, elle ne capture pas correctement la proximite semantique entre deux formulations differentes d'une meme competence. D'autre part, elle tend a sous-valoriser certains profils dont les savoir-faire sont implicites, transferables ou exprimes avec un vocabulaire heterogene. Enfin, dans un contexte de recrutement, les criteres explicites peuvent se combiner a des biais plus implicites, notamment lorsqu'ils avantagent artificiellement des profils plus standardises.

Dans ce contexte, les modeles de langue representent une piste interessante pour depasser le simple filtrage lexical. Parmi eux, CamemBERT occupe une place importante pour les textes en francais, puisqu'il fournit des representations contextuelles capables de mieux prendre en compte le sens global d'un texte. Dans le cadre de DevSpot, ce choix apparait pertinent car la plateforme manipule principalement des offres d'emploi et des CV rediges en francais, souvent melangeant vocabulaire technique, expressions metier et formulations peu standardisees.

Le travail presente dans ce memoire ne cherche pas a proposer un modele de recherche fondamentalement nouveau. Il vise plutot a concevoir et evaluer, dans un cadre applique, un pipeline de matching semantique utilisable dans une plateforme de recrutement. La question centrale est donc la suivante : **dans quelle mesure un systeme de matching s'appuyant sur CamemBERT permet-il d'ameliorer la pertinence du classement entre offres et profils developpeurs par rapport a une approche plus simple, et quelles sont les limites d'une telle approche sur un dataset de taille modeste ?**

Pour y repondre, ce travail s'organise en plusieurs etapes. Une revue de la litterature permet d'abord de situer les enjeux du recrutement algorithmique, des systemes de recommandation emploi-candidat et de l'utilisation des modeles de langage en traitement automatique du langage. La methodologie precise ensuite la constitution du dataset, l'architecture du pipeline et les metriques d'evaluation retenues. Les resultats comparent ensuite le comportement du modele CamemBERT de base et d'une variante optimisee par projection lineaire. Enfin, la discussion revient sur les apports du travail, ses limites et les perspectives d'amelioration, notamment en vue d'un fine-tuning futur du modele.

---

## Revue de la litterature et definition de la problematique

### 1. Recrutement algorithmique et enjeux du matching

La mise en relation automatisee entre candidats et offres d'emploi releve a la fois de la recommandation d'information, du ranking et de l'analyse semantique de documents. Dans les systemes traditionnels, le matching repose souvent sur des approches symboliques ou lexicales : comparaison de mots-cles, regles d'exclusion, filtres sur l'experience ou rapprochement entre taxonomies de competences. Ces approches sont attractives car elles sont simples a implementer, rapides et relativement explicables. En revanche, elles restent sensibles aux variations de vocabulaire, a l'heterogeneite des intitulés de postes et a la formulation libre des CV. [A completer par references scientifiques sur job matching, IR et information retrieval en recrutement]

La litterature montre egalement que les systemes de recrutement automatises soulevent des enjeux d'equite, d'explicabilite et de biais. Les biais peuvent provenir des donnees historiques, des variables utilisees comme proxies de certains attributs sensibles ou encore de la structure meme des regles de classement. Dans les environnements professionnels, ces questions sont devenues centrales, notamment depuis la generalisation des outils de tri automatise. [A completer par references sur fairness, algorithmic bias, AI in hiring]

### 2. Limites des approches par mots-cles

Le matching lexical presente une faiblesse bien connue : il detecte la presence d'un terme, mais pas sa relation semantique avec d'autres formulations proches. Dans le secteur tech, cette limite est particulierement visible. Des expressions comme "developpeur frontend React", "ingenieur JavaScript", "integration UI" ou "SPA TypeScript" peuvent designer des profils proches sans partager exactement les memes mots. De meme, un candidat peut avoir mobilise des competences connexes sans les nommer explicitement. [A completer par references sur skill extraction, ontology-based matching, keyword limitations]

Dans DevSpot, cette logique est visible dans la baseline de scoring, qui mesure un recouvrement direct entre hard skills et soft skills declares. Cette baseline reste utile comme point de comparaison, mais elle ne permet pas a elle seule de capturer la richesse semantique des textes libres.

### 3. Apports des modeles de langue contextuels

Les modeles de type BERT ont modifie l'etat de l'art en traitement automatique du langage en introduisant des representations contextuelles profondes. Contrairement aux embeddings statiques, ces modeles prennent en compte le contexte local et global des termes. Les variantes specialisees par langue, comme CamemBERT pour le francais, offrent une meilleure adequation aux corpus non anglophones. [A completer par references sur BERT, RoBERTa, CamemBERT]

Dans un probleme de matching offre-candidat, ces modeles permettent de transformer un texte en vecteur dense, puis d'estimer une proximite semantique entre offre et CV. Cette approche est particulierement interessante lorsque le but est le classement plutot que la simple classification binaire. Toutefois, ces modeles generalistes ne comprennent pas necessairement les regularites propres au domaine du recrutement tech. C'est pour cette raison que plusieurs travaux recents proposent des formes de specialisation, soit par fine-tuning supervisé, soit par apprentissage contrastif, soit par adaptation a une tache de ranking. [A completer par references sur sentence embeddings, bi-encoders, ranking, contrastive learning]

### 4. Positionnement du travail

Le present travail se situe a l'intersection de trois axes :

- le matching automatise en contexte de recrutement ;
- l'utilisation de modeles de langue francophones pour representer des offres et des CV ;
- l'evaluation d'un pipeline de ranking sur un jeu de donnees metier de taille limitee.

Le choix de CamemBERT se justifie par la nature francophone du corpus. Le choix d'une projection lineaire au-dessus du modele de base s'explique, quant a lui, par la volonte de specialiser le systeme sans engager immediatement un fine-tuning complet, plus couteux en donnees, en calcul et en stabilite experimentale.

### 5. Problematique

Au regard de la litterature et du contexte projet, la problematique retenue est la suivante :

**Dans quelle mesure un systeme de matching semantique base sur CamemBERT permet-il d'ameliorer le classement des profils developpeurs pour des offres d'emploi tech en francais, et dans quelle mesure une adaptation legere du modele, via projection lineaire, apporte-t-elle un gain mesurable sur un dataset interne de taille modeste ?**

### 6. Hypotheses de recherche

Les hypotheses suivantes peuvent structurer le memoire :

- **H1** : un matching semantique base sur CamemBERT fournit un classement plus pertinent qu'une approche purement lexicale.
- **H2** : l'ajout d'une projection lineaire specialisee sur les donnees du projet ameliore les performances du modele de base.
- **H3** : les gains observes doivent etre interpretes avec prudence en raison de la taille et de la structure du dataset, ainsi que du mode de labellisation utilise.

---

## Methodologie

La recherche adopte une demarche appliquee de type experimental. L'objectif n'est pas de produire un corpus theorique original, mais d'evaluer quantitativement un pipeline de matching semantique dans un cadre realiste de plateforme de recrutement.

Le protocole repose sur un dataset interne nettoye compose de **90 offres** et **192 profils developpeurs**. Les textes sont normalises, homogenises et reequilibres afin de corriger certaines incoherences lexicales et de seniorite. Le pipeline de matching compare plusieurs niveaux de traitement :

1. une baseline par recouvrement de competences ;
2. un matching semantique base sur CamemBERT ;
3. une version adaptee par projection lineaire apprise sur les donnees du projet.

Les embeddings sont generes par le service Python du projet a partir de `camembert-base`. La similarite entre une offre et un profil est calculee par cosinus, puis reetalonnee pour etirer une plage de scores trop compacte. La projection lineaire est entrainee separement sur des paires offre/candidat generees par weak supervision.

L'evaluation repose sur des metriques de ranking : **Recall@1**, **Recall@3**, **Recall@5**, **MRR** et **nDCG@5**. Ces mesures permettent d'estimer a la fois la capacite du systeme a faire remonter un bon candidat dans les premiers rangs et la qualite globale de l'ordre des resultats. Le protocole est reproductible a partir des scripts du depot, ce qui constitue un point important de rigueur methodologique.

---

## Resultats

### 1. Description du jeu de donnees

Le jeu de donnees nettoye utilise pour l'evaluation contient :

- **192 profils developpeurs** ;
- **90 offres d'emploi** ;
- une repartition de seniorite reequilibree cote candidats ;
- une repartition d'experience reequilibree cote offres.

Le rapport de nettoyage indique notamment :

- 35 profils juniors ;
- 96 profils intermediaires ;
- 50 profils seniors ;
- 11 profils leads.

Pour les offres, la repartition finale des niveaux d'experience est la suivante :

- 14 offres entre 0 et 2 ans ;
- 41 offres entre 3 et 4 ans ;
- 23 offres entre 5 et 6 ans ;
- 12 offres a 7 ans et plus.

Ces elements montrent que le dataset a fait l'objet d'un travail de reequilibrage utile a l'experimentation. En revanche, il reste de taille modeste pour une tache de specialisation profonde d'un modele de langue.

### 2. Resultats du modele CamemBERT de base

L'evaluation du modele `camembert-base` sans projection produit les scores suivants :

| Metrique | Valeur |
|----------|--------|
| Recall@1 | 0.9222 |
| Recall@3 | 1.0000 |
| Recall@5 | 1.0000 |
| MRR | 0.9611 |
| nDCG@5 | 0.9371 |

Ces resultats indiquent que le modele semantique de base est deja tres performant sur le dataset considere. Le fait que Recall@3 et Recall@5 atteignent 1.0 signifie que, pour chaque offre evaluee, au moins un candidat considere comme pertinent apparait systematiquement dans les trois ou cinq premiers resultats.

### 3. Resultats apres projection lineaire

L'ajout de la projection lineaire specialisee entrainee sur les donnees du projet produit les resultats suivants :

| Metrique | Avant projection | Apres projection | Ecart |
|----------|------------------|------------------|-------|
| Recall@1 | 0.9222 | 1.0000 | +0.0778 |
| Recall@3 | 1.0000 | 1.0000 | +0.0000 |
| Recall@5 | 1.0000 | 1.0000 | +0.0000 |
| MRR | 0.9611 | 1.0000 | +0.0389 |
| nDCG@5 | 0.9371 | 0.9991 | +0.0620 |

La projection ne change pas les metriques deja saturees a 1.0, mais elle ameliore encore le positionnement du premier bon candidat ainsi que la qualite du classement dans le top 5. Les gains sur **MRR** et **nDCG@5** sont les plus informatifs, car ils montrent une meilleure organisation des resultats, et pas uniquement la presence d'un bon candidat dans les premiers rangs.

### 4. Lecture qualitative des classements

Les extraits d'offres disponibles dans les rapports d'evaluation montrent que, pour des postes comme **Developpeur Full Stack React / Symfony**, **DevOps Engineer** ou **Developpeur Android Kotlin**, le systeme remonte effectivement des profils dont le niveau de pertinence faible est note a 3 dans les premiers rangs. Cette observation suggere que la representation semantique capture correctement une part importante de la proximite metier attendue.

Cependant, certains exemples visibles dans les sorties montrent egalement que des profils faiblement pertinents ou non pertinents peuvent remonter dans le top 3 lorsque l'environnement de l'offre est tres large ou lorsque le nombre de candidats positifs est tres eleve. Cela invite a ne pas interpréter les scores de maniere trop optimiste.

### 5. Premiere synthese des resultats

Les resultats soutiennent les deux constats suivants :

- le matching semantique base sur CamemBERT fonctionne deja bien sur le corpus de DevSpot ;
- une specialisation legere par projection lineaire semble apporter un gain supplementaire mesurable sur la qualite du classement.

L'hypothese **H2** est donc provisoirement soutenue par les mesures disponibles. En revanche, l'interpretation des performances absolues demande une analyse critique, developpee dans la partie suivante.

---

## Discussion

### 1. Ce que montrent reellement les resultats

Les scores obtenus sont tres eleves. Pris isolément, ils pourraient conduire a conclure que le probleme est presque resolu. Une telle lecture serait toutefois excessive. Les performances mesurees doivent etre replacées dans le contexte particulier du dataset et du protocole de labellisation.

En effet, les rapports d'evaluation montrent que certaines offres disposent d'un nombre tres eleve de candidats positifs. Par exemple, une offre Full Stack React / Symfony peut compter **191 candidats positifs sur 192 profils**. Dans ce cas, les metriques de rappel deviennent tres faciles a saturer, car la probabilite de faire remonter au moins un candidat positif dans le top 3 ou top 5 est presque mecanique. Cela explique pourquoi Recall@3 et Recall@5 atteignent 1.0 des avant le modele de base.

### 2. Importance des metriques de ranking fines

Dans ce contexte, les metriques les plus utiles ne sont pas Recall@3 et Recall@5, mais plutot **MRR** et **nDCG@5**. Ces deux indicateurs renseignent plus finement la qualite du classement, en valorisant le fait de remonter les meilleurs profils plus haut dans la liste.

Le passage de 0.9611 a 1.0 en MRR et de 0.9371 a 0.9991 en nDCG@5 indique que la projection lineaire rend le classement plus ordonne. Cela constitue un signal positif, meme si l'amplitude du gain doit etre interpretee a la lumiere des limites du protocole.

### 3. Interet du MVP hybride

Du point de vue de l'ingenierie, le MVP de DevSpot ne peut pas etre considere comme faible. L'association d'un modele de langue generaliste en francais avec une couche de calibration metier est une strategie pragmatique. Elle permet :

- d'obtenir rapidement un moteur utilisable ;
- de compenser le manque de donnees annotees ;
- de garder un certain niveau d'explicabilite sur le comportement du systeme ;
- de tester des hypotheses metier avant d'engager un fine-tuning plus lourd.

Dans ce cadre, la partie heuristique ou la projection ne doivent pas etre comprises comme un aveu de faiblesse du modele, mais comme une forme d'adaptation progressive a un domaine specialise.

### 4. Limites scientifiques de l'etude

Plusieurs limites importantes doivent etre explicitées.

La premiere concerne la **taille du dataset**. Meme si la matrice offre-candidat permet de generer de nombreuses paires, le nombre reel d'objets independants reste limite a 90 offres et 192 profils. Pour un travail de recherche applique, cela suffit a produire un premier signal. Pour un fine-tuning complet robuste de CamemBERT, cela reste probablement insuffisant.

La deuxieme limite tient au mode de **labellisation faible**. Les labels de pertinence ne proviennent pas d'une evaluation humaine exhaustive, mais d'une fonction de pertinence heuristique. Cela facilite la construction d'un protocole experimental, mais peut aussi introduire un biais de circularite : le systeme est partiellement evalue selon une logique qui reflète deja des choix metier definis en amont.

La troisieme limite concerne la **saturation de certaines metriques**. Lorsque beaucoup de candidats sont juges positifs pour une meme offre, les mesures de rappel perdent une partie de leur capacite discriminante. Cela rend indispensable le recours a des metriques plus fines et, idealement, a des evaluations humaines complementaires.

### 5. Faut-il encore optimiser l'algorithme ?

Oui, mais pas n'importe comment. Les resultats actuels montrent que le pipeline fonctionne deja. Il n'est donc pas necessaire de renverser completement l'architecture pour obtenir un resultat defendable. En revanche, si l'objectif est de transformer le MVP en contribution plus solide, plusieurs axes d'optimisation apparaissent.

Le premier axe est l'amelioration de la **qualite du dataset** : plus de profils, plus d'offres, davantage de cas difficiles, et idealement quelques centaines d'annotations humaines. Le second axe est l'amelioration de l'**evaluation**, avec un jeu de validation mieux separe, davantage de hard negatives et une analyse qualitative plus systematique. Le troisieme axe est la specialisation progressive du modele, par exemple via une projection amelioree, un fine-tuning partiel ou une adaptation de type bi-encoder avant d'envisager un fine-tuning complet.

### 6. Perspectives

La perspective la plus naturelle consiste a reduire progressivement la dependance aux regles metier explicites au profit d'un modele plus specialise. Toutefois, sur un horizon court et avec le volume de donnees actuel, un fine-tuning complet de CamemBERT parait risqué. Une trajectoire plus realiste serait :

1. enrichir le dataset ;
2. mieux annoter les paires ;
3. tester un fine-tuning partiel ou une adaptation parametre-efficiente ;
4. comparer rigoureusement cette nouvelle version au MVP hybride existant.

L'apport scientifique du travail ne serait alors pas de montrer qu'un grand modele suffit seul, mais de documenter une trajectoire d'industrialisation progressive d'un moteur de matching semantique en contexte de recrutement tech francophone.

---

## Conclusion

Ce memoire avait pour objectif d'evaluer la pertinence d'un systeme de matching semantique pour des offres d'emploi et des profils developpeurs en francais dans le cadre de la plateforme DevSpot. L'etude montre qu'un pipeline base sur CamemBERT permet deja d'obtenir des resultats tres encourageants sur le dataset considere, et qu'une adaptation legere via projection lineaire semble encore ameliorer la qualite du classement.

Les hypotheses de depart sont globalement soutenues : le matching semantique apporte une valeur ajoutee par rapport a une simple logique lexicale, et la specialisation du modele a l'aide des donnees du projet produit un gain observable. Toutefois, ces conclusions doivent etre nuancees par plusieurs limites methodologiques, en particulier la taille du corpus, la structure du dataset et la nature heuristique de la labellisation.

Ainsi, la principale contribution du travail ne reside pas dans la demonstration d'un modele definitif, mais dans la mise en place d'un cadre experimental coherent, reproductible et directement exploitable pour faire evoluer le systeme. A court terme, le MVP hybride mis en place apparait donc comme une solution pertinente. A moyen terme, l'enjeu sera de transferer progressivement cette connaissance metier dans un modele plus specialise, a condition de disposer de donnees plus riches et d'un protocole d'evaluation plus exigeant.

---

## Bibliographie

> A completer avec un minimum de 15 references scientifiques, citees dans le texte, selon un style APA coherent.

### References scientifiques a ajouter en priorite

- travaux fondateurs sur BERT, RoBERTa et CamemBERT ;
- articles sur semantic matching et job recommendation ;
- articles sur fairness dans les systemes de recrutement algorithmique ;
- articles sur sentence embeddings et contrastive learning ;
- articles sur evaluation de systemes de ranking et metriques MRR / nDCG.

### Modele de notice APA

```text
Nom, A. A., & Nom, B. B. (Annee). Titre de l'article. Titre de la revue, volume(numero), pages.
DOI : https://doi.org/xx.xxxx/xxxxx
```

---

## Annexes

### Annexe 1 - Description du dataset

- nombre de profils ;
- nombre d'offres ;
- repartition des niveaux ;
- exemples de champs normalises.

### Annexe 2 - Protocole experimental

- scripts utilises ;
- parametres de `evaluate_matching_model.py` ;
- parametres de `train_matching_projection.py`.

### Annexe 3 - Extraits de resultats

- contenu de `docs/matching-eval-before.json` ;
- contenu de `docs/matching-eval-after.json` ;
- tableaux de comparaison.

### Annexe 4 - Elements techniques du pipeline

- schema du pipeline ;
- formule de similarite ;
- description de la projection lineaire ;
- variables d'environnement du service ML.

### Annexe 5 - Prompts et outils utilises

Si vous avez utilise des prompts pour structurer la documentation, nettoyer des donnees ou explorer des pistes de recherche, vous pouvez les joindre ici conformement aux consignes.

---

## Conseils de redaction pour la version finale

- garder ce document comme base de travail, puis le convertir en Word ou PDF avec pagination ;
- ne pas gonfler artificiellement la partie Resultats : mieux vaut un protocole clair et des limites bien discutees qu'une sur-promesse ;
- inserer au moins 2 ou 3 tableaux et 2 figures dans les sections Resultats et Discussion ;
- citer explicitement les figures et tableaux dans le texte ;
- garder la partie Discussion analytique, sans y ajouter de nouveaux resultats ;
- annoncer clairement dans l'introduction que le travail porte sur une **evaluation appliquee d'un prototype avance**, pas sur une recherche fondamentale exhaustive.