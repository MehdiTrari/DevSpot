# DevSpot – Plan détaillé d'intégration de CamemBERT

## 1. Constat actuel

### Ce qui existe déjà
- une application Symfony avec base PostgreSQL,
- un matching heuristique local,
- un module d'anonymisation basique,
- un calcul fairness junior / non-junior,
- un dataset de démo et une page `/matching/demo`.

### Ce qui n'existe pas encore
- aucun modèle NLP branché dans le scoring,
- aucun embedding de CV ou d'offre,
- aucune similarité cosinus,
- aucun pipeline CamemBERT en production locale.

### Conclusion
Le projet est aujourd'hui au stade **baseline fonctionnelle**, pas au stade **matching IA sémantique**.

## 2. Architecture cible

### Principe
Conserver Symfony comme application principale et ajouter un micro-service ML dans le même repo.

### Répartition des responsabilités

#### Symfony
- UI et dashboard
- gestion des profils et offres
- persistance en base
- anonymisation métier
- fairness
- orchestration des appels ML

#### Service Python ML
- chargement du modèle CamemBERT
- prétraitement texte spécifique NLP
- génération d'embeddings
- éventuellement extraction de compétences
- calcul ou retour des éléments nécessaires au scoring sémantique

### Pourquoi ne pas exécuter CamemBERT directement en PHP
- l'écosystème `transformers` est natif Python,
- la maintenance serait bien plus complexe en PHP,
- les performances et la reproductibilité seraient moins bonnes,
- un service dédié est plus propre, testable et extensible.

## 3. Structure cible du repo

```text
DevSpot/
├── src/
├── templates/
├── docs/
├── compose.yaml
└── ml/
    ├── app/
    │   ├── main.py
    │   ├── schemas.py
    │   ├── inference.py
    │   └── preprocessing.py
    ├── requirements.txt
    └── Dockerfile
```

## 4. Étapes techniques

### Phase 1 – Initialiser le service ML
1. Créer le dossier `ml/`.
2. Ajouter `requirements.txt` avec au minimum :
   - `transformers`
   - `torch`
   - `sentence-transformers` si besoin
   - `fastapi`
   - `uvicorn`
   - `numpy`
3. Ajouter `ml/Dockerfile`.
4. Ajouter un service `ml` dans `compose.yaml`.
5. Exposer le port du service ML, par exemple `8001`.

### Phase 2 – Démarrer CamemBERT
1. Choisir le modèle exact.
2. Commencer par une intégration simple :
   - CamemBERT pour embeddings,
   - puis évolution possible vers fine-tuning.
3. Charger le modèle une seule fois au démarrage.
4. Ajouter un endpoint `GET /health`.

### Phase 3 – Définir le contrat API ML

#### Endpoint santé
```http
GET /health
```

Réponse attendue :
```json
{
  "status": "ok",
  "model": "camembert-base"
}
```

#### Endpoint embedding
```http
POST /embed
```

Payload :
```json
{
  "text": "Développeur Symfony avec expérience API et SQL"
}
```

Réponse :
```json
{
  "embedding": [0.12, -0.04, 0.33],
  "dimension": 768
}
```

#### Endpoint matching
```http
POST /match
```

Payload :
```json
{
  "offer_text": "Offre d'emploi...",
  "candidate_text": "CV anonymisé..."
}
```

Réponse :
```json
{
  "semantic_score": 0.84
}
```

## 5. Intégration côté Symfony

### Services à créer
- `src/Service/AiMatchingClient.php`
- `src/Service/SemanticMatchingService.php`

### Responsabilités

#### `AiMatchingClient`
- appeler le service ML,
- gérer les timeouts,
- gérer les erreurs réseau,
- sérialiser/désérialiser les payloads JSON.

#### `SemanticMatchingService`
- préparer le texte CV et offre,
- envoyer les données au service ML,
- calculer ou récupérer le score sémantique,
- renvoyer un score exploitable par l'application.

### Configuration
Ajouter dans `.env` :
```env
ML_SERVICE_URL=http://ml:8001
```

## 6. Pipeline NLP cible

### Étape 1 – Prétraitement
- nettoyage du texte,
- normalisation,
- suppression de bruit,
- harmonisation des accents et ponctuations,
- découpage CV / expériences / compétences si nécessaire.

### Étape 2 – Anonymisation
- masquer email,
- masquer téléphone,
- masquer nom et prénom,
- masquer adresse,
- décider si les noms d'école ou d'entreprise doivent être neutralisés.

### Étape 3 – Encodage sémantique
- encoder le texte de l'offre,
- encoder le texte du profil ou du CV anonymisé,
- produire des vecteurs comparables.

### Étape 4 – Matching
- calculer similarité cosinus,
- convertir la similarité en score lisible,
- comparer les candidats pour une offre donnée.

### Étape 5 – Fairness
- comparer score moyen junior vs non-junior,
- comparer top-3 et top-5,
- mesurer les écarts entre baseline et modèle sémantique.

## 7. Ce qu'il faut stocker en base

À prévoir dans de futures tables ou colonnes :
- texte anonymisé,
- compétences extraites,
- embedding ou référence d'embedding,
- score baseline,
- score semantic,
- version du modèle,
- métriques de fairness,
- date de calcul.

## 8. Dataset et évaluation

### Dataset
Il faut préparer un dataset en français contenant :
- des offres d'emploi,
- des CV ou profils,
- des cas de bons matchs,
- des cas de mauvais matchs,
- des profils juniors,
- des compétences transversales.

### Labels
Prévoir des annotations du type :
- `match fort`
- `match moyen`
- `non match`

### Métriques
- précision
- recall
- F1-score
- top-1 accuracy
- top-3 accuracy
- top-k ranking si vous comparez plusieurs candidats

## 9. Stratégie recommandée pour le mémoire

### Baseline
Conserver le matcher actuel comme point de comparaison scientifique.

### Modèle IA
Ajouter un mode `semantic` avec CamemBERT.

### Comparaison
Comparer :
- baseline mots-clés,
- baseline pondérée actuelle,
- CamemBERT sémantique.

### Ce que cette comparaison vous apporte
- un argument méthodologique solide,
- une preuve d'amélioration ou non,
- une base claire pour discuter performance et biais.

## 10. Fairness junior

### Problème
Les profils juniors ont souvent moins d'expérience explicite dans le texte.

### Ce qu'il faut tester
- sans correction,
- avec rééquilibrage du dataset,
- avec mécanisme de compensation,
- avec détection renforcée des soft skills et compétences transférables.

### Métriques fairness à suivre
- score moyen junior,
- score moyen non-junior,
- ratio junior / non-junior,
- présence des juniors dans le top des recommandations.

## 11. Ce qu'il faut montrer dans la démo

La page de démonstration finale devrait afficher :
- l'offre sélectionnée,
- les candidats classés,
- le score baseline,
- le score CamemBERT,
- les compétences détectées,
- le CV anonymisé,
- les métriques fairness.

## 12. Risques techniques

### Risque 1
CamemBERT brut peut être insuffisant pour un bon matching phrase à phrase.

### Réponse
Prévoir dès le départ la possibilité :
- d'utiliser une variante `sentence-transformer`,
- ou de fine-tuner un modèle de type siamese plus tard.

### Risque 2
Latence trop forte si tout est fait en synchrone.

### Réponse
Prévoir `Messenger` pour les traitements lourds.

### Risque 3
Anonymisation trop faible.

### Réponse
renforcer le module avant d'envoyer les textes au modèle.

## 13. Roadmap concrète

### Sprint 1
- créer `ml/`
- dockeriser le service
- endpoint `/health`
- endpoint `/embed`
- client Symfony minimal

### Sprint 2
- brancher le score sémantique dans la démo
- afficher `baseline` vs `semantic`
- stocker les résultats

### Sprint 3
- préparer dataset annoté
- lancer l'évaluation
- mesurer fairness

### Sprint 4
- améliorer l'anonymisation
- ajouter détection des compétences transversales
- préparer la soutenance

## 14. Formulation correcte pour votre oral

### Ce que vous pouvez dire maintenant
"Nous avons déjà implémenté une baseline de matching avec anonymisation et fairness, qui sert de socle expérimental."

### Ce que vous pourrez dire après intégration
"Nous avons ensuite intégré un modèle français de type CamemBERT dans l'architecture afin d'effectuer un matching sémantique entre CV et offres, puis comparé cette approche à la baseline classique."

## 15. Définition de fini

Le chantier CamemBERT sera réellement terminé quand :
- un service ML tourne dans le repo,
- Symfony peut appeler ce service,
- un score sémantique est visible dans la démo,
- la comparaison baseline vs CamemBERT est disponible,
- la fairness est mesurée sur les deux approches,
- les résultats peuvent être utilisés dans le mémoire et à l'oral.
