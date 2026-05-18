# Algorithme IA CamemBERT - Description technique detaillee

## 1. Objet du document

Ce document decrit le fonctionnement technique reel de la brique IA de DevSpot telle qu'elle est implemente actuellement dans le depot.

L'objectif est de distinguer clairement :

- ce qui repose effectivement sur **CamemBERT** ;
- ce qui est gere par le **service Python FastAPI** ;
- ce qui est orchestre cote **Symfony / PHP** ;
- ce qui releve d'une **inference heuristique** et non du modele de langage.

En l'etat actuel, DevSpot n'utilise pas un CamemBERT fine-tune de bout en bout. Le systeme s'appuie sur :

- **CamemBERT gelé** pour produire des embeddings semantiques ;
- une **projection lineaire optionnelle** entrainee sur le domaine du matching ;
- un **rescoring PHP** pour rendre les scores discriminants ;
- un module d'**inference de competences par regles** ;
- un score final enrichi qui combine semantique et bonus metier.

## 2. Vue d'ensemble du pipeline

Le pipeline de matching suit la sequence suivante :

```text
Offre + CV candidat
        |
        v
Normalisation des textes
        |
        v
Embedding CamemBERT (768 dimensions)
        |
        +--> projection lineaire optionnelle
        |
        v
Normalisation L2 des vecteurs
        |
        v
Similarite cosinus
        |
        v
Rescaling lineaire du score
        |
        +--> score semantique
        |
        +--> inference de competences par regles
                 |
                 v
           bonus d'enrichissement
                 |
                 v
           score final DevSpot
```

Dans le pipeline produit actuellement expose dans l'application, le systeme produit donc trois niveaux de score :

- un **score baseline** par overlap de competences ;
- un **score semantique** derive des embeddings CamemBERT ;
- un **score enrichi DevSpot** qui ajoute un bonus issu des competences inferees.

Point important : un **reranker** existe aussi dans les scripts de recherche et d'evaluation offline du depot, notamment dans `scripts/train_matching_reranker.py` et `scripts/evaluate_matching_model.py`. Ce reranker est un modele tabulaire experimente au-dessus des signaux `baseline`, `semantic` et `enriched_proxy`, mais il n'est pas aujourd'hui la brique de scoring runtime exposee par les services Symfony du site. Autrement dit, dans le produit en ligne, le score final utilise reste le **score enrichi DevSpot** ; le reranker correspond pour l'instant a une piste avancee d'evaluation et de comparaison, pas a un quatrieme score affiche dans l'interface.

## 3. Architecture logicielle

### 3.1 Repartition des responsabilites

La brique IA est volontairement separee entre deux couches :

- **Python / FastAPI** pour le calcul vectoriel et l'inference ;
- **Symfony / PHP** pour l'orchestration metier, le cache applicatif et le scoring final exploitable par l'interface.

### 3.2 Service Python

Le service Python expose plusieurs endpoints :

- `GET /health`
- `POST /embed`
- `POST /embed-batch`
- `POST /match`
- `POST /infer-skills`
- `POST /infer-skills-batch`

Ce service est initialise au demarrage par le lifespan FastAPI, ce qui force le chargement du modele une seule fois en memoire.

### 3.3 Orchestration PHP

Cote Symfony, plusieurs services structurent le pipeline :

- `AiMatchingClient` : client HTTP + cache 7 jours ;
- `SemanticMatchingService` : calcul du score semantique ;
- `CandidateSkillInferenceService` : mapping des reponses d'inference ;
- `EnrichedMatchingService` : ajout des bonus d'inference ;
- `SkillMatcher` : baseline par recouvrement de competences.

## 4. Partie strictement CamemBERT

### 4.1 Modele charge

Le service charge par defaut le modele HuggingFace `camembert-base` via :

- `AutoTokenizer.from_pretrained(model_name)`
- `AutoModel.from_pretrained(model_name)`

Le nom du modele est pilote par la variable d'environnement `CAMEMBERT_MODEL`.

Le device est pilote par `CAMEMBERT_DEVICE` et retombe automatiquement sur `cpu` si `cuda` est demande mais indisponible.

### 4.2 Nature de l'usage du modele

CamemBERT est utilise ici comme **encodeur de texte** et non comme modele de classification fine-tune.

Concretement :

- les poids du modele de base ne sont pas modifies dans le service d'inference ;
- l'inference se fait sous `torch.no_grad()` ;
- le modele est place en mode `eval()` ;
- la sortie exploitee est `last_hidden_state`.

