# DevSpot — Plan d'implémentation détaillé du matching sémantique junior-aware

## 1. But fonctionnel

L'objectif n'est pas seulement d'ajouter un score IA supplémentaire.
Le but est de construire un pipeline de matching capable de :

- conserver la baseline actuelle pour servir de référence,
- ajouter un score sémantique basé sur un modèle NLP,
- enrichir les profils juniors avec des compétences implicites,
- comparer les approches directement dans l'interface DevSpot,
- mesurer l'impact sur la fairness junior / non-junior.

En pratique, le système final devra pouvoir afficher pour une même offre :

- le score baseline actuel,
- le score sémantique brut,
- le score enrichi par inférence de compétences implicites,
- les compétences détectées explicitement,
- les compétences inférées,
- les indicateurs fairness de chaque approche.

---

## 2. Problème métier à résoudre

Aujourd'hui, la baseline favorise les candidats qui décrivent déjà très bien leurs compétences avec les bons mots-clés.
Cela pénalise souvent les juniors pour trois raisons :

1. ils ont moins d'expérience professionnelle explicite,
2. ils décrivent leurs projets en termes d'activités et non de compétences,
3. leurs soft skills et compétences transférables sont rarement structurées.

Exemples typiques à transformer :

- "projet de groupe" → `teamwork`
- "présentation orale" → `communication`
- "travail en autonomie sur un projet" → `autonomy`
- "résolution de bugs complexes" → `problem solving`
- "organisation d'un sprint étudiant" → `project management`

Le chantier technique consiste donc à enrichir le texte brut et les profils avec un niveau intermédiaire de compréhension métier.

---

## 3. Principe général de la solution

La solution cible repose sur trois couches de score conservées en parallèle.

### 3.1 Score 1 — Baseline

Le score déjà présent dans Symfony reste inchangé comme référence.
Il continue à utiliser :

- hard skills détectées,
- soft skills détectées,
- bonus junior,
- règles métier simples et explicables.

Ce score est utile car :

- il est interprétable,
- il sert de témoin scientifique,
- il permet de voir immédiatement si le modèle IA apporte une vraie amélioration.

### 3.2 Score 2 — Sémantique brut

Le service ML calcule déjà un score de proximité sémantique entre :

- le texte de l'offre,
- le CV anonymisé / texte du profil.

Ce score doit être conservé tel quel pour comparaison.
Il représente la version "modèle brut".

### 3.3 Score 3 — Sémantique enrichi junior-aware

C'est ce troisième score qui constitue la vraie contribution de recherche.
Le pipeline enrichira le profil candidat avant le matching en :

- extrayant les compétences explicites,
- inférant des compétences implicites,
- renforçant certaines compétences transférables,
- recalculant un score sémantique sur un texte enrichi,
- combinant éventuellement ce score avec la baseline.

---

## 4. Architecture cible dans le repo

La structure existante est déjà adaptée. L'extension proposée est la suivante :

```text
DevSpot/
├── src/
│   ├── Service/
│   │   ├── AiMatchingClient.php
│   │   ├── SemanticMatchingService.php
│   │   ├── CandidateSkillInferenceService.php        ← à créer
│   │   ├── EnrichedMatchingService.php               ← à créer
│   │   └── MatchingComparisonFormatter.php           ← à créer
│   ├── Matching/
│   │   └── Service/
│   │       └── FairnessAuditor.php
│   └── Controller/
│       ├── MatchingDemoController.php
│       └── MatchingPreviewController.php
├── ml/
│   └── app/
│       ├── main.py
│       ├── inference.py
│       ├── preprocessing.py
│       ├── schemas.py
│       └── skill_inference.py                        ← à créer
└── templates/
    └── matching/
        └── demo.html.twig
```

---

## 5. Pipeline technique détaillé

## 5.1 Étape A — Préparation du texte candidat

### Entrées

Le texte candidat doit continuer à être construit à partir de :

- headline,
- bio,
- expériences,
- postes visés,
- éventuellement formation.

### Amélioration proposée

Le texte ne doit plus seulement être un bloc libre.
Il faut produire une représentation structurée :

- `raw_text`
- `anonymized_text`
- `explicit_hard_skills`
- `explicit_soft_skills`
- `inferred_soft_skills`
- `inferred_transferable_skills`
- `enriched_text`

### Pourquoi

Cette étape permet de ne plus envoyer au modèle un texte brut trop flou.
On lui envoie aussi une version enrichie avec des indices métier plus explicites.

---

## 5.2 Étape B — Inférence de compétences implicites

C'est la nouvelle brique principale.

### Idée

Créer un service d'inférence qui analyse les descriptions d'expériences, bios et projets, puis renvoie des compétences probables.

### Exemple

Entrée :

```text
Participation à un projet universitaire à 4, présentation finale devant jury, correction de bugs et organisation du travail en sprint.
```

Sortie attendue :

```json
{
  "inferred_soft_skills": ["teamwork", "communication", "organization"],
  "inferred_transferable_skills": ["project management", "problem solving"],
  "confidence": {
    "teamwork": 0.92,
    "communication": 0.81,
    "organization": 0.78,
    "project management": 0.64,
    "problem solving": 0.73
  }
}
```

### Stratégie technique recommandée

Commencer par une approche hybride en deux niveaux.

#### Niveau 1 — Règles explicables

Créer un mapping métier simple côté Python ou Symfony :

- expressions observées,
- verbes d'action,
- contextes scolaires,
- indicateurs de travail collectif,
- indicateurs de leadership ou autonomie.

Exemples de règles :

- `projet en groupe|travail en équipe|binôme` → `teamwork`
- `présentation|soutenance|pitch` → `communication`
- `corriger des bugs|débugger|résoudre` → `problem solving`
- `organiser|planifier|coordonner` → `organization`
- `seul|autonome|indépendant` → `autonomy`

Cette première version a trois avantages :

- elle est rapide à implémenter,
- elle est explicable dans le mémoire,
- elle sert de baseline pour l'inférence.

#### Niveau 2 — Modèle NLP supervisé ou assisté

Ensuite, on peut faire évoluer cette brique vers :

- classification multi-label de soft skills,
- extraction de compétences implicites à partir d'un dataset annoté,
- approche zero-shot ou few-shot si le dataset est encore petit.

Le plus réaliste dans le projet actuel est de démarrer par le niveau 1, puis de préparer la structure pour passer au niveau 2.

---

## 5.3 Étape C — Enrichissement du profil

Une fois les compétences implicites détectées, il faut construire une version enrichie du candidat.

### Sortie cible

```json
{
  "candidate_id": "cand-123",
  "explicit_skills": ["react", "typescript", "html", "css"],
  "inferred_skills": ["teamwork", "communication", "problem solving"],
  "enriched_text": "Frontend Developer React TypeScript HTML CSS teamwork communication problem solving ..."
}
```

### Règle métier importante

Les compétences inférées ne doivent pas remplacer les compétences explicites.
Elles doivent être stockées séparément pour :

- pouvoir les afficher dans l'interface,
- mesurer leur impact,
- les désactiver si nécessaire,
- éviter de brouiller l'interprétation scientifique.

---

## 5.4 Étape D — Calcul des scores

Pour chaque couple offre / candidat, calculer les scores suivants.

### Score baseline

Score déjà existant.
Aucun changement majeur.

### Score semantic raw

Score sémantique calculé sur :

- texte d'offre brut,
- texte candidat anonymisé brut.

### Score semantic enriched

Score sémantique calculé sur :

- texte d'offre enrichi ou normalisé,
- texte candidat enrichi avec compétences implicites.

### Score final hybride (optionnel)

Une formule de combinaison peut être introduite :

$$
score_{final} = \alpha \cdot score_{baseline} + \beta \cdot score_{semantic\_raw} + \gamma \cdot score_{semantic\_enriched}
$$

avec :

$$
\alpha + \beta + \gamma = 1
$$

Exemple initial raisonnable :

- $\alpha = 0.35$
- $\beta = 0.20$
- $\gamma = 0.45$

L'idée est de privilégier le score enrichi sans perdre l'ancrage métier de la baseline.

---

## 6. Implémentation Symfony détaillée

## 6.1 Nouveau service `CandidateSkillInferenceService`

### Emplacement

`src/Service/CandidateSkillInferenceService.php`

### Responsabilités

- appeler le service ML d'inférence de compétences,
- recevoir les compétences implicites,
- normaliser les libellés,
- filtrer par score de confiance minimal,
- construire un objet ou tableau d'enrichissement.

### Sortie attendue

```php
[
    'inferredSoftSkills' => ['teamwork', 'communication'],
    'inferredTransferableSkills' => ['problem solving'],
    'confidence' => [
        'teamwork' => 0.92,
        'communication' => 0.81,
        'problem solving' => 0.73,
    ],
]
```

---

## 6.2 Nouveau service `EnrichedMatchingService`

### Emplacement

`src/Service/EnrichedMatchingService.php`

### Responsabilités

- préparer le texte enrichi offre / candidat,
- combiner explicit skills et inferred skills,
- appeler `SemanticMatchingService`,
- produire le score `semantic_enriched`,
- produire éventuellement le score final hybride.

### Pourquoi un service séparé

Il ne faut pas alourdir `OfferMatchingService` avec toute la logique expérimentale.
Le service actuel doit rester lisible.

Le plus propre est :

- `OfferMatchingService` orchestre,
- `SemanticMatchingService` calcule le sémantique brut,
- `CandidateSkillInferenceService` enrichit,
- `EnrichedMatchingService` calcule le matching enrichi.

---

## 6.3 Adaptation de `OfferMatchingService`

Le payload retourné à la démo devra évoluer pour inclure plusieurs blocs de score.

### Structure cible par candidat

```php
[
    'percentage' => 72.5,
    'score' => 0.725,
    'semanticPercentage' => 84.2,
    'semanticScore' => 0.842,
    'semanticEnrichedPercentage' => 88.7,
    'semanticEnrichedScore' => 0.887,
    'finalHybridPercentage' => 81.4,
    'finalHybridScore' => 0.814,
    'matchedHardSkills' => [...],
    'matchedSoftSkills' => [...],
    'inferredSoftSkills' => [...],
    'inferredTransferableSkills' => [...],
]
```

### Avantage

En gardant tous les scores dans le même payload, le site peut comparer directement les approches sans calcul additionnel côté front.

---

## 6.4 Adaptation du contrôleur et de la démo

### Contrôleurs concernés

- `src/Controller/MatchingDemoController.php`
- `src/Controller/MatchingPreviewController.php`

### Vue concernée

- `templates/matching/demo.html.twig`

### Ce que la page doit afficher

Pour chaque candidat :

- score baseline,
- score semantic raw,
- score semantic enriched,
- score final hybride,
- hard skills explicites matchées,
- soft skills explicites matchées,
- compétences implicites inférées,
- texte anonymisé,
- fairness par approche.

### Bloc fairness à prévoir

Il faudra afficher au minimum :

- fairness baseline,
- fairness semantic raw,
- fairness semantic enriched,
- fairness final hybride.

Ainsi, la démo devient directement un outil expérimental utilisable pour le mémoire.

---

## 7. Implémentation ML détaillée

## 7.1 Nouveau endpoint d'inférence de compétences

### Endpoint proposé

```http
POST /infer-skills
```

### Payload

```json
{
  "text": "Description d'experience, bio ou projet étudiant..."
}
```

### Réponse

```json
{
  "inferred_soft_skills": ["teamwork", "communication"],
  "inferred_transferable_skills": ["problem solving"],
  "confidence": {
    "teamwork": 0.92,
    "communication": 0.81,
    "problem solving": 0.73
  }
}
```

### Fichier cible

- `ml/app/skill_inference.py`
- adaptation de `ml/app/schemas.py`
- adaptation de `ml/app/main.py`

---

## 7.2 Première implémentation recommandée

### Version 1 — Heuristique NLP assistée

Dans `ml/app/skill_inference.py` :

- définir un référentiel de compétences inférables,
- définir une liste de patterns linguistiques,
- attribuer des scores de confiance,
- renvoyer les compétences détectées.

Cette version est idéale pour lancer rapidement l'expérimentation.

### Version 2 — Modèle de classification

Ensuite, remplacer ou compléter ce module par :

- un classifieur multi-label,
- un modèle fine-tuné sur des descriptions d'expériences annotées,
- un système zero-shot pour explorer les labels.

---

## 8. Référentiel de compétences à introduire

Pour ne pas produire des libellés incohérents, il faut créer un vocabulaire contrôlé.

### Catégories minimales

- `soft skills`
- `transferable skills`
- `hard skills`

### Exemples de compétences implicites prioritaires

- teamwork
- communication
- autonomy
- adaptability
- problem solving
- organization
- critical thinking
- project management
- leadership
- time management

### Pourquoi ce référentiel est important

Il évite de générer plusieurs variantes pour la même idée :

- `travail d'équipe`
- `team work`
- `collaboration`
- `travail collectif`

Toutes doivent converger vers une valeur normalisée.

---

## 9. Données à stocker ensuite en base

Même si la première phase peut fonctionner sans persistance, la cible projet doit prévoir un stockage.

### À stocker par calcul

- texte anonymisé,
- texte enrichi,
- compétences explicites,
- compétences implicites inférées,
- score baseline,
- score semantic raw,
- score semantic enriched,
- score final hybride,
- version du modèle,
- date de calcul.

### Intérêt

Cela permet :

- de rejouer les expériences,
- de comparer les versions du modèle,
- d'alimenter le mémoire,
- d'auditer les effets sur les juniors.

---

## 10. Évaluation scientifique dans le site

Le fait de conserver tous les scores côte à côte dans DevSpot est une très bonne décision.

### Pourquoi

Cela permet de comparer directement :

- ce que voit la baseline,
- ce que comprend le modèle brut,
- ce que change l'enrichissement des compétences implicites,
- l'effet final sur le rang du candidat.

### Vue comparative recommandée

Pour chaque offre dans la démo, afficher un tableau ou blocs avec :

- rang baseline,
- rang semantic raw,
- rang semantic enriched,
- rang final hybride,
- variation de rang,
- statut junior / non-junior.

### Exemple de lecture métier

- Amir : bon match dans toutes les approches,
- Nora : faux positif possible en semantic raw,
- Junior peu explicite : amélioration attendue surtout en semantic enriched.

C'est précisément cette comparaison qui donne de la valeur au travail de recherche.

---

## 11. Métriques à suivre

## 11.1 Qualité de matching

- précision,
- recall,
- F1-score,
- top-1 accuracy,
- top-3 accuracy,
- top-k ranking.

## 11.2 Qualité de l'inférence de compétences

- précision des compétences inférées,
- rappel des compétences implicites attendues,
- cohérence des labels,
- taux de faux positifs.

## 11.3 Fairness junior

- score moyen junior,
- score moyen non-junior,
- ratio junior / non-junior,
- présence des juniors dans le top-3,
- présence des juniors dans le top-5,
- variation de rang des juniors entre baseline et enrichi.

---

## 12. Ordre d'implémentation recommandé

### Sprint 1 — Brique d'inférence simple

- ajouter `/infer-skills` dans le service ML,
- créer `CandidateSkillInferenceService`,
- détecter quelques soft skills implicites via règles,
- enrichir le payload candidat.

### Sprint 2 — Score enrichi

- créer `EnrichedMatchingService`,
- calculer `semantic_enriched`,
- afficher baseline vs semantic raw vs semantic enriched.

### Sprint 3 — Comparaison complète dans la démo

- ajouter les fairness par approche,
- ajouter les variations de rang,
- afficher les compétences inférées.

### Sprint 4 — Calibration scientifique

- régler les poids du score final hybride,
- analyser les faux positifs,
- vérifier l'impact sur les juniors,
- préparer les tableaux de résultats du mémoire.

### Sprint 5 — Évolution modèle

- tester un modèle plus adapté à la similarité de phrases,
- préparer un dataset annoté pour l'inférence de compétences,
- fine-tuner ou remplacer le modèle brut si nécessaire.

---

## 13. Risques techniques et garde-fous

### Risque 1 — Trop de faux positifs sur le sémantique

Exemple : un profil data ou QA peut paraître proche d'une offre front simplement parce que les textes sont tous deux techniques.

#### Réponse

- garder la baseline,
- garder le score brut visible,
- ne jamais remplacer totalement les autres scores,
- ajouter un score hybride mieux contrôlé.

### Risque 2 — Compétences implicites trop généreuses

L'inférence peut sur-attribuer des soft skills.

#### Réponse

- seuil minimal de confiance,
- compétences inférées séparées des compétences explicites,
- audit manuel sur un échantillon.

### Risque 3 — Système trop opaque

#### Réponse

- afficher le détail des compétences inférées,
- conserver la baseline,
- expliquer chaque couche du score.

---

## 14. Définition de fini

Le chantier sera considéré comme techniquement abouti quand :

- le projet retourne toujours le score baseline,
- le projet retourne le score semantic raw,
- le projet retourne le score semantic enriched,
- le site affiche les trois scores côte à côte,
- les compétences implicites sont visibles dans la démo,
- la fairness est calculée pour chaque approche,
- il est possible de comparer l'effet de l'enrichissement sur les juniors directement depuis l'interface.

---

## 15. Conclusion

La vraie valeur du projet ne réside pas seulement dans l'ajout d'un modèle NLP.
Elle réside dans la capacité à mieux représenter les profils juniors, à transformer leurs expériences en compétences interprétables, puis à comparer objectivement l'apport de cette couche d'intelligence avec les scores déjà présents.

Le fait de conserver tous les scores dans DevSpot est donc non seulement utile techniquement, mais central pour la démonstration scientifique du mémoire.
