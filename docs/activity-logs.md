# Journalisation des actions majeures

## Objectif

Le journal d'activité permet de conserver une trace exploitable des actions importantes réalisées sur la plateforme DevSpot.

Il sert à :

- suivre les connexions réussies ;
- comprendre le parcours des postulants et recruteurs ;
- rattacher une action à une entité métier précise ;
- conserver des informations techniques utiles à l'audit, comme l'adresse IP ;
- stocker des détails structurés dans `metadata`, notamment les scores de matching.

## Logs applicatifs

### Entité utilisée

Les logs applicatifs sont stockés dans l'entité `App\Entity\ActivityLog`, table SQL `activity_log`.

| Champ | Rôle |
| --- | --- |
| `id` | Identifiant du log |
| `user` | Utilisateur à l'origine de l'action, nullable |
| `action` | Type d'action enregistrée |
| `entityType` | Classe de l'entité concernée, par exemple `App\Entity\JobOffer` |
| `entityId` | Identifiant de l'entité concernée |
| `ipAddress` | Adresse IP de la requête courante |
| `metadata` | Données JSON complémentaires |
| `createdAt` | Date de création du log |

`createdAt` est généré automatiquement dans le constructeur PHP de `ActivityLog`.

### Service central

L'insertion des logs passe par `App\Service\LoggerService`.

Le service centralise :

- la création de l'objet `ActivityLog` ;
- l'association à l'utilisateur ;
- la récupération automatique de l'IP depuis la requête courante ;
- la persistance Doctrine ;
- le choix de flusher immédiatement ou de laisser le log partir avec la transaction métier.

Signature principale :

```php
$loggerService->log(
    action: LoggerService::PROFILE_UPDATE,
    user: $user,
    entityType: DeveloperProfile::class,
    entityId: $profile->getId(),
    metadata: ['step' => 2],
);
```

Le paramètre `flush: false` permet d'ajouter le log dans la même transaction qu'une modification métier déjà flushée plus loin :

```php
$loggerService->log(
    LoggerService::OFFER_PUBLISHED,
    $user,
    JobOffer::class,
    $offer->getId(),
    ['old_status' => 'draft', 'new_status' => 'published'],
    flush: false,
);
```

### Actions actuellement enregistrées

#### `USER_LOGIN`

Déclenché lors d'une connexion réussie.

Point d'intégration :

- `App\Security\UserAuthenticator::onAuthenticationSuccess()`

Entité liée :

- `entityType`: `App\Entity\User`
- `entityId`: identifiant de l'utilisateur connecté

Métadonnées :

```json
{
  "firewall": "main",
  "roles": ["ROLE_APPLICANT", "ROLE_USER"]
}
```

#### `PROFILE_UPDATE`

Déclenché lors de la création ou de la modification d'un profil postulant.

Points d'intégration :

- `ApplicantController::createProfile()`
- `ApplicantController::editProfileStep1()`
- `ApplicantController::editProfileStep2()`
- `ApplicantController::editProfileStep3()`
- `ApplicantController::editProfileStep4()`

Entité liée :

- `entityType`: `App\Entity\DeveloperProfile`
- `entityId`: identifiant du profil modifié

Métadonnées :

```json
{
  "step": 2
}
```

Pour une création de profil :

```json
{
  "step": 1,
  "created": true
}
```

#### `OFFER_PUBLISHED`

Déclenché lorsqu'un recruteur publie une offre.

Points d'intégration :

- `RecruiterController::createOffer()`
- `RecruiterController::toggleOfferStatus()`

Entité liée :

- `entityType`: `App\Entity\JobOffer`
- `entityId`: identifiant de l'offre

Métadonnées à la création :

```json
{
  "status": "published",
  "title": "Développeur Symfony"
}
```

Métadonnées lors d'un changement de statut :

```json
{
  "old_status": "draft",
  "new_status": "published"
}
```

#### `MATCHING_CALCULATED`

Déclenché lorsqu'un matching recruteur est réellement recalculé.

Point d'intégration :

- `RecruiterController::offerMatching()`

Important : aucun log supplémentaire n'est créé quand l'endpoint renvoie un matching déjà présent en cache. Le log correspond donc à une vraie exécution du calcul.

Entité liée :

- `entityType`: `App\Entity\JobOffer`
- `entityId`: identifiant de l'offre matchée

Métadonnées :