La dimension des embeddings retournes est celle de `hidden_size`, soit **768** pour `camembert-base`.

## 5. Pretraitement des textes

Avant tokenisation, chaque texte est normalise par `normalize_text()`.

Les operations appliquees sont :

- normalisation Unicode **NFKC** ;
- remplacement des espaces insécables par des espaces standards ;
- reduction de tous les espaces consecutifs a un seul espace ;
- suppression des espaces en debut et fin de chaine.

Ce pretraitement est important pour :

- limiter les variations artificielles entre textes quasi identiques ;
- stabiliser les cles de cache ;
- obtenir des embeddings plus coherents.

## 6. Calcul d'un embedding

### 6.1 Tokenisation

Le service runtime tokenise les textes avec les parametres suivants :

- `return_tensors="pt"`
- `truncation=True`
- `max_length=512`
- `padding=True`

Le mode batch est natif : plusieurs textes peuvent etre tokenises et inférés en une seule passe.

Point important : les scripts offline `train_matching_projection.py` et `evaluate_matching_model.py` utilisent, eux, une limite a `max_length=256`. Le runtime applicatif est donc un peu plus permissif que les scripts de preparation et d'evaluation.

### 6.2 Forward pass

Une fois tokenises, les tenseurs sont deplaces sur le device cible puis passes au modele :

```python
with torch.no_grad():
    model_output = self.model(**encoded)
```

Le resultat exploite est `model_output.last_hidden_state`, donc la representation contextuelle de chaque token.

### 6.3 Mean pooling masque

L'embedding de phrase n'est pas le token `[CLS]`. Le service applique un **mean pooling pondere par le masque d'attention** :

$$
e = \frac{\sum_{t=1}^{T} h_t \cdot m_t}{\sum_{t=1}^{T} m_t}
$$

avec :

- $h_t$ : vecteur du token $t$ ;
- $m_t$ : masque d'attention ;
- $e$ : embedding de phrase.

Ce choix est classique pour l'usage sentence embedding avec des modeles de type BERT.

### 6.4 Projection lineaire optionnelle

Si `CAMEMBERT_PROJECTION_PATH` est defini, le service charge une matrice de projection PyTorch et applique :

$$
e' = e W^T
$$

ou $W$ est une matrice $768 \times 768$.

Cette projection permet d'adapter l'espace vectoriel au domaine offre/candidat sans re-entrainer tout CamemBERT.

### 6.5 Normalisation L2

L'embedding final est ensuite normalise :

$$
\hat{e} = \frac{e'}{\|e'\|_2}
$$

Cette etape est critique, car elle permet de transformer directement le produit scalaire en similarite cosinus.

## 7. Similarite semantique

Une fois les deux vecteurs normalises obtenus, la similarite brute est calculee par cosinus :

$$
\cos(u, v) = \frac{u \cdot v}{\|u\|_2 \|v\|_2}
$$

Comme les vecteurs sont deja normalises, cette expression est numeriquement equivalente au produit scalaire.

### 7.1 Probleme observe

Dans le domaine des textes techniques francophones, les cosinus bruts sont souvent tres regroupes, typiquement dans une plage proche de `0.85 - 0.95`. Cela rend les differences peu lisibles a l'affichage et peu discriminantes pour le tri final.

### 7.2 Rescaling applique

Pour etirer cette plage, le score est recalcule lineairement avec un plancher fixe a `0.75` :

$$
score = \text{clamp}\left(\frac{\cos(u, v) - 0.75}{1 - 0.75}, 0, 1\right)
$$

Ce rescoring est implemente a la fois :

- dans le service Python pour l'endpoint `/match` ;
- dans `SemanticMatchingService` cote PHP pour garder le meme comportement quand les embeddings sont recuperes separement.

Le score final semantique est ensuite arrondi a 4 decimales et converti en pourcentage pour l'UI.

## 8. Pourquoi le calcul est duplique entre Python et PHP

Le endpoint `/match` peut calculer directement un score semantique complet, mais le code PHP privilegie surtout le chemin suivant :

- recuperation des embeddings via `/embed` ou `/embed-batch` ;
- calcul du cosinus cote Symfony ;
- rescoring local ;
- reutilisation du cache applicatif.

Cette approche donne plus de controle au coeur metier pour :

- batcher les requetes ;
- memoriser les vecteurs ;
- recalculer plusieurs combinaisons sans rappeler le service ML ;
- rester resilient si un endpoint specialise evolue.

## 9. Systeme de cache

### 9.1 Cache Python en memoire du service

