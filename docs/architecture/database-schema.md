# Schéma complet de la BDD DevSpot

## Périmètre

Ce document décrit le schéma relationnel observé dans le dépôt à partir des migrations Doctrine présentes dans `migrations/`, de `Version20260220142708.php` (20 février 2026) à `Version20260421101500.php` (21 avril 2026).

La base applicative couvre :

- 20 tables métier
- 1 table technique Symfony Messenger : `messenger_messages`

Note : selon l'environnement, Doctrine Migrations ajoute aussi la table technique `doctrine_migration_versions`. Elle ne fait pas partie du modèle métier documenté ci-dessous.

## Vue d'ensemble

Les tableaux détaillés ci-dessous restent la **source de vérité** pour les colonnes, types et contraintes. Les schémas Mermaid suivants servent de lecture rapide du modèle relationnel.

### Diagramme ER global

<pre class="mermaid">
erDiagram
	USER ||--o| DEVELOPER_PROFILE : porte
	USER ||--o| RECRUITER_PROFILE : porte
	COMPANY ||--o{ RECRUITER_PROFILE : rattache
	RECRUITER_PROFILE ||--o{ JOB_OFFER : publie
	RECRUITER_PROFILE ||--o{ FAVORITE_PROFILE : cree
	DEVELOPER_PROFILE ||--o{ FAVORITE_PROFILE : est_favori
	DEVELOPER_PROFILE ||--o{ PROFILE_SKILL : declare
	SKILL ||--o{ PROFILE_SKILL : reference
	DEVELOPER_PROFILE ||--o{ EXPERIENCE : contient
	EXPERIENCE ||--o{ EXPERIENCE_TECHNOLOGY : utilise
	TECHNOLOGY ||--o{ EXPERIENCE_TECHNOLOGY : reference
	DEVELOPER_PROFILE ||--o{ EDUCATION : contient
	DEVELOPER_PROFILE ||--o{ CONTACT_MESSAGE : recoit
	DEVELOPER_PROFILE ||--o{ DEVELOPER_PROFILE_POSITION : cible
	POSITION ||--o{ DEVELOPER_PROFILE_POSITION : reference

	USER {
		int id PK
		string email
		json roles
		string status
	}
	DEVELOPER_PROFILE {
		int id PK
		int user_id FK
		string slug
		boolean is_public
		string experience_level
	}
	RECRUITER_PROFILE {
		int id PK
		int user_id FK
		int company_id FK
		string job_title
	}
	COMPANY {
		int id PK
		string name
		string size
	}
	JOB_OFFER {
		int id PK
		int recruiter_profile_id FK
		string title
		string status
		string contract_type
	}
	PROFILE_SKILL {
		int id PK
		int developer_profile_id FK
		int skill_id FK
		string level
	}
	EXPERIENCE {
		int id PK
		int developer_profile_id FK
		string company_name
	}
	EDUCATION {
		int id PK
		int developer_profile_id FK
		string school_name
	}
	FAVORITE_PROFILE {
		int id PK
		int recruiter_profile_id FK
		int developer_profile_id FK
	}
</pre>

### Diagramme ER communication, notifications et audit

<pre class="mermaid">
erDiagram
	USER ||--o{ CONVERSATION : participe
	USER ||--o{ MESSAGE : envoie
	USER ||--o{ NOTIFICATION : recoit
	USER ||--o{ ACTIVITY_LOG : declenche
	USER ||--o{ ADMIN_ACTION_LOG : agit
	USER ||--o{ ADMIN_ACTION_LOG : cible
	CONVERSATION ||--o{ MESSAGE : contient

	USER {
		int id PK
		string email
	}
	CONVERSATION {
		int id PK
		int recruiter_id FK
		int developer_id FK
		string status
	}
	MESSAGE {
		int id PK
		int conversation_id FK
		int sender_id FK
		text content
	}
	NOTIFICATION {
		int id PK
		int user_id FK
		string type
		boolean is_read
	}
	ACTIVITY_LOG {
		int id PK
		int user_id FK
		string action
	}
	ADMIN_ACTION_LOG {
		int id PK
		int admin_id FK
		int target_user_id FK
		string action
	}
</pre>

### Découpage fonctionnel

- Authentification et comptes : `user`
- Profils candidat : `developer_profile`, `education`, `experience`, `profile_skill`, `contact_message`
- Référentiels : `position`, `skill`, `technology`
- Tables de jointure : `developer_profile_position`, `experience_technology`
- Espace recruteur : `company`, `recruiter_profile`, `job_offer`, `favorite_profile`
- Interaction et communication : `conversation`, `message`, `notification`
- Audit et administration : `activity_log`, `admin_action_log`
- Technique : `messenger_messages`

### Relations structurantes

- Un `user` peut porter un profil candidat (`developer_profile`) et/ou un profil recruteur (`recruiter_profile`).
- Un `developer_profile` appartient à un seul `user`.
- Un `recruiter_profile` appartient à un seul `user` et peut être rattaché à une `company`.
- Les postes visés d'un candidat passent par `developer_profile_position`.
- Les technologies utilisées dans une expérience passent par `experience_technology`.
- Les compétences d'un candidat passent par `profile_skill`.
- Les offres d'emploi appartiennent à un `recruiter_profile`.
- Les favoris relient un `recruiter_profile` à un `developer_profile`.
- La messagerie interne repose sur `conversation` et `message`, tous deux reliés à `user`.

## Références d'enums

Les enums Doctrine sont stockés en base dans des colonnes `VARCHAR`.

| Enum PHP | Colonnes concernées | Valeurs |
| --- | --- | --- |
| `UserStatus` | `user.status` | `active`, `pending`, `suspended`, `banned`, `deleted` |
| `LocationType` | `developer_profile.location_type`, `job_offer.location_type` | `remote`, `hybrid`, `onsite` |
| `ExperienceLevel` | `developer_profile.experience_level` | `intern`, `junior`, `mid`, `senior`, `lead` |
| `SkillLevel` | `profile_skill.level` | `beginner`, `intermediate`, `advanced`, `expert` |
| `CompanySize` | `company.size` | `micro`, `small`, `medium`, `large`, `enterprise` |
| `ContractType` | `job_offer.contract_type` | `full_time`, `part_time`, `permanent`, `fixed_term`, `internship`, `apprenticeship`, `freelance`, `contract` |
| `OfferStatus` | `job_offer.status` | `draft`, `published`, `closed` |
| `ConversationStatus` | `conversation.status` | `open`, `archived`, `blocked` |
| `NotificationType` | `notification.type` | `account_pending`, `account_approved`, `account_rejected`, `account_suspended`, `account_banned`, `new_message`, `new_conversation`, `message_reply`, `profile_updated`, `profile_published`, `profile_unpublished`, `profile_viewed`, `profile_moderated`, `profile_incomplete`, `profile_favorited`, `profile_unfavorited`, `new_user_pending`, `role_request`, `slug_change_request`, `content_reported`, `admin_message`, `job_offer_created`, `job_offer_updated`, `job_offer_expired`, `system_notification` |

## Authentification et profils

### `user`

Rôle : compte d'authentification, autorisations, statut de cycle de vie.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `email` | `VARCHAR(180)` | non | Unique |
| `roles` | `JSON` | non | Tableau des rôles stocké en JSON |
| `password` | `VARCHAR(255)` | non | Mot de passe hashé |
| `is_verified` | `BOOLEAN` | non | Vérification email |
| `status` | `VARCHAR(255)` | non | Enum `UserStatus` |
| `created_at` | `TIMESTAMP(0)` | non | Horodatage de création |
| `updated_at` | `TIMESTAMP(0)` | non | Horodatage de mise à jour |

Contraintes et relations :

- Index unique : `UNIQ_IDENTIFIER_EMAIL` sur `email`
- Référencé par `developer_profile.user_id` et `recruiter_profile.user_id` en one-to-one
- Référencé par `activity_log`, `admin_action_log`, `notification`, `conversation` et `message`
- Le code ajoute toujours `ROLE_USER` à la lecture, même si ce rôle n'est pas explicitement stocké dans `roles`

### `developer_profile`

Rôle : profil candidat public/interne, CV structuré, visibilité et modération.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `first_name` | `VARCHAR(255)` | non | Prénom |
| `last_name` | `VARCHAR(255)` | non | Nom |
| `headline` | `VARCHAR(255)` | non | Titre / accroche |
| `bio` | `TEXT` | oui | Présentation libre |
| `city` | `VARCHAR(255)` | oui | Ville |
| `country` | `VARCHAR(255)` | oui | Pays |
| `location_type` | `VARCHAR(255)` | oui | Enum `LocationType` |
| `experience_level` | `VARCHAR(255)` | oui | Enum `ExperienceLevel` |
| `years_experience` | `INT` | oui | Nombre d'années |
| `is_public` | `BOOLEAN` | non | Visibilité publique |
| `slug` | `VARCHAR(255)` | non | Identifiant public unique |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `updated_at` | `TIMESTAMP(0)` | non | Mise à jour |
| `avatar_path` | `VARCHAR(255)` | oui | Chemin avatar |
| `cv_pdf_path` | `VARCHAR(255)` | oui | Chemin CV PDF |
| `github_url` | `VARCHAR(255)` | oui | URL GitHub |
| `linkedin_url` | `VARCHAR(255)` | oui | URL LinkedIn |
| `portfolio_url` | `VARCHAR(255)` | oui | URL portfolio |
| `portfolio_generated_at` | `TIMESTAMP(0)` | oui | Date de génération portfolio |
| `moderated_at` | `TIMESTAMP(0)` | oui | Date de modération |
| `moderation_reason` | `VARCHAR(255)` | oui | Motif de modération |
| `user_id` | `INT` | non | FK vers `user.id` |

Contraintes et relations :

- Index uniques : `slug`, `user_id`
- Index de performance : `idx_dev_profile_public_generated_order` sur `(is_public, portfolio_generated_at DESC, updated_at DESC)`
- One-to-one obligatoire vers `user`
- One-to-many vers `experience`, `education`, `profile_skill`, `contact_message`, `favorite_profile`
- Many-to-many vers `position` via `developer_profile_position`

### `recruiter_profile`

Rôle : profil métier du recruteur, relié à un compte utilisateur et éventuellement à une entreprise.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `first_name` | `VARCHAR(255)` | non | Prénom |
| `last_name` | `VARCHAR(255)` | non | Nom |
| `job_title` | `VARCHAR(255)` | non | Poste/fonction |
| `work_email` | `VARCHAR(255)` | oui | Email pro |
| `phone` | `VARCHAR(255)` | oui | Téléphone |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `updated_at` | `TIMESTAMP(0)` | non | Mise à jour |
| `user_id` | `INT` | non | FK vers `user.id` |
| `company_id` | `INT` | oui | FK vers `company.id` |

Contraintes et relations :

- Index unique : `UNIQ_4740AFE9A76ED395` sur `user_id`
- Index simple sur `company_id`
- One-to-one obligatoire vers `user`
- Many-to-one optionnel vers `company`
- One-to-many vers `job_offer` et `favorite_profile`

### `company`

Rôle : entreprise associée à un ou plusieurs profils recruteurs.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `name` | `VARCHAR(255)` | non | Nom |
| `website` | `VARCHAR(255)` | oui | Site web |
| `description` | `TEXT` | oui | Description |
| `industry` | `VARCHAR(255)` | oui | Secteur |
| `size` | `VARCHAR(255)` | oui | Enum `CompanySize` |
| `location` | `VARCHAR(255)` | oui | Localisation libre |
| `logo_path` | `VARCHAR(255)` | oui | Chemin logo |

Contraintes et relations :

- Pas d'unicité SQL sur `name` ou `website`
- Référencée par `recruiter_profile.company_id`

## Référentiels et tables de jointure

### `position`

Rôle : référentiel des postes visés par les candidats.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `name` | `VARCHAR(255)` | non | Libellé unique |

Contraintes et relations :

- Index unique sur `name`
- Many-to-many avec `developer_profile` via `developer_profile_position`
- Le champ `created_at` a été supprimé par migration le 24 février 2026

### `developer_profile_position`

Rôle : table de jointure entre profils candidats et postes souhaités.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `developer_profile_id` | `INT` | non | FK vers `developer_profile.id` |
| `position_id` | `INT` | non | FK vers `position.id` |

Contraintes et relations :

- Clé primaire composite : `developer_profile_id`, `position_id`
- Index simples sur chaque FK
- `ON DELETE CASCADE` sur les deux FKs

### `skill`

Rôle : référentiel des compétences métier.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `name` | `VARCHAR(255)` | non | Libellé unique |
| `category` | `VARCHAR(255)` | oui | Catégorie fonctionnelle |

Contraintes et relations :

- Index unique sur `name`
- Référencée par `profile_skill.skill_id`
- Le champ `created_at` a été supprimé par migration le 24 février 2026

### `profile_skill`

Rôle : association entre un candidat et une compétence, avec niveau et ancienneté.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `level` | `VARCHAR(255)` | oui | Enum `SkillLevel` |
| `years` | `INT` | oui | Années d'expérience sur la compétence |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `developer_profile_id` | `INT` | non | FK vers `developer_profile.id` |
| `skill_id` | `INT` | non | FK vers `skill.id` |

Contraintes et relations :

- Index simples sur `developer_profile_id` et `skill_id`
- Many-to-one obligatoire vers `developer_profile`
- Many-to-one obligatoire vers `skill`
- Attention : l'entité Doctrine déclare une unicité composite `profile_skill_unique` sur `(developer_profile_id, skill_id)`, mais aucune migration présente ne crée cet index unique côté SQL

### `technology`

Rôle : référentiel des technologies utilisées dans les expériences.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `name` | `VARCHAR(255)` | non | Libellé unique |
| `category` | `VARCHAR(255)` | non | Catégorie technique |

Contraintes et relations :

- Index unique sur `name`
- Many-to-many avec `experience` via `experience_technology`
- Le champ `created_at` a été supprimé par migration le 24 février 2026

### `experience`

Rôle : expérience professionnelle d'un candidat.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `company_name` | `VARCHAR(255)` | non | Entreprise |
| `title` | `VARCHAR(255)` | non | Poste |
| `start_date` | `DATE` | non | Début |
| `end_date` | `DATE` | non | Fin |
| `is_current` | `BOOLEAN` | non | Poste actuel |
| `description` | `TEXT` | non | Description |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `updated_at` | `TIMESTAMP(0)` | non | Mise à jour |
| `developer_profile_id` | `INT` | oui | FK vers `developer_profile.id` |

Contraintes et relations :

- Index simple sur `developer_profile_id`
- Many-to-one optionnel vers `developer_profile`
- Many-to-many vers `technology` via `experience_technology`
- Le formulaire autorise un `endDate` vide pour un poste courant, mais le schéma SQL garde `end_date` en `NOT NULL` : l'application doit donc toujours persister une date

### `experience_technology`

Rôle : table de jointure entre expériences et technologies.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `experience_id` | `INT` | non | FK vers `experience.id` |
| `technology_id` | `INT` | non | FK vers `technology.id` |

Contraintes et relations :

- Clé primaire composite : `experience_id`, `technology_id`
- Index simples sur chaque FK
- `ON DELETE CASCADE` sur les deux FKs

### `education`

Rôle : formation d'un candidat.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `school_name` | `VARCHAR(255)` | non | Établissement |
| `degree` | `VARCHAR(255)` | oui | Diplôme |
| `field` | `VARCHAR(255)` | oui | Domaine |
| `start_date` | `DATE` | oui | Début |
| `end_date` | `DATE` | oui | Fin |
| `description` | `TEXT` | oui | Description |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `updated_at` | `TIMESTAMP(0)` | non | Mise à jour |
| `developer_profile_id` | `INT` | oui | FK vers `developer_profile.id` |

Contraintes et relations :

- Index simple sur `developer_profile_id`
- Many-to-one optionnel vers `developer_profile`

## Recrutement, matching et communication

### `job_offer`

Rôle : offre d'emploi publiée ou brouillon par un recruteur.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `title` | `VARCHAR(255)` | non | Titre |
| `description` | `TEXT` | non | Description détaillée |
| `location` | `VARCHAR(255)` | oui | Lieu libre |
| `location_type` | `VARCHAR(255)` | oui | Enum `LocationType` |
| `contract_type` | `VARCHAR(255)` | oui | Enum `ContractType` |
| `experience_level` | `INT` | oui | Niveau requis, stocké en entier libre |
| `salary_min` | `INT` | oui | Fourchette basse |
| `salary_max` | `INT` | oui | Fourchette haute |
| `is_active` | `BOOLEAN` | non | Drapeau historique/technique |
| `status` | `VARCHAR(20)` | non | Enum `OfferStatus`, défaut SQL `published` |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `updated_at` | `TIMESTAMP(0)` | non | Mise à jour |
| `application_deadline` | `DATE` | oui | Date limite de candidature |
| `recruiter_profile_id` | `INT` | non | FK vers `recruiter_profile.id` |

Contraintes et relations :

- Index simple sur `recruiter_profile_id`
- Many-to-one obligatoire vers `recruiter_profile`
- La migration du 15 avril 2026 a ajouté `status` et a rétro-rempli les lignes existantes depuis `is_active`
- Dans le code, `status` est la source de vérité ; `is_active` est synchronisé automatiquement lors des changements de statut

### `favorite_profile`

Rôle : favoris recruteur vers profils candidats.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `created_at` | `TIMESTAMP(0)` | non | Date d'ajout |
| `recruiter_profile_id` | `INT` | non | FK vers `recruiter_profile.id` |
| `developer_profile_id` | `INT` | non | FK vers `developer_profile.id` |

Contraintes et relations :

- Index simples sur les deux FKs
- Index unique composite `favorite_profile_unique_pair` sur `(recruiter_profile_id, developer_profile_id)`
- Many-to-one obligatoire vers `recruiter_profile`
- Many-to-one obligatoire vers `developer_profile`

### `contact_message`

Rôle : message de contact simple envoyé à un profil candidat, distinct de la messagerie temps réel.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `recruiter_name` | `VARCHAR(255)` | non | Nom expéditeur |
| `recruiter_email` | `VARCHAR(255)` | non | Email expéditeur |
| `subject` | `VARCHAR(255)` | non | Sujet |
| `message` | `TEXT` | non | Corps |
| `is_read` | `BOOLEAN` | non | Lu / non lu |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `developer_profile_id` | `INT` | oui | FK vers `developer_profile.id` |

Contraintes et relations :

- Index simple sur `developer_profile_id`
- Index fonctionnel : `idx_contact_message_profile_lower_email` sur `(developer_profile_id, LOWER(recruiter_email))`
- Index d'ordre : `idx_contact_message_profile_created_order` sur `(developer_profile_id, created_at DESC, id DESC)`
- Many-to-one optionnel vers `developer_profile`
- Aucun lien direct vers `user`, `recruiter_profile` ou `conversation`

### `conversation`

Rôle : fil de discussion entre un candidat et un recruteur.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `status` | `VARCHAR(255)` | non | Enum `ConversationStatus` |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `updated_at` | `TIMESTAMP(0)` | non | Mise à jour |
| `subject` | `VARCHAR(255)` | oui | Sujet libre |
| `applicant_user_id` | `INT` | non | FK vers `user.id` |
| `recruiter_user_id` | `INT` | non | FK vers `user.id` |

Contraintes et relations :

- Index simples sur `applicant_user_id` et `recruiter_user_id`
- Deux FKs distinctes vers `user`
- One-to-many vers `message`
- Aucun lien SQL direct vers `job_offer`

### `message`

Rôle : message individuel d'une conversation.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `content` | `TEXT` | non | Contenu |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `is_read` | `BOOLEAN` | non | Lu / non lu |
| `conversation_id` | `INT` | non | FK vers `conversation.id` |
| `sender_user_id` | `INT` | non | FK vers `user.id` |

Contraintes et relations :

- Index simples sur `conversation_id` et `sender_user_id`
- Many-to-one obligatoire vers `conversation`
- Many-to-one obligatoire vers `user`

### `notification`

Rôle : notifications internes adressées à un utilisateur.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `type` | `VARCHAR(255)` | non | Enum `NotificationType` |
| `title` | `VARCHAR(255)` | non | Titre |
| `content` | `TEXT` | non | Contenu |
| `is_read` | `BOOLEAN` | non | Lu / non lu |
| `link` | `VARCHAR(255)` | oui | Lien de navigation |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `user_id` | `INT` | non | Destinataire, FK vers `user.id` |
| `sender_user_id` | `INT` | oui | Expéditeur optionnel, FK vers `user.id` |
| `sender_label` | `VARCHAR(255)` | oui | Libellé de fallback de l'expéditeur |

Contraintes et relations :

- Index simples sur `user_id` et `sender_user_id`
- Many-to-one obligatoire vers le destinataire `user`
- Many-to-one optionnel vers l'expéditeur `user`
- `sender_user_id` est en `ON DELETE SET NULL`

## Audit, administration et technique

### `activity_log`

Rôle : journal d'activité générique côté application.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `action` | `VARCHAR(255)` | non | Action réalisée |
| `entity_type` | `VARCHAR(255)` | oui | Type d'entité ciblée |
| `entity_id` | `INT` | oui | Identifiant ciblé |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `ip_address` | `VARCHAR(255)` | oui | IP source |
| `metadata` | `JSON` | oui | Données complémentaires |
| `user_id` | `INT` | oui | FK vers `user.id` |

Contraintes et relations :

- Index simple sur `user_id`
- Many-to-one optionnel vers `user`

### `admin_action_log`

Rôle : historique des actions d'administration sur les comptes.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `INT IDENTITY` | non | Clé primaire |
| `action` | `VARCHAR(255)` | non | Action admin |
| `reason` | `TEXT` | oui | Justification textuelle |
| `metadata` | `JSON` | oui | Détails structurés |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `admin_user_id` | `INT` | oui | FK vers `user.id` |
| `target_user_id` | `INT` | oui | FK vers `user.id` |

Contraintes et relations :

- Index simples sur `admin_user_id` et `target_user_id`
- Deux FKs vers `user`
- `admin_user_id` et `target_user_id` sont en `ON DELETE SET NULL`
- Contrairement à beaucoup d'autres entités, `created_at` n'est pas initialisé dans le constructeur PHP : il doit être fourni par l'application avant persistance

### `messenger_messages`

Rôle : table technique du transport Doctrine de Symfony Messenger.

| Colonne | Type SQL | Null | Détails |
| --- | --- | --- | --- |
| `id` | `BIGINT IDENTITY` | non | Clé primaire |
| `body` | `TEXT` | non | Payload du message |
| `headers` | `TEXT` | non | En-têtes sérialisés |
| `queue_name` | `VARCHAR(190)` | non | Nom de file |
| `created_at` | `TIMESTAMP(0)` | non | Création |
| `available_at` | `TIMESTAMP(0)` | non | Disponibilité |
| `delivered_at` | `TIMESTAMP(0)` | oui | Date de prise en charge |

Contraintes et relations :

- Index composite `IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750` sur `(queue_name, available_at, delivered_at, id)`
- Aucune relation métier vers les autres tables

## Contraintes et index transverses

### Unicités SQL réellement présentes dans les migrations

- `user.email`
- `developer_profile.slug`
- `developer_profile.user_id`
- `recruiter_profile.user_id`
- `position.name`
- `skill.name`
- `technology.name`
- `favorite_profile(recruiter_profile_id, developer_profile_id)`

### Index de performance non triviaux

- `idx_dev_profile_public_generated_order` sur `developer_profile(is_public, portfolio_generated_at DESC, updated_at DESC)`
- `idx_contact_message_profile_lower_email` sur `contact_message(developer_profile_id, LOWER(recruiter_email))`
- `idx_contact_message_profile_created_order` sur `contact_message(developer_profile_id, created_at DESC, id DESC)`
- Index composite de consommation de file sur `messenger_messages(queue_name, available_at, delivered_at, id)`

### Clés étrangères avec comportement de suppression explicite

- `developer_profile_position.developer_profile_id` : `ON DELETE CASCADE`
- `developer_profile_position.position_id` : `ON DELETE CASCADE`
- `experience_technology.experience_id` : `ON DELETE CASCADE`
- `experience_technology.technology_id` : `ON DELETE CASCADE`
- `admin_action_log.admin_user_id` : `ON DELETE SET NULL`
- `admin_action_log.target_user_id` : `ON DELETE SET NULL`
- `notification.sender_user_id` : `ON DELETE SET NULL`

Le reste des FKs est laissé au comportement par défaut de PostgreSQL, donc sans cascade SQL explicite.

## Points d'attention

### 1. Source de vérité SQL vs ORM

- La présente doc prend les migrations SQL comme source principale de vérité.
- L'entité `ProfileSkill` annonce une contrainte unique composite non reflétée dans les migrations actuelles.

### 2. Champs initialisés surtout par le code

- Plusieurs colonnes `NOT NULL` n'ont pas de défaut SQL et dépendent des constructeurs PHP ou des services applicatifs : `is_read`, `is_public`, `is_active`, `created_at`, `updated_at`, etc.
- Si des insertions sont faites hors Doctrine, il faut fournir ces valeurs explicitement.

### 3. Double statut sur les offres

- `job_offer.status` est le statut métier actuel.
- `job_offer.is_active` subsiste pour compatibilité et est maintenu en cohérence par le code, pas par une contrainte SQL.

### 4. Messagerie simple vs messagerie interne

- `contact_message` sert de prise de contact simple vers un profil.
- `conversation` et `message` constituent la messagerie interne temps réel.
- Il n'existe pas de lien SQL direct entre ces deux sous-systèmes.

### 5. Profils utilisateur

- Le modèle autorise techniquement qu'un même `user` possède un `developer_profile` et un `recruiter_profile`.
- Les droits d'accès sont pilotés par `roles`, pas par une exclusivité imposée en base entre les deux profils.