```json
{
  "offer": {
    "id": 12,
    "title": "Développeur Symfony"
  },
  "matchesCount": 5,
  "topMatchPercentage": 91.4,
  "fairness": {},
  "semantic": {},
  "enriched": {},
  "scores": [
    {
      "developerId": 34,
      "percentage": 86.2,
      "semanticPercentage": 88.7,
      "semanticEnrichedPercentage": 91.4,
      "scoreBreakdown": {
        "hardSkills": 0.5,
        "softSkills": 0.2
      }
    }
  ]
}
```

Les scores de l'extraction sémantique sont donc conservés dans `metadata` via :

- `semantic` : synthèse du matching sémantique ;
- `enriched` : synthèse du matching enrichi ;
- `scores[*].semanticPercentage` : score sémantique par profil ;
- `scores[*].semanticEnrichedPercentage` : score enrichi par profil ;
- `scores[*].scoreBreakdown` : détail du score métier.

## Logs de modération admin

### Entité utilisée

Les décisions de modération administrative sont stockées dans `App\Entity\AdminActionLog`, table SQL `admin_action_log`.

| Champ | Rôle |
| --- | --- |
| `id` | Identifiant du log |
| `action` | Nature de la décision de modération |
| `adminUser` | Administrateur responsable de la décision |
| `targetUser` | Utilisateur impacté par la décision |
| `reason` | Motivation textuelle de l'action |
| `metadata` | Données JSON contextuelles |
| `createdAt` | Date exacte de création du log |

`createdAt` est renseigné au moment de l'action par le service de modération.

### Service central

Les écritures de modération passent par `App\Service\AdminModerationLogger`.

Le service garantit :

- l'écriture uniquement pour un compte disposant de `ROLE_ADMIN` ;
- la création d'un `AdminActionLog` avec `adminUser`, `targetUser`, `reason`, `metadata` et `createdAt` ;
- l'obligation d'une raison non vide pour l'action `BAN` ;
- la possibilité de persister dans la transaction métier en laissant `flush` à `false`.

Exemple :

```php
$adminModerationLogger->log(
    AdminModerationLogger::BAN,
    $adminUser,
    $targetUser,
    'Comportement abusif confirmé.',
    [
        'previousStatus' => 'active',
        'newStatus' => 'banned',
    ],
);
```

### Actions de modération

| Action | Déclencheur |
| --- | --- |
| `BAN` | Passage du compte au statut `banned` |
| `UNBAN` | Réactivation d'un compte non pending |
| `SUSPEND` | Passage du compte au statut `suspended` |
| `ADD_WHITELIST` | Validation d'un compte pending vers `active` |
| `VALIDATE_PROFILE` | Action disponible pour une validation de profil dédiée |
| `REJECT` | Action disponible pour un refus de compte ou profil |

Les actions de statut utilisateur sont intégrées dans `AdminController` :

- `updateUserStatus()`
- `suspendUser()`
- `banUser()`
- `validateUser()`

### Métadonnées de statut

Pour une modification de statut utilisateur, `metadata` contient au minimum :

```json
{
  "previousStatus": "active",
  "newStatus": "banned",
  "source": "admin_users_quick_action"
}
```

Le champ `source` indique le flux admin qui a déclenché l'action.

### Filtrage de l'historique

Le repository `AdminActionLogRepository` expose deux méthodes ciblées :

```php
$adminActionLogRepository->findByAdmin($adminUser);
$adminActionLogRepository->findByTarget($targetUser);
```

Elles permettent de répondre aux questions :

- qui a banni ou validé un compte ?
- quelles décisions ont impacté un utilisateur donné ?

## Bonnes pratiques

1. Utiliser `LoggerService` pour tout nouveau log applicatif.
2. Mettre une constante d'action dans `LoggerService` quand l'action devient officielle.
3. Renseigner `entityType` et `entityId` dès qu'une entité métier est concernée.
4. Garder `metadata` structuré et stable, sans texte libre difficile à requêter.
5. Eviter de stocker des données sensibles dans `metadata`, notamment mots de passe, tokens, contenus privés longs ou données personnelles non nécessaires.
6. Utiliser `flush: false` quand un contrôleur ou service fait déjà un `flush()` juste après l'action métier.

## Exemple de requêtes utiles

Dernières connexions :

```sql
SELECT *
FROM activity_log
WHERE action = 'USER_LOGIN'
ORDER BY created_at DESC;
```

Historique d'un profil postulant :

```sql
SELECT *
FROM activity_log
WHERE entity_type = 'App\\Entity\\DeveloperProfile'
  AND entity_id = 34
ORDER BY created_at DESC;
```

Derniers calculs de matching :

```sql
SELECT *
FROM activity_log
WHERE action = 'MATCHING_CALCULATED'
ORDER BY created_at DESC;
```