Le singleton `get_embedding_service()` est protege par `@lru_cache(maxsize=1)`. Le modele et le tokenizer ne sont donc charges qu'une seule fois par processus.

### 9.2 Cache Symfony des appels IA

`AiMatchingClient` applique un cache `cache.app` avec :

- une cle de type `ai_matching.embed.{sha256}` pour les embeddings ;
- une cle de type `ai_matching.infer.{sha256}` pour les inferences ;
- une duree de vie de **604800 secondes**, soit **7 jours**.

Le client tente d'abord un appel batch, puis retombe sur des appels unitaires si le batch renvoie une structure invalide.

### 9.3 Cache memoire intra-requete

`SemanticMatchingService` maintient aussi un cache local PHP indexe par hash SHA-256 du texte. Cela evite de recalculer plusieurs fois le meme embedding au sein d'une seule requete de matching.

## 10. Entrainement de la projection lineaire

### 10.1 Principe general

Le script `scripts/train_matching_projection.py` n'entraine pas CamemBERT lui-meme. Il gele le modele de base et apprend uniquement une transformation lineaire dans l'espace des embeddings.

Le pipeline est le suivant :

1. charger le dataset nettoye ;
2. construire les textes offre et candidat ;
3. produire les embeddings CamemBERT geles ;
4. generer des paires faibles positives et negatives ;
5. apprendre une matrice de projection ;
6. sauvegarder la projection dans un fichier `.pt`.

### 10.2 Construction des labels

Les labels sont produits par **weak supervision** a partir de `weak_relevance()` :

- paires positives si l'offre et le profil partagent un niveau minimal de pertinence ;
- paires negatives sinon ;
- intensite du label positive normalisee sur `3.0`.

Autrement dit, les labels ne proviennent pas d'annotations humaines exhaustives mais d'heuristiques metier servant de supervision faible.

### 10.3 Modele appris

La projection est un `nn.Linear(feature_dim, feature_dim, bias=False)` avec :

- `feature_dim = 768` ;
- poids initialises a l'identite ;
- optimisation par `AdamW`.

### 10.4 Fonction de perte

Le script calcule :

1. projection des embeddings offre et candidat ;
2. normalisation L2 ;
3. cosinus ;
4. multiplication des cosinus par `12.0` pour augmenter le contraste des logits ;
5. optimisation par `BCEWithLogitsLoss`.

La perte reelle utilisee dans le code est donc **BCEWithLogitsLoss**, et non CrossEntropyLoss.

### 10.5 Hyperparametres par defaut

Les hyperparametres visibles dans le script sont :

- `epochs = 6`
- `batch_size = 64`
- `learning_rate = 0.005`
- `negatives_per_offer = 4`
- `seed = 42`

Le resultat est sauvegarde dans un payload contenant notamment :

- la matrice `projection` ;
- le nom du modele ;
- le chemin du dataset ;
- le nombre de paires d'entrainement ;
- la `finalLoss`.

## 11. Evaluation de la projection

Le script `scripts/evaluate_matching_model.py` evalue la qualite du modele avec ou sans projection.

Les metriques calculees sont :

- `recall@1`
- `recall@3`
- `recall@5`
- `mrr`
- `ndcg@5`

Le calcul se fait sur la matrice de similarite :

$$
S = O C^T
$$

avec :

- $O$ : embeddings des offres ;
- $C$ : embeddings des candidats.

Comme les vecteurs sont normalises, cette multiplication matricielle fournit directement les cosinus.

## 12. Ce qui n'est pas CamemBERT dans le pipeline

Il est important d'etre techniquement exact : toute la brique IA DevSpot n'est pas issue de CamemBERT.

### 12.1 Inference de competences

Le module `ml/app/skill_inference.py` est un moteur d'**inference par expressions regulieres et regles derivees**. Il detecte notamment :

- soft skills ;
- competences transferables ;
- competences techniques ;
- niveau `beginner`, `intermediate` ou `advanced`.

Cette partie n'utilise pas de forward pass CamemBERT. Elle repose sur :

- des patterns regex explicites ;
- des bonus de niveau ;
- des regles de derivation metier.

### 12.2 Baseline metier

`SkillMatcher` calcule un score de reference sans IA :

$$
score = \min\left(1.0, 0.65 \cdot hard + 0.25 \cdot soft + juniorBoost\right)
$$

avec un bonus fixe de `0.1` pour les profils `<= 2 ans` d'experience.

### 12.3 Score enrichi final

