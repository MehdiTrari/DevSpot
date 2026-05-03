# 🤖 Matching IA DevSpot — Documentation technique

> **Version** : 1.1 — Avril 2026  
> **Auteurs** : Équipe DevSpot  
> **Stack** : Symfony 7 (PHP) + FastAPI (Python) + CamemBERT (HuggingFace)

---

## Table des matières

1. [Vue d'ensemble](#1-vue-densemble)
2. [Architecture du pipeline](#2-architecture-du-pipeline)
3. [Les 3 scores de matching](#3-les-3-scores-de-matching)
   - 3.1 [Baseline (mots-clés)](#31-baseline-mots-clés)
   - 3.2 [Sémantique (CamemBERT)](#32-sémantique-camembert)
   - 3.3 [Matching DevSpot (enrichi)](#33-matching-devspot-enrichi)
4. [Le modèle CamemBERT](#4-le-modèle-camembert)
   - 4.1 [Qu'est-ce que CamemBERT ?](#41-quest-ce-que-camembert-)
   - 4.2 [Comment on l'utilise](#42-comment-on-lutilise)
   - 4.3 [Entraînement de la couche de projection](#43-entraînement-de-la-couche-de-projection)
5. [Inférence de compétences](#5-inférence-de-compétences)
6. [Audit de fairness (équité)](#6-audit-de-fairness-équité)
7. [Anonymisation des CV](#7-anonymisation-des-cv)
8. [Cache et performance](#8-cache-et-performance)
9. [Infrastructure et déploiement](#9-infrastructure-et-déploiement)
10. [Endpoints API](#10-endpoints-api)
11. [Évaluation du modèle](#11-évaluation-du-modèle)
12. [Glossaire](#12-glossaire)

---

## 1. Vue d'ensemble

Le système de matching IA de DevSpot met en relation des **offres d'emploi** de recruteurs avec des **profils développeurs** de la plateforme. Il produit un score de compatibilité en pourcentage pour chaque paire offre/candidat, permettant au recruteur de voir les profils les plus pertinents en premier.

Deux niveaux de lecture sont volontairement séparés :

- ce document décrit le **pipeline produit réellement exposé** dans l'application ;
- le document [docs/camembert-algorithme-technique.md](camembert-algorithme-technique.md) décrit en détail la **brique vectorielle CamemBERT**, la projection et le calcul des scores.

### Principes fondamentaux

- **Multimodal** : combine l'analyse par mots-clés, la compréhension sémantique du langage naturel et l'inférence de compétences implicites.
- **Équitable** : un module de fairness mesure et signale les biais potentiels entre profils juniors et seniors.
- **Transparent** : chaque score est décomposé (baseline, sémantique, enrichi) pour comprendre pourquoi un profil matche.
- **Respectueux de la vie privée** : les textes candidats sont dé-identifiés avant traitement IA, et la vue recruteur reste anonyme jusqu'au premier contact réussi.
- **Performant** : les embeddings et les résultats de matching sont mis en cache pour éviter les recalculs inutiles.

---

## 2. Architecture du pipeline

```
┌─────────────────────────────────────────────────────────────────────┐
│                        REQUÊTE DU RECRUTEUR                        │
│                  (offre + profils développeurs)                     │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     OfferMatchingService (PHP)                      │
│  • Convertit les entités en modèles de matching (DTOs)              │
│  • Extrait les compétences depuis les textes (dictionnaire BDD)     │
│  • Construit le texte candidat de matching puis le dé-identifie     │
│  • Orchestre les 3 stratégies de scoring                            │
└──────┬──────────────────┬───────────────────┬──────────────────────┘
       │                  │                   │
       ▼                  ▼                   ▼
┌──────────────┐  ┌───────────────┐  ┌────────────────────┐
│  SkillMatcher│  │  Semantic     │  │  Enriched          │
│  (Baseline)  │  │  Matching     │  │  Matching          │
│              │  │  Service      │  │  Service           │
│  PHP pur     │  │  PHP → Python │  │  PHP → Python      │
│  Mots-clés   │  │  CamemBERT   │  │  CamemBERT +       │
│              │  │              │  │  Inférence skills   │
└──────┬───────┘  └──────┬───────┘  └────────┬───────────┘
       │                 │                    │
       │                 ▼                    ▼
       │         ┌──────────────────────────────────┐
       │         │   Service ML Python (FastAPI)     │
       │         │   Port 8001                       │
       │         │   • CamemBERT (camembert-base)    │
       │         │   • Embeddings 768 dimensions     │
       │         │   • Inférence de compétences      │
       │         └──────────────────────────────────┘
       │                  │                   │
       ▼                  ▼                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      FairnessAuditor (PHP)                          │
│   Mesure le ratio d'impact entre juniors (≤2 ans) et seniors        │
│   Appliqué indépendamment sur chaque stratégie de scoring           │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    RÉSULTATS TRIÉS & PAGINÉS                        │
│        Classement par score Matching DevSpot (enrichi) DESC         │
│        + Cache serveur (30 min TTL, filesystem)                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Composants principaux

| Composant | Langage | Rôle |
|-----------|---------|------|
| `OfferMatchingService` | PHP | Orchestrateur principal du pipeline |
| `SkillMatcher` | PHP | Scoring baseline par overlap de mots-clés |
| `SemanticMatchingService` | PHP | Scoring sémantique via CamemBERT |
| `EnrichedMatchingService` | PHP | Score enrichi = sémantique + bonus d'inférence |
| `CandidateSkillInferenceService` | PHP | Appels au service ML pour inférer les compétences |
| `AiMatchingClient` | PHP | Client HTTP vers le service Python avec cache |
| `CvAnonymizer` | PHP | Anonymisation des données personnelles |
| `FairnessAuditor` | PHP | Audit d'équité junior/senior |
| Service ML FastAPI | Python | Modèle CamemBERT, embeddings, inférence |

---

### 2.1 Flux produit actuel

Le pipeline réellement utilisé dans l'application suit la séquence ci-dessous :

1. construction d'un texte candidat à partir du profil public ;
2. dé-identification de ce texte avant envoi au service IA ;
3. extraction des compétences explicites ;
4. calcul du score baseline par overlap ;
5. calcul du score sémantique CamemBERT ;
6. inférence de compétences implicites par règles ;
7. calcul du score enrichi DevSpot ;
8. tri final ;
9. exposition d'une vue recruteur **anonyme par défaut** ;
10. révélation de l'identité seulement si une conversation existe déjà ou après un premier contact réussi.

Concrètement, le matching côté recruteur fonctionne maintenant avec deux états :

- **carte anonyme** : `candidateLabel`, scores, années d'expérience, compétences correspondantes et inférées ;
- **carte révélée** : nom, headline, lien profil, conversation et favoris.

La transition entre les deux états est déterminée par l'existence d'une conversation recruteur ↔ candidat, sans table supplémentaire dédiée.

---

## 3. Les 3 scores de matching

Chaque paire offre/candidat reçoit **3 scores** indépendants, ce qui permet de comparer les approches et de comprendre les résultats.

### 3.1 Baseline (mots-clés)

Le score baseline est un **overlap pur** entre les compétences demandées par l'offre et celles du candidat.

**Formule :**

$$\text{score} = \min\!\Big(1.0,\; \underbrace{0.65 \times \frac{|\text{hardSkills}_{\text{offre}} \cap \text{hardSkills}_{\text{candidat}}|}{|\text{hardSkills}_{\text{offre}}|}}_{\text{hard skills}} + \underbrace{0.25 \times \frac{|\text{softSkills}_{\text{offre}} \cap \text{softSkills}_{\text{candidat}}|}{|\text{softSkills}_{\text{offre}}|}}_{\text{soft skills}} + \underbrace{\text{juniorBoost}}_{\text{+0.10 si ≤2 ans}}\Big)$$

| Paramètre | Poids |
|-----------|-------|
| Hard skills (techniques) | 65% |
| Soft skills (comportementales) | 25% |
| Bonus junior (≤ 2 ans d'expérience) | +10% |

**Avantages** : simple, rapide, explicable.  
**Limites** : ne comprend pas les synonymes, ni le contexte (ex : "React" ≠ "React.js" sauf si les deux sont en base).

---

### 3.2 Sémantique (CamemBERT)

Le score sémantique utilise le modèle de langage **CamemBERT** pour comprendre le *sens* des textes, pas juste les mots exacts.

**Processus :**

1. Le texte de l'offre et le CV du candidat sont transformés en **vecteurs** de 768 dimensions (embeddings).
2. La **similarité cosinus** entre ces deux vecteurs donne un score brut entre -1 et 1.
3. Un **recalibrage linéaire** rend le score plus lisible.

**Formule de recalibrage :**

$$\text{score\_final} = \text{clamp}\!\left(\frac{\text{cosinus} - 0.75}{1.0 - 0.75},\; 0,\; 1\right)$$

**Pourquoi recalibrer ?** Les textes techniques en français ont des cosinus très proches (entre 0.85 et 0.95 en général). Le recalibrage avec un seuil plancher de 0.75 « étire » cette plage pour que les différences deviennent visibles sur une échelle 0–100%.

**Avantages** : comprend les synonymes, le contexte, la sémantique.  
**Limites** : boîte noire, pas de détail sur *quelles* compétences matchent.

---

### 3.3 Matching DevSpot (enrichi)

C'est le **score principal** utilisé pour le classement. Il combine le score sémantique CamemBERT avec un **bonus d'inférence de compétences**.

**Formule :**

$$\text{score\_DevSpot} = \min\!\Big(1.0,\;\max\!\big(0.0,\;\text{score\_sémantique} + \text{bonus}\big)\Big)$$

$$\text{bonus} = \min\!\Big(0.15,\;\text{bonus\_technique} + \text{bonus\_soft}\Big)$$

Le bonus est **plafonné à 15%** pour ne pas surcompenser.

**Bonus technique** (par compétence technique inférée) :

$$\text{bonus\_technique} += 0.08 \times \text{poids\_niveau} \times \text{confiance} \times \text{poids\_match}$$

| Niveau inféré | Poids |
|---------------|-------|
| Débutant | 0.35 |
| Intermédiaire | 0.65 |
| Avancé | 0.85 |

| Type de match | Poids |
|---------------|-------|
| Match direct (même compétence) | 1.0 |
| Match par proximité (compétence liée) | 0.6 |
| Pas de match | 0.0 |

**Bonus soft** (par compétence comportementale inférée) :

$$\text{bonus\_soft} += 0.03 \times \text{confiance}$$

**Avantages** : meilleur des deux mondes — compréhension sémantique + valorisation des compétences implicites.  
**C'est le score affiché en premier et utilisé pour le classement final.**

---

## 4. Le modèle CamemBERT

### 4.1 Qu'est-ce que CamemBERT ?

[CamemBERT](https://camembert-model.fr/) est un modèle de langage **pré-entraîné sur du texte français**. C'est l'équivalent français de BERT (Google), développé par des chercheurs d'INRIA, Facebook AI et la Sorbonne.

| Caractéristique | Valeur |
|-----------------|--------|
| Nom du modèle | `camembert-base` |
| Corpus d'entraînement | OSCAR (138 Go de texte français) |
| Architecture | RoBERTa (variante de BERT) |
| Paramètres | ~110 millions |
| Dimensions d'embedding | 768 |
| Longueur max des tokens | 512 |
| Licence | MIT |

CamemBERT a été entraîné par ses auteurs sur un **corpus massif de texte français** issu du web. Nous n'avons **pas ré-entraîné le modèle lui-même** — nous utilisons ses capacités de compréhension du français telles quelles.

### 4.2 Comment on l'utilise

```
Texte brut (offre ou CV)
         │
         ▼
┌─────────────────────────┐
│  Normalisation           │
│  (NFKC + whitespace)     │
└────────┬────────────────┘
         ▼
┌─────────────────────────┐
│  Tokenisation            │
│  CamemBERT tokenizer     │
│  (max 512 tokens,        │
│   padding, truncation)   │
└────────┬────────────────┘
         ▼
┌─────────────────────────┐
│  Forward pass            │
│  CamemBERT model         │
│  (mode évaluation,       │
│   pas de gradient)       │
└────────┬────────────────┘
         ▼
┌─────────────────────────┐
│  Mean pooling            │
│  Moyenne pondérée par    │
│  le masque d'attention   │
└────────┬────────────────┘
         ▼
┌─────────────────────────┐
│  Projection (optionnel)  │
│  Matrice linéaire         │
│  entraînée sur nos        │
│  données                  │
└────────┬────────────────┘
         ▼
┌─────────────────────────┐
│  Normalisation L2        │
│  Vecteur unitaire        │
│  (768 dimensions)        │
└─────────────────────────┘
```

### 4.3 Entraînement de la couche de projection

Nous n'avons **pas fine-tuné** CamemBERT (ses poids sont gelés). En revanche, nous avons entraîné une **couche de projection linéaire** légère par-dessus.

**Objectif** : Améliorer la qualité des embeddings pour notre cas d'usage spécifique (matching offre ↔ développeur) sans modifier le modèle de base.

**Méthode d'entraînement :**

| Paramètre | Valeur |
|-----------|--------|
| Type | Matrice de projection linéaire (768 × 768) |
| Initialisation | Matrice identité |
| Fonction de perte | CrossEntropyLoss sur la similarité cosinus × 12.0 (temperature scaling) |
| Optimiseur | AdamW (weight decay = 10⁻⁴) |
| Learning rate | 0.005 |
| Epochs | 6 |
| Batch size | 64 |
| Seed | 42 |

**Données d'entraînement :**

Les paires d'entraînement sont générées de manière **faiblement supervisée** (weak supervision) à partir de notre dataset :

- **Paires positives** : offre + candidat dont les compétences se recoupent, étiquetées par niveau de pertinence :
  - Score 3 (fort) → label 1.0
  - Score 2 (moyen) → label 0.67
  - Score 1 (faible) → label 0.33
- **Paires négatives** : offre + candidat aléatoire sans rapport, avec un ratio configurable (par défaut 4 négatifs par offre).

**Fonction de pertinence faible** : évalue automatiquement si un candidat est pertinent pour une offre en vérifiant :
- Le recouvrement de famille de rôle (backend, frontend, fullstack, devops, data, etc.)
- Le recouvrement de mots-clés spécifiques dans chaque famille
- L'adéquation du niveau d'expérience

**Résultat** : un fichier `.pt` contenant la matrice de projection entraînée, chargé au démarrage du service ML.

---

## 5. Inférence de compétences

Le système ne se contente pas des compétences *déclarées* par le candidat. Il **infère des compétences implicites** à partir du texte du CV grâce à un moteur de pattern matching.

### Compétences soft détectées

| Pattern détecté dans le texte | Compétence inférée | Confiance |
|-------------------------------|-------------------|-----------|
| « travail en équipe », « collaboration », « projet en groupe »... | Travail d'équipe | 92% |
| « communication », « présentation », « soutenance »... | Communication | 84% |
| « autonomie », « autonome », « indépendant »... | Autonomie | 80% |
| « mentoring », « lead », « leadership »... | Leadership | 78% |
| « adaptabilité », « polyvalent »... | Adaptabilité | 72% |
| « design system », « UX », « accessibilité »... | Créativité | 66% |

### Compétences transversales détectées

| Pattern | Compétence | Confiance |
|---------|-----------|-----------|
| « résolution de bugs », « debug »... | Résolution de problèmes | 79% |
| « organisation », « planifier », « sprint »... | Organisation | 76% |
| « deadline », « priorisation »... | Gestion du temps | 71% |
| « delivery », « CI/CD », « gestion de projet »... | Gestion de projet | 70% |
| « qualité », « tests », « non-régression »... | Esprit critique | 68% |
| « projet universitaire », « stage », « alternance »... | Agilité d'apprentissage | 64% |

### Compétences techniques détectées

~25 patterns couvrant : Symfony, Doctrine, Twig, Laravel, JavaScript, TypeScript, React, Vue.js, Node.js, Docker, Kubernetes, CI/CD, SQL, PostgreSQL, MySQL, Redis, Linux, Bash, HTML/CSS, développement API, automatisation de tests...

### Niveaux inférés depuis le contexte

| Contexte dans le texte | Niveau attribué | Bonus |
|------------------------|----------------|-------|
| « senior », « lead », « expert », « architecte »... | Avancé | +10% |
| « maintenance », « production », « optimisation »... | Intermédiaire | +8% |
| « stage », « alternance », « projet universitaire »... | Débutant | +0% |
| (pas de contexte) | Débutant | +3% |

### Règles de dérivation technique

Des règles combinatoires avancées permettent de déduire des compétences supplémentaires :
- Symfony + API → infère « développement API » + « Doctrine »
- E-commerce → infère « back office » + « développement API »
- Framework frontend + design system → infère « architecture de composants »

---

## 6. Audit de fairness (équité)

Le module `FairnessAuditor` mesure si le système de matching est **équitable entre profils juniors et seniors**.

### Définitions

- **Junior** : ≤ 2 ans d'expérience
- **Non-junior** : > 2 ans d'expérience

### Métriques calculées

| Métrique | Formule | Interprétation |
|----------|---------|---------------|
| Score moyen juniors | $\text{mean}(\text{scores juniors})$ | Performance moyenne des juniors |
| Score moyen non-juniors | $\text{mean}(\text{scores non-juniors})$ | Performance moyenne des seniors |
| Ratio d'impact disparate | $\frac{\text{moy. juniors}}{\text{moy. non-juniors}}$ | Proche de 1.0 = équitable |
| Effectifs juniors / non-juniors | $\text{count}(\text{group})$ | Vérifie que la comparaison est exploitable |
| Taux de sélection junior / non-junior | $\frac{\text{scores} \ge 0.8}{\text{effectif groupe}}$ | Part des profils au-dessus d'un seuil favorable |
| Ratio de taux de sélection | $\frac{\text{taux junior}}{\text{taux non-junior}}$ | Signal de sous/sur-sélection |
| Écart moyen de score | $\text{moy. junior} - \text{moy. non-junior}$ | Sens et amplitude de l'écart |
| Assessment | règle de lecture | `balanced_selection_rate`, `junior_under_selected`, `junior_over_selected` ou population insuffisante |

Ces métriques ne prouvent pas une fairness générale. Elles mesurent surtout la dimension junior / non-junior, qui est volontairement conservée dans le produit.

### Application

L'audit de fairness est réalisé **indépendamment sur chaque stratégie** :
1. **Baseline** → fairness du scoring par mots-clés
2. **Sémantique** → fairness du scoring CamemBERT
3. **Enrichi** → fairness du scoring DevSpot

Cela permet de comparer comment chaque approche traite les juniors et d'identifier les biais éventuels.

---

## 7. Anonymisation des CV

### 7.1 Objectif

L'anonymisation actuelle vise deux choses distinctes :

1. **réduire l'exposition de l'IA aux identifiants directs** dans le texte candidat ;
2. **masquer l'identité côté recruteur** tant qu'aucun contact n'a été initié.

Il ne s'agit pas encore d'une anonymisation forte par NER ni d'une neutralisation complète de tous les attributs potentiellement biaisants.

### 7.2 Dé-identification du texte envoyé à l'IA

Avant tout traitement par l'IA, le texte candidat de matching est **dé-identifié** par le composant `CvAnonymizer`.

Les éléments suivants sont retirés ou masqués :

| Donnée personnelle | Remplacement |
|-------------------|-------------|
| Prénom / nom injectés dans le texte | supprimés du texte source |
| Adresses email | `[EMAIL]` |
| Numéros de téléphone | `[PHONE]` |
| Adresses postales | `[ADDRESS]` |
| Slug public | `[SLUG]` |
| URLs portfolio / GitHub / LinkedIn | `[URL]` |
| Liens bruts présents dans les champs texte | `[URL]` |

Le contenu métier utile au matching est conservé, par exemple :

- headline ;
- bio ;
- descriptions d'expérience ;
- intitulés de poste ;
- entreprises ;
- compétences déclarées.

L'anonymisation actuelle est **regex-based**. Elle constitue une phase 1 pragmatique de dé-identification, mais ne remplace pas un vrai pipeline NER orienté recherche / fairness.

### 7.3 Vue recruteur anonymisée

Le endpoint de matching ne renvoie plus systématiquement les données nominatives.

Pour un candidat non révélé, la réponse expose principalement :

- `candidateLabel` ;
- `revealed = false` ;
- `canContact` ;
- `contactToken` ;
- les scores ;
- `yearsExperience` ;
- les compétences correspondantes ;
- les compétences inférées.

Pour un candidat non révélé, les champs suivants ne sont pas exposés dans la carte initiale :

- `fullName` ;
- `headline` ;
- `profileUrl` ;
- `developerId` ;
- actions de favoris.

### 7.4 Révélation après contact

L'identité est révélée dans deux cas :

1. une conversation existe déjà entre le recruteur et le candidat ;
2. le recruteur initie un premier contact depuis le matching.

Le flux de révélation repose sur :

- un `contactToken` opaque transmis par le matching ;
- un endpoint dédié de contact ;
- la création d'une conversation recruteur ↔ candidat ;
- un recalcul ultérieur de l'état `revealed` via la conversation existante.

Cette approche permet de concilier :

- anonymisation initiale ;
- contact direct depuis le matching ;
- persistance simple du statut révélé sans stockage supplémentaire.

---

## 8. Cache et performance

### Cache des embeddings (7 jours)

Le client `AiMatchingClient` met en cache les embeddings CamemBERT dans le filesystem Symfony :

| Paramètre | Valeur |
|-----------|--------|
| Backend | `cache.app` (filesystem Symfony) |
| TTL | 604 800 secondes (7 jours) |
| Clé | `ai_matching.{embed|infer}.{sha256_du_texte}` |
| Optimisation batch | Les textes non-cachés sont envoyés en batch au service ML |

### Cache des résultats de matching (30 min)

Les résultats complets du matching sont cachés côté contrôleur :

| Paramètre | Valeur |
|-----------|--------|
| Backend | `cache.app` (filesystem Symfony) |
| TTL | 1 800 secondes (30 minutes) |
| Clé | `matching_offer_{id}` |
| Contenu | Tous les matchs normalisés, scores, fairness, metadata |
| Invalidation | Paramètre `?refresh=1` pour forcer le recalcul |

### Cache mémoire (par requête)

`SemanticMatchingService` maintient un cache en mémoire (dictionnaire PHP) indexé par hash SHA-256 du texte, évitant de recalculer les embeddings d'un même texte au sein d'une requête.

---

## 9. Infrastructure et déploiement

### Services et exécution

Le pipeline de matching dépend de deux briques d'exécution :

- l'application Symfony,
- un service ML FastAPI accessible via `ML_SERVICE_URL`.

Dans l'état actuel du repo, les fichiers Compose visibles documentent surtout l'infrastructure locale annexe (`database`, `mercure`, `adminer`, `mailer`). Le service ML est bien attendu par le code, mais il n'est pas décrit explicitement dans ces fichiers Compose.

### Service ML Python

| Paramètre | Valeur |
|-----------|--------|
| Image | Python 3.11-slim |
| Framework | FastAPI + Uvicorn |
| Port | 8001 |
| Dépendances | torch, transformers, scikit-learn, uvicorn |
| Volume | Cache HuggingFace persisté |
| Modèle | Chargé une fois au démarrage (singleton) |

### Variables d'environnement

| Variable | Défaut | Description |
|----------|--------|-------------|
| `ML_SERVICE_URL` | `http://localhost:8001` | URL du service Python ML |
| `ML_SERVICE_TIMEOUT` | `10` secondes | Timeout des appels HTTP |
| `CAMEMBERT_MODEL` | `camembert-base` | Modèle HuggingFace à charger |
| `CAMEMBERT_DEVICE` | `cpu` | Device PyTorch (cpu/cuda) |
| `CAMEMBERT_PROJECTION_PATH` | — | Chemin vers la matrice de projection entraînée |

---

## 10. Endpoints API

### Service ML Python (port 8001)

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/health` | GET | Vérification de santé du service |
| `/embed` | POST | Texte → vecteur embedding (768 dims) |
| `/embed-batch` | POST | Textes → vecteurs batch |
| `/match` | POST | Offre + candidat → score de similarité sémantique |
| `/infer-skills` | POST | Texte → compétences inférées |
| `/infer-skills-batch` | POST | Textes → compétences inférées batch |

### Application Symfony

| Route | Méthode | Description |
|-------|---------|-------------|
| `/recruiter/offers` | GET | Liste des offres avec indicateur cache |
| `/recruiter/offers/{id}` | GET | Détail d'une offre + matching caché |
| `/recruiter/offers/{id}/matching` | GET | Endpoint JSON du matching (avec pagination) |
| `/recruiter/offers/{id}/matching/contact` | POST | Création du premier contact et révélation du profil |
| `/api/matching/preview` | POST | API JSON pour tester avec un payload brut |
| `/matching/demo` | GET | Page de démo (comptes démo uniquement) |

---

## 11. Évaluation du modèle

### Métriques de qualité

Le script d'évaluation mesure les performances de la couche de projection entraînée :

| Métrique | Description |
|----------|-------------|
| **Recall@1** | Le bon candidat est classé 1er |
| **Recall@3** | Le bon candidat est dans le top 3 |
| **Recall@5** | Le bon candidat est dans le top 5 |
| **MRR** | Mean Reciprocal Rank — rang moyen inversé |
| **nDCG@5** | Normalized Discounted Cumulative Gain @ 5 |

### Tri final des résultats

Les candidats sont triés dans cet ordre de priorité :

1. **Score Matching DevSpot** (enrichi) **décroissant**
2. Score sémantique CamemBERT décroissant (départage)
3. Score baseline décroissant (second départage)
4. Nom alphabétique croissant (dernier recours)

---

## 12. Glossaire

| Terme | Définition |
|-------|------------|
| **Embedding** | Représentation vectorielle d'un texte dans un espace à 768 dimensions |
| **Similarité cosinus** | Mesure de l'angle entre deux vecteurs (1 = identiques, 0 = orthogonaux) |
| **Mean pooling** | Moyenne des embeddings de chaque token, pondérée par le masque d'attention |
| **Projection linéaire** | Transformation matricielle apprise pour adapter les embeddings à notre domaine |
| **Weak supervision** | Étiquetage automatique des données d'entraînement par des heuristiques |
| **Fairness** | Équité de traitement entre différents groupes (ici juniors vs seniors) |
| **Disparate impact ratio** | Rapport des scores moyens entre groupes, idéalement proche de 1.0 |
| **TTL** | Time To Live — durée de vie d'une entrée en cache |
| **Baseline** | Score de référence calculé par overlap de mots-clés (sans IA) |
| **CamemBERT** | Modèle de langage pré-entraîné sur du français (variante française de BERT) |
| **Score DevSpot** | Score final enrichi = sémantique CamemBERT + bonus d'inférence de compétences |

---

## Comparatif des 3 approches

| Aspect | Baseline | Sémantique | Matching DevSpot |
|--------|----------|------------|------------------|
| **Moteur** | Overlap mots-clés (PHP) | CamemBERT embeddings | CamemBERT + inférence |
| **Formule** | 0.65h + 0.25s + boost | Cosinus recalibré | Sémantique + min(15%, bonus) |
| **Avantage junior** | +10% si ≤2 ans | Aucun (pur sémantique) | Via compétences inférées du contexte |
| **Explicabilité** | ✅ Élevée | ❌ Faible (distance vectorielle) | 🔶 Moyenne (détail du bonus) |
| **Dépendance ML** | Non | Oui (service Python) | Oui (service Python) |
| **Audit fairness** | ✅ | ✅ | ✅ |
| **Utilisé pour le tri** | Non | Non | ✅ **Oui** |
