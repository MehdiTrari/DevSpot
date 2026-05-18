# 🧠 Plan de fine-tuning CamemBERT — DevSpot Matching

> **Objectif** : Fine-tuner le modèle CamemBERT lui-même (pas juste une couche de projection) pour obtenir un matching offre/développeur significativement supérieur aux modèles de NLP génériques.  
> **Date** : Avril 2026  
> **Matériel cible** : RTX 3060 (12 Go VRAM)

> **Statut réel dans le projet** : ce fine-tuning complet n'a finalement **pas été réalisé** dans la version actuelle de DevSpot.
> La solution effectivement implémentée et utilisée en runtime repose sur `camembert-base` **gelé**, une **projection linéaire entraînée** et un score enrichi côté application.
> Ce document est donc conservé comme **plan de recherche / perspective d'évolution**, pas comme compte-rendu d'une étape exécutée.

---

## Plan du document

1. Pourquoi fine-tuner ?
2. Ce qu'on a aujourd'hui vs ce qu'on veut
3. Prérequis matériels et logiciels
4. Étape 1 — Préparer le dataset
5. Étape 2 — Écrire le script de fine-tuning
6. Étape 3 — Lancer l'entraînement sur GPU
7. Étape 4 — Évaluer les résultats
8. Étape 5 — Intégrer le modèle fine-tuné
9. Estimation des ressources RTX 3060
10. Risques et solutions de repli
11. Checklist complète

---

## 1. Pourquoi fine-tuner ?

CamemBERT a été pré-entraîné sur **138 Go de texte français général** (Wikipedia, news, forums…). Il comprend le français, mais il ne sait **rien** de :

- La sémantique spécifique du recrutement tech ("fullstack" ≈ "backend + frontend")
- Les relations entre technologies ("Symfony" implique "PHP", "Doctrine", "Twig")
- La hiérarchie d'expérience ("lead" > "senior" > "confirmé" > "junior")
- Les équivalences d'offres ("Développeur React" ≈ "Ingénieur front-end JavaScript")

**Avec le fine-tuning**, CamemBERT apprendra ces concepts directement dans ses 110M de paramètres, au lieu qu'on doive les compenser avec des règles PHP (regex, dictionnaires, bonus manuels).

---

## 2. Ce qu'on a aujourd'hui vs ce qu'on veut

Avant lecture : la colonne "Aujourd'hui" correspond à l'état réel du dépôt ; la colonne "Après fine-tuning" décrit une cible envisagée mais non mise en production.

| Aspect | Aujourd'hui (projection) | Après fine-tuning |
|--------|-------------------------|-------------------|
| **Paramètres entraînés** | 590K (matrice 768×768) | ~110M (tout CamemBERT) |
| **CamemBERT** | ❄️ Gelé | 🔥 Entraîné |
| **Compréhension du domaine** | Français général | Français **recrutement tech** |
| **Synonymes tech** | ❌ Ne comprend pas | ✅ Apprend que React ≈ React.js |
| **Hiérarchie expérience** | ❌ Ignorée | ✅ Apprend junior < senior < lead |
| **Script** | `train_matching_projection.py` | **Nouveau** `finetune_camembert.py` |
| **Temps d'entraînement** | ~5 min (CPU) | ~1-3h (RTX 3060) |
| **Fichier produit** | `projection.pt` (2.3 Mo) | Dossier modèle complet (~450 Mo) |

---

## 3. Prérequis matériels et logiciels

### Matériel

| Composant | Minimum | Recommandé (ta config) |
|-----------|---------|----------------------|
| **GPU** | 8 Go VRAM | ✅ RTX 3060 — 12 Go VRAM |
| **RAM** | 16 Go | 16+ Go |
| **Disque** | 5 Go libres | 10+ Go (modèle + cache HuggingFace) |

### Logiciels à installer sur le PC avec GPU

```bash
# 1. Python 3.11+
python3 --version

# 2. PyTorch avec support CUDA (RTX 3060 → CUDA 11.8 ou 12.x)
pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121

# 3. Vérifier que le GPU est détecté
python3 -c "import torch; print(f'CUDA: {torch.cuda.is_available()}'); print(f'GPU: {torch.cuda.get_device_name(0)}')"
# Doit afficher : CUDA: True / GPU: NVIDIA GeForce RTX 3060

# 4. Dépendances ML
pip install transformers accelerate datasets scikit-learn numpy

# 5. (Optionnel mais recommandé) Weights & Biases pour le suivi
pip install wandb
```

---

## Étape 1 — Préparer le dataset

### État actuel du dataset

```
docs/matching-demo-dataset-v2-clean.json
├── 192 développeurs
└── 90 offres
→ Matrice potentielle : 192 × 90 = 17 280 paires
```

### Ce qu'on doit faire

Le dataset actuel utilise la **weak supervision** (labélisation automatique par heuristiques). Pour le fine-tuning, on va garder cette approche mais l'enrichir :

#### a) Augmenter le dataset (optionnel mais recommandé)

Plus on a de paires, mieux c'est. Cibles idéales :

| Taille | Paires d'entraînement | Qualité attendue |
|--------|----------------------|-----------------|
| Actuel | ~1 500 paires | Correcte |
| Bon | ~5 000 paires | Bonne |
| Très bon | ~15 000+ paires | Très bonne |

Pour augmenter : ajouter des profils développeurs et des offres dans le JSON, ou scraper des offres tech françaises.

#### b) Améliorer la weak supervision

Le fichier `scripts/matching_dataset_tools.py` contient la fonction `weak_relevance()` qui attribue un score 0–3 à chaque paire. Pour le fine-tuning, on va convertir en paires `(texte_offre, texte_cv, label)` avec des labels plus granulaires :

| Score `weak_relevance` | Label pour l'entraînement | Signification |
|------------------------|--------------------------|---------------|
| 0 | 0.0 | Pas de rapport |
| 1 | 0.33 | Faiblement pertinent |
| 2 | 0.67 | Pertinent |
| 3 | 1.0 | Très pertinent |

#### c) (Bonus) Annotations manuelles

Si tu veux un modèle encore meilleur, tu peux annoter manuellement 200-500 paires offre/CV avec un score de 0 à 5. Ça prendrait 2-3h mais améliorerait significativement la qualité.

### Commande de préparation

```bash
# Vérifier et nettoyer le dataset
python scripts/clean_dataset.py \
  --input docs/matching-demo-dataset-v2-clean.json \
  --output docs/matching-demo-dataset-v3-finetuning.json
```

---

## Étape 2 — Écrire le script de fine-tuning

Le script `finetune_camembert.py` mentionné dans cette page n'a pas été produit dans le dépôt final.

### Différences avec le script actuel

| Aspect | `train_matching_projection.py` (actuel) | `finetune_camembert.py` (nouveau) |
|--------|----------------------------------------|----------------------------------|
| CamemBERT | `model.eval()`, `torch.no_grad()` | `model.train()`, **gradients activés** |
| Learning rate | 0.005 (élevé, ok pour projection) | **2e-5** (très bas, critique pour fine-tuning) |
| Ce qu'on optimise | `nn.Linear(768, 768)` | **Tout le modèle** CamemBERT |
| Scheduler | Aucun | **Linear warmup + decay** |
| Mixed precision | Non | **Oui (fp16)** — indispensable pour la VRAM |
| Gradient accumulation | Non | **Oui** — simule des batch plus grands |
| Sauvegarde | Un seul `.pt` | Dossier complet (config + tokenizer + poids) |

### Architecture du script

Le nouveau script va :

```
1. Charger le dataset → générer les paires (offre, cv, label)
2. Charger camembert-base SANS geler les poids
3. Ajouter une tête de scoring (cosine similarity)
4. Entraîner avec :
   - Mixed precision fp16 (pour tenir en 12 Go VRAM)
   - Gradient accumulation (steps=4, simule batch_size=64)
   - Linear warmup sur 10% des steps
   - Learning rate 2e-5 avec decay linéaire
   - Early stopping si la loss stagne
5. Sauvegarder le modèle complet dans un dossier
6. Évaluer sur un split de validation (20%)
```

### Hyperparamètres recommandés pour RTX 3060

```python
# Hyperparamètres optimaux pour RTX 3060 (12 Go VRAM)
HYPERPARAMS = {
    "model_name": "camembert-base",
    "max_length": 256,              # 512 = trop lourd pour 12 Go, 256 = bon compromis
    "batch_size": 16,               # par GPU (effectif = 16 × 4 = 64 avec accumulation)
    "gradient_accumulation_steps": 4,
    "learning_rate": 2e-5,          # standard pour fine-tuning BERT
    "weight_decay": 0.01,
    "warmup_ratio": 0.1,            # 10% des steps en warmup
    "epochs": 3,                    # 3-5 epochs suffit pour fine-tuning
    "fp16": True,                   # mixed precision, économise ~40% de VRAM
    "seed": 42,
    "val_split": 0.2,               # 20% pour validation
    "temperature": 20.0,            # scaling pour la similarité cosinus
    "loss": "cosine_embedding_loss", # ou contrastive loss
}
```

### Pseudocode du script

```python
import torch
from torch.cuda.amp import autocast, GradScaler
from transformers import AutoModel, AutoTokenizer, get_linear_schedule_with_warmup

# 1. Charger le modèle — PAS gelé cette fois
model = AutoModel.from_pretrained("camembert-base")
model.train()  # ← Différence clé : gradients activés

tokenizer = AutoTokenizer.from_pretrained("camembert-base")

# 2. Préparer les paires
train_pairs, val_pairs = load_and_split_pairs(dataset, val_ratio=0.2)

# 3. Optimiseur avec learning rate très bas
optimizer = torch.optim.AdamW(model.parameters(), lr=2e-5, weight_decay=0.01)
scheduler = get_linear_schedule_with_warmup(optimizer, warmup_steps, total_steps)
scaler = GradScaler()  # pour mixed precision fp16

# 4. Boucle d'entraînement
for epoch in range(3):
    for step, (offer_text, cv_text, label) in enumerate(train_loader):
        with autocast(dtype=torch.float16):
            # Encoder les deux textes
            offer_emb = mean_pool(model(tokenize(offer_text)))
            cv_emb = mean_pool(model(tokenize(cv_text)))
            
            # Similarité cosinus
            similarity = cosine_similarity(offer_emb, cv_emb)
            loss = loss_fn(similarity * temperature, label)
            loss = loss / gradient_accumulation_steps
        
        # Backward avec mixed precision
        scaler.scale(loss).backward()
        
        if (step + 1) % gradient_accumulation_steps == 0:
            scaler.step(optimizer)
            scaler.update()
            scheduler.step()
            optimizer.zero_grad()
    
    # Validation à chaque epoch
    val_metrics = evaluate(model, val_pairs)
    print(f"Epoch {epoch}: val_loss={val_metrics['loss']:.4f}, MRR={val_metrics['mrr']:.4f}")

# 5. Sauvegarder le modèle complet
model.save_pretrained("ml/models/camembert-devspot-finetuned")
tokenizer.save_pretrained("ml/models/camembert-devspot-finetuned")
```

---

## Étape 3 — Lancer l'entraînement sur GPU

Cette étape n'a pas été exécutée dans le cadre du projet tel qu'il est livré aujourd'hui.

### Commandes à exécuter sur le PC avec RTX 3060

```bash
# 0. Se placer dans le projet
cd /path/to/DevSpot

# 1. Vérifier le GPU
python3 -c "import torch; print(torch.cuda.get_device_name(0)); print(f'{torch.cuda.get_device_properties(0).total_mem / 1e9:.1f} Go VRAM')"
# → NVIDIA GeForce RTX 3060 / 12.0 Go VRAM

# 2. (Optionnel) Évaluer le modèle AVANT fine-tuning pour avoir une baseline
python3 scripts/evaluate_matching_model.py \
  --dataset docs/matching-demo-dataset-v2-clean.json \
  --model camembert-base \
  --device cuda \
  --output docs/eval-before-finetuning.json

# 3. Lancer le fine-tuning 🚀
python3 scripts/finetune_camembert.py \
  --dataset docs/matching-demo-dataset-v2-clean.json \
  --model camembert-base \
  --output ml/models/camembert-devspot-finetuned \
  --device cuda \
  --epochs 3 \
  --batch-size 16 \
  --learning-rate 2e-5 \
  --max-length 256 \
  --fp16

# 4. Évaluer le modèle APRÈS fine-tuning
python3 scripts/evaluate_matching_model.py \
  --dataset docs/matching-demo-dataset-v2-clean.json \
  --model ml/models/camembert-devspot-finetuned \
  --device cuda \
  --output docs/eval-after-finetuning.json

# 5. Comparer les résultats
python3 -c "
import json
before = json.load(open('docs/eval-before-finetuning.json'))
after = json.load(open('docs/eval-after-finetuning.json'))
for metric in ['recall_at_1', 'recall_at_3', 'recall_at_5', 'mrr', 'ndcg_at_5']:
    b = before.get(metric, 0)
    a = after.get(metric, 0)
    delta = a - b
    print(f'{metric:>15}: {b:.4f} → {a:.4f}  ({delta:+.4f})')
"
```

### Durée estimée (RTX 3060)

| Phase | Durée estimée |
|-------|--------------|
| Chargement du modèle | ~30 secondes |
| Encodage initial du dataset | ~2-5 minutes |
| Entraînement (3 epochs, 192 devs × 90 offres) | **30 min – 1h30** |
| Évaluation | ~2-5 minutes |
| **Total** | **~45 min – 2h** |

---

## Étape 4 — Évaluer les résultats

### Métriques à comparer

| Métrique | Signification | Objectif |
|----------|---------------|----------|
| **Recall@1** | Le bon candidat est classé 1er | > 0.50 |
| **Recall@3** | Le bon candidat est dans le top 3 | > 0.70 |
| **Recall@5** | Le bon candidat est dans le top 5 | > 0.80 |
| **MRR** | Rang moyen inversé du premier bon résultat | > 0.60 |
| **nDCG@5** | Qualité du classement des 5 premiers | > 0.65 |

### Résultat attendu

```
                Avant (projection)  →  Après (fine-tuning)
    recall_at_1:       0.35         →        0.55+
    recall_at_3:       0.55         →        0.75+
    recall_at_5:       0.65         →        0.85+
            mrr:       0.45         →        0.65+
      ndcg_at_5:       0.50         →        0.70+
```

Le gain attendu est de **+15 à +25 points** sur chaque métrique, car le modèle apprendra les relations sémantiques spécifiques au recrutement tech.

### Que faire si les résultats ne s'améliorent pas ?

| Problème | Solution |
|----------|----------|
| Loss ne descend pas | Réduire le learning rate (1e-5) |
| Overfitting (val_loss remonte) | Réduire les epochs (2), augmenter weight_decay (0.05) |
| CUDA OOM (plus de VRAM) | Réduire max_length (128) ou batch_size (8) |
| Résultats pires qu'avant | Le dataset est trop petit → augmenter les données |

---

## Étape 5 — Intégrer le modèle fine-tuné

Cette intégration n'a pas eu lieu. En pratique, le runtime actuel continue d'utiliser `CAMEMBERT_MODEL=camembert-base` et, quand elle est activée, la projection `ml/models/devspot-matching-projection.pt`.

Une fois le modèle entraîné et évalué, l'intégration est simple car notre architecture est déjà prête.

### a) Copier le modèle

```bash
# Le modèle fine-tuné sera dans :
ml/models/camembert-devspot-finetuned/
├── config.json
├── model.safetensors      # ~440 Mo (les poids)
├── tokenizer.json
├── tokenizer_config.json
├── special_tokens_map.json
└── sentencepiece.bpe.model
```

### b) Modifier la variable d'environnement

Dans `.env` ou `compose.yaml` :

```bash
# Avant
CAMEMBERT_MODEL=camembert-base

# Après
CAMEMBERT_MODEL=./models/camembert-devspot-finetuned
```

### c) Désactiver la couche de projection

Le modèle fine-tuné n'a **plus besoin** de la couche de projection (elle est "absorbée" dans les poids du modèle). Il faut soit :
- Retirer la variable `CAMEMBERT_PROJECTION_PATH`
- Ou garder la projection comme couche supplémentaire (à tester)

```bash
# Avant
CAMEMBERT_PROJECTION_PATH=/app/models/devspot-matching-projection.pt

# Après — supprimer ou commenter cette ligne
# CAMEMBERT_PROJECTION_PATH=
```

### d) Redémarrer le service ML

```bash
docker compose down ml && docker compose up -d ml
```

Le service FastAPI chargera automatiquement le nouveau modèle — **aucune modification de code Python n'est nécessaire** car `inference.py` utilise déjà `AutoModel.from_pretrained(model_name)` qui accepte aussi bien un nom HuggingFace qu'un chemin local.

### e) Vider les caches

```bash
# Cache des embeddings (le modèle a changé, les anciens embeddings sont invalides)
php bin/console cache:pool:clear cache.app

# Cache des résultats de matching
php bin/console cache:clear
```

---

## Estimation des ressources RTX 3060

### Consommation VRAM estimée

| Composant | VRAM |
|-----------|------|
| Poids CamemBERT (fp16) | ~220 Mo |
| Gradients | ~220 Mo |
| États optimiseur (AdamW) | ~440 Mo |
| Activations (batch=16, seq=256) | ~2-4 Go |
| Buffer PyTorch | ~1 Go |
| **Total estimé** | **~4-6 Go** |
| **Marge restante** | **~6-8 Go** ✅ |

→ La RTX 3060 avec 12 Go est **largement suffisante** pour fine-tuner `camembert-base` en fp16.

### Si jamais ça ne tient pas en mémoire

```python
# Plan B — réduire l'empreinte mémoire
batch_size = 8                       # au lieu de 16
max_length = 128                     # au lieu de 256
gradient_accumulation_steps = 8      # pour compenser le batch plus petit
gradient_checkpointing = True        # échange calcul contre mémoire
```

---

## Risques et solutions de repli

| Risque | Probabilité | Solution |
|--------|-------------|----------|
| **Dataset trop petit** (192 devs, 90 offres) | Moyenne | Augmenter le dataset ou utiliser la data augmentation |
| **Overfitting** (le modèle mémorise au lieu d'apprendre) | Moyenne | Early stopping + validation split + dropout |
| **VRAM insuffisante** | Faible (12 Go suffisent) | Réduire batch_size/max_length, activer gradient checkpointing |
| **Résultats dégradés** | Faible | Garder le modèle actuel (projection), itérer sur le dataset |
| **Temps d'entraînement trop long** | Faible | 3 epochs × ~1h max, acceptable |

### Plan de repli

Si le fine-tuning complet n'apporte pas assez d'amélioration avec notre dataset actuel :

1. **Fine-tuning partiel** : ne dégeler que les 2-3 dernières couches de CamemBERT au lieu des 12
2. **Sentence-BERT (SBERT)** : utiliser le framework `sentence-transformers` pour fine-tuner en mode bi-encoder avec `MultipleNegativesRankingLoss`
3. **Augmentation de données** : générer des offres/CVs synthétiques avec un LLM (GPT, Mistral)

---

## Checklist complète

Cette checklist doit être lue comme une checklist de **travail envisagé**, non comme une liste d'actions réalisées.

### 📋 Sur le PC portable (maintenant, sans GPU)

- [ ] Vérifier que le dataset `docs/matching-demo-dataset-v2-clean.json` est complet et propre
- [ ] (Optionnel) Enrichir le dataset avec plus de profils/offres
- [ ] Écrire le script `scripts/finetune_camembert.py`
- [ ] Écrire/adapter le script d'évaluation pour comparer avant/après
- [ ] Push le code sur Git

### 🖥️ Sur le PC fixe (chez toi, avec RTX 3060)

- [ ] Pull le code
- [ ] Installer PyTorch CUDA : `pip install torch --index-url https://download.pytorch.org/whl/cu121`
- [ ] Installer les dépendances : `pip install transformers accelerate scikit-learn numpy`
- [ ] Vérifier le GPU : `python3 -c "import torch; print(torch.cuda.get_device_name(0))"`
- [ ] Évaluer le modèle AVANT (baseline de métriques)
- [ ] Lancer le fine-tuning (~1-2h)
- [ ] Évaluer le modèle APRÈS
- [ ] Comparer les métriques avant/après
- [ ] Si amélioration → copier le modèle dans `ml/models/camembert-devspot-finetuned/`
- [ ] Modifier `.env` : `CAMEMBERT_MODEL=./models/camembert-devspot-finetuned`
- [ ] Supprimer `CAMEMBERT_PROJECTION_PATH` (la projection n'est plus nécessaire)
- [ ] Redémarrer le service ML
- [ ] Vider les caches PHP
- [ ] Tester le matching sur l'interface recruteur
- [ ] Push le modèle (⚠️ ~450 Mo — utiliser Git LFS ou un stockage externe)
- [ ] Commit + push le code

---

## Résumé en une phrase

> On va **dégeler CamemBERT** et l'entraîner sur nos paires offre/développeur avec un learning rate très bas (2e-5) en mixed precision fp16 sur ta RTX 3060, pour qu'il comprenne nativement le matching tech français au lieu de dépendre de règles PHP écrites à la main.