`EnrichedMatchingService` ajoute au score semantique un bonus plafonne a `0.15` base sur les competences inferees.

Le bonus technique suit la logique :

$$
bonus_{tech} += 0.08 \times poids_{niveau} \times confiance \times poids_{match}
$$

avec :

- `poids_niveau = 0.35` pour `beginner` ;
- `poids_niveau = 0.65` pour `intermediate` ;
- `poids_niveau = 0.85` pour `advanced` ;
- `poids_match = 1.0` pour un match direct ;
- `poids_match = 0.6` pour un match par proximite semantique metier.

Le bonus soft suit la logique :

$$
bonus_{soft} += 0.03 \times confiance
$$

Le score final est ensuite borne dans `[0, 1]`.

## 13. Contrats d'API

Les exemples ci-dessous sont illustratifs. Les vecteurs sont volontairement tronques pour rester lisibles, alors qu'un embedding reel contient 768 valeurs flottantes.

### 13.1 Embed unitaire

Requete :

```json
{
  "text": "Symfony 7, Docker, PostgreSQL, CI/CD"
}
```

Reponse :

```json
{
  "embedding": [0.0123, -0.0456],
  "dimension": 768,
  "normalized_text": "Symfony 7, Docker, PostgreSQL, CI/CD"
}
```

### 13.2 Match semantique

Requete :

```json
{
  "offer_text": "Recherche developpeur Symfony Docker PostgreSQL",
  "candidate_text": "Developpeur backend PHP Symfony avec Docker et Postgres"
}
```

Reponse :

```json
{
  "semantic_score": 0.8731,
  "offer_dimension": 768,
  "candidate_dimension": 768,
  "normalized_offer_text": "Recherche developpeur Symfony Docker PostgreSQL",
  "normalized_candidate_text": "Developpeur backend PHP Symfony avec Docker et Postgres"
}
```

### 13.3 Inference de competences

Requete :

```json
{
  "text": "Projet Symfony avec API, Docker, PostgreSQL et GitHub Actions"
}
```

Reponse :

```json
{
  "inferred_soft_skills": [],
  "inferred_transferable_skills": ["project management"],
  "inferred_technical_skills": [
    {"skill": "symfony", "level": "intermediate", "confidence": 0.92},
    {"skill": "docker", "level": "intermediate", "confidence": 0.88}
  ],
  "confidence": {
    "project management": 0.7
  },
  "normalized_text": "Projet Symfony avec API, Docker, PostgreSQL et GitHub Actions"
}
```

## 14. Variables d'environnement et execution

Les variables importantes du pipeline sont :

- `ML_SERVICE_URL`
- `ML_SERVICE_TIMEOUT`
- `CAMEMBERT_MODEL`
- `CAMEMBERT_DEVICE`
- `CAMEMBERT_PROJECTION_PATH`

Dans le depot :

- l'application pointe localement vers `http://localhost:8001` ou `http://127.0.0.1:8001` selon l'environnement ;
- Docker relie Symfony au service ML via `http://ml:8001` ;
- le modele de projection present dans le depot est `ml/models/devspot-matching-projection.pt`.

Le service ML tourne via Uvicorn, expose en local sur le port `8001`.

## 15. Forces et limites de l'approche actuelle

### 15.1 Forces

- bonne adequation au francais grace a CamemBERT ;
- architecture simple a operer ;
- cout d'entrainement faible par rapport a un fine-tuning complet ;
- batching et cache deja prevus ;
- controle metier fort sur l'explicabilite du score final.

### 15.2 Limites

- le modele de base n'est pas fine-tune sur le matching tech ;
- la projection lineaire reste plus limitee qu'un apprentissage profond de bout en bout ;
- l'inference de competences repose sur des regles, donc sensible a la formulation ;
- le rescoring par plancher fixe a `0.75` est empirique et devra etre recalibre si le modele change.

## 16. Conclusion technique

Techniquement, l'algorithme IA DevSpot repose sur une approche hybride :

- **CamemBERT** fournit la representation semantique des textes ;
- une **projection lineaire** optionnelle specialise cet espace vectoriel ;
- **Symfony** orchestre le calcul, le cache et le scoring final ;
- des **heuristiques metier** completent le modele pour valoriser les competences implicites.

Le systeme actuel n'est donc pas un simple matching par mots-cles, mais ce n'est pas encore non plus un CamemBERT fine-tune de bout en bout. C'est une architecture intermediaire pragmatique, deja industrialisee dans le projet, qui permet d'obtenir un matching semantique exploitable tout en gardant une bonne maitrise technique et metier.