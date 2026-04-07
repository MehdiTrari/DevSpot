# US15 — Amélioration de la performance

Date: 10/03/2026  
Branche: `feature/US15`

## 1) Objectif de l'US

Réduire les lenteurs perçues sur les pages les plus consultées sans modifier le comportement fonctionnel:

- Accueil (listing des portfolios publics)
- Profil public développeur
- Messagerie candidat

---

## 2) Où étaient les coûts

### A. Home: listing public + skills

La home affiche des cartes avec des compétences (`profileSkills -> skill`).
Sans optimisation, Doctrine peut produire un effet **N+1**:

- 1 requête pour récupérer les profils
- puis des requêtes supplémentaires pour les relations lazy

### B. Profil public: page riche en relations

La page profil utilise beaucoup de relations:

- `profileSkills`, `skill`
- `experiences`, `technologies`
- `education`
- `desiredPositions`

Si chargé en lazy, chaque bloc peut déclencher des requêtes en plus.

### C. Cache HTTP absent sur pages publiques

Même quand rien n'a changé, le serveur recalculait entièrement les pages publiques.
On ne profitait pas des réponses `304 Not Modified`.

### D. Index SQL incomplets

Certaines requêtes filtrées/triées fréquemment n'avaient pas d'index adaptés.

---

## 3) Modifications faites

## 3.1 Optimisation Doctrine — Home

Fichier: `src/Repository/DeveloperProfileRepository.php`

Méthode optimisée: `findPublicGeneratedProfilesPaginated()`

### Avant

- Requête paginée directe sur entités
- Risque de lazy loading sur les skills affichées

### Après (stratégie 2 étapes)

1. Requête paginée **uniquement sur les IDs** (`SELECT d.id ... LIMIT/OFFSET`)  
2. Requête d'hydratation sur ces IDs avec `LEFT JOIN` + `addSelect` sur:
   - `d.profileSkills`
   - `profileSkills.skill`

Puis on réordonne en PHP pour respecter l'ordre paginé initial.

### Pourquoi c'est mieux

- Pagination stable
- Beaucoup moins de requêtes secondaires
- Meilleur temps de réponse quand le volume augmente

---

## 3.2 Optimisation Doctrine — Profil public

Fichiers:

- `src/Repository/DeveloperProfileRepository.php`
- `src/Controller/ProfileController.php`

Nouvelle méthode repository: `findPublicPortfolioBySlugWithDetails(string $slug)`

Elle précharge les relations utiles à l'affichage via joins:

- `profileSkills` + `skill`
- `experiences` + `technologies`
- `education`
- `desiredPositions`

Dans le contrôleur, `show()` utilise désormais cette méthode au lieu d'un `findOneBy(['slug' => ...])` simple.

### Pourquoi c'est mieux

- Réduction du N+1 sur la page la plus riche en données
- Moins d'aller-retours DB

---

## 3.3 Cache HTTP conditionnel (ETag / Last-Modified)

Fichiers:

- `src/Controller/HomeController.php`
- `src/Controller/ProfileController.php`
- `src/Repository/DeveloperProfileRepository.php`

### Home (`/`)

- Cache appliqué **uniquement pour visiteurs anonymes**
- Ajout de `Last-Modified` (date de dernière mise à jour d'un portfolio public)
- `max-age` / `s-maxage` à 60s
- Si non modifié: retour `304` sans recalcul de page

Nouvelle méthode repository utilisée: `findLatestPublicGeneratedProfileUpdate()`.

### Profil public (`/profil/{slug}`)

- Cache appliqué **uniquement sur GET anonyme + profil public**
- Ajout de:
  - `Last-Modified`
  - `ETag` (id profil + état public + timestamps)
- TTL: 120s
- Si non modifié: `304`

### Cas connectés

- Réponses marquées `private` + `no-store`
- But: ne jamais mettre en cache des vues dépendantes utilisateur (ex: contact déjà établi)

---

## 3.4 Index SQL ajoutés

Fichier: `migrations/Version20260310151000.php`

Index créés:

1. `idx_dev_profile_public_generated_order`  
   Table: `developer_profile`  
   Colonnes: `(is_public, portfolio_generated_at DESC, updated_at DESC)`

2. `idx_contact_message_profile_lower_email`  
   Table: `contact_message`  
   Colonnes: `(developer_profile_id, LOWER(recruiter_email))`

3. `idx_contact_message_profile_created_order`  
   Table: `contact_message`  
   Colonnes: `(developer_profile_id, created_at DESC, id DESC)`

### Pourquoi ces index

- 1: accélère le listing home filtré + trié
- 2: accélère le check “recruteur a déjà contacté ce profil” avec `LOWER(...)`
- 3: accélère le listing messages candidat trié par date/id

---

## 4) Validation faite

- Lint container Symfony: OK
- Vérification syntaxe PHP des fichiers modifiés: OK
- Migration détectée en `New` via `doctrine:migrations:status`: OK

Note tests: l'exécution PHPUnit locale échoue ici à cause d'un driver SQLite manquant (`could not find driver`), pas à cause du code de l'US.

---

## 5) Comment mesurer l'impact chez toi

## A. Profiler Symfony (toolbar)

Comparer avant/après sur:

- `/`
- `/profil/{slug}`
- `/applicant/messages`

Regarder:

- nombre de requêtes SQL
- temps total DB
- temps total requête HTTP

## B. Vérifier le cache HTTP

Sur page publique anonyme:

- Premier hit: `200`
- Hits suivants (avec `If-None-Match`/`If-Modified-Since`): `304`

## C. Vérifier plan SQL (optionnel)

Utiliser `EXPLAIN ANALYZE` sur les requêtes concernées pour confirmer l'usage des nouveaux index.

---

## 6) Commandes à exécuter après pull

```bash
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
php bin/console tailwind:build
```

Puis relancer ton flux local habituel (`symfony serve`, `tailwind:build --watch`, etc.).

---

## 7) Idées d'optimisation phase suivante (si besoin)

- Mettre en cache applicatif (Symfony Cache) le `countPublicGeneratedProfiles()` sur courte durée
- Ajouter une pagination côté messages candidats
- Ajouter un endpoint de stats léger si la home grossit fortement
- Créer des tests de perf de non-régression (budget SQL par page)
