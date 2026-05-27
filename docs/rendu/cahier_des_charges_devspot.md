# Cahier des charges

**Projet :** DevSpot  
**Titre :** L’intelligence sémantique au service du recrutement équitable  
**Formation :** Master Cyber 2024 - 2026  
**Auteurs :** Trari Mehdi & Dufrénois Mélène  
**Rôles :** Co-développeurs fullstack  

---

## Présentation générale du projet

DevSpot est une plateforme web de recrutement dédiée aux métiers de la Tech et de l’IT. Elle permet à des développeurs, notamment juniors, de créer un profil professionnel structuré, et à des recruteurs de rechercher, consulter, contacter et comparer ces profils.

L’objectif du projet n’est pas simplement de créer un annuaire de candidats. DevSpot vise à proposer un outil d’aide au recrutement capable de valoriser les compétences techniques, les expériences, les réalisations et les compétences transversales des candidats de manière plus juste.

Le projet répond à un problème fréquent dans le recrutement tech : les entreprises rencontrent des difficultés à identifier les bons profils, tandis que certains candidats, en particulier les profils juniors ou atypiques, peuvent être écartés trop tôt à cause de filtres par mots-clés trop rigides. DevSpot propose donc une approche fondée sur le matching sémantique, l’anonymisation des profils et une meilleure analyse des interactions entre recruteurs et candidats.

---

## 2. Contexte et problématique

Le recrutement dans les métiers du numérique repose encore souvent sur des critères très explicites : intitulé de poste, années d’expérience, technologies précises, diplômes ou mots-clés présents dans un CV. Cette logique peut être efficace pour filtrer rapidement un grand nombre de candidatures, mais elle présente plusieurs limites.

D’abord, elle peut exclure des profils pertinents simplement parce qu’ils n’utilisent pas les mêmes formulations que celles présentes dans une offre d’emploi. Ensuite, elle valorise surtout les compétences directement écrites, sans toujours prendre en compte les compétences implicites, les projets personnels, les expériences associatives, les capacités d’apprentissage ou les soft skills. Enfin, elle peut renforcer certains biais de recrutement, notamment lorsque des informations personnelles influencent consciemment ou inconsciemment l’évaluation du profil.

La problématique à laquelle répond DevSpot peut donc être formulée ainsi :

> Comment concevoir une plateforme de recrutement tech capable d’améliorer la pertinence de l’appariement entre offres et profils développeurs, tout en limitant les biais liés au filtrage lexical et à l’exposition des données personnelles ?

---

## 3. Objectifs du projet

DevSpot poursuit trois grands objectifs : technologique, éthique et métier.

| Objectif | Description | Résultat attendu |
|---|---|---|
| Technologique | Dépasser la simple recherche par mots-clés grâce au matching sémantique. | Identifier des profils pertinents même lorsque les formulations diffèrent entre CV et offres. |
| Éthique | Favoriser un recrutement plus équitable grâce à l’anonymisation des profils. | Réduire l’influence des biais liés aux données personnelles non nécessaires à l’évaluation. |
| Métier | Fournir aux recruteurs un outil de suivi et de pilotage du recrutement. | Prioriser les profils, suivre les échanges et améliorer la qualité des décisions. |

### 3.1 Objectif technologique

Le premier objectif est de dépasser la simple recherche par mots-clés grâce à un système de matching sémantique. L’application doit être capable de comparer une offre d’emploi avec un profil développeur en tenant compte du sens global des informations renseignées.

| Besoin | Description |
|---|---|
| Identifier les compétences explicites | Repérer les compétences techniques clairement renseignées dans les profils. |
| Valoriser les compétences implicites | Déduire certaines compétences à partir d’expériences, projets ou formulations proches. |
| Rapprocher des formulations différentes | Comprendre que deux expressions différentes peuvent avoir un sens proche. |
| Produire un score de pertinence | Aider le recruteur à classer les profils selon leur adéquation avec une offre. |
| Générer une shortlist | Proposer une sélection de profils pertinents pour accélérer le recrutement. |

### 3.2 Objectif éthique

Le deuxième objectif est de favoriser un recrutement plus équitable grâce à une logique de recrutement anonymisé. DevSpot doit limiter l’exposition initiale des informations pouvant entraîner des biais : nom, prénom, âge, genre, origine, localisation précise ou autres données personnelles non nécessaires à l’évaluation technique.

| Données à limiter ou masquer | Finalité |
|---|---|
| Nom et prénom | Éviter une identification trop précoce du candidat. |
| Âge ou date de naissance | Réduire les biais liés à l’âge. |
| Genre | Limiter les biais conscients ou inconscients. |
| Origine ou informations personnelles sensibles | Éviter les discriminations. |
| Localisation précise | Ne conserver que les informations utiles au recrutement. |

L’objectif est de recentrer l’analyse sur les compétences, les expériences, les formations, les projets, les réalisations, les soft skills et le potentiel professionnel.

### 3.3 Objectif métier

Le troisième objectif est de fournir aux recruteurs un outil réellement utile dans leur suivi de recrutement. DevSpot ne doit pas seulement afficher des profils, mais aider à prioriser les candidats, suivre les échanges, identifier les profils engageants et améliorer la qualité des décisions.

| Indicateur recruteur | Utilité |
|---|---|
| Profils contactés | Suivre les candidats déjà approchés. |
| Profils ayant répondu | Identifier les candidats engagés. |
| Profils ajoutés en favoris | Retrouver les profils jugés intéressants. |
| Conversations actives | Suivre les échanges en cours. |
| Conversations sans réponse | Identifier les relances possibles. |
| Profils liés à une offre | Relier les échanges à un besoin concret. |
| Taux de réponse | Mesurer l’efficacité des prises de contact. |
| Niveau d’intérêt estimé | Prioriser les profils les plus engageants. |

---

## 4. Périmètre du projet

### 4.1 Fonctionnalités incluses dans le périmètre

| Domaine | Fonctionnalités incluses |
|---|---|
| Authentification | Inscription, connexion, déconnexion, gestion des rôles. |
| Profil candidat | Création, modification, publication et dépublication du profil développeur. |
| Profil recruteur | Création du profil recruteur et rattachement à une entreprise. |
| Entreprises | Création, association et consultation des entreprises. |
| Offres d’emploi | Création, modification, archivage et utilisation des offres pour le matching. |
| Recherche | Consultation des profils, recherche, filtres, tris et pagination. |
| Favoris | Ajout, retrait et consultation des profils favoris. |
| Messagerie | Conversations, messages, suivi des échanges et temps réel via Mercure. |
| Notifications | Notifications utilisateur selon les actions importantes. |
| Administration | Gestion des utilisateurs, rôles, statuts et actions sensibles. |
| Logs | Logs d’activité et logs d’administration. |
| Dashboards | Dashboard candidat, recruteur et administrateur. |
| IA | Service de matching, anonymisation et scoring. |
| Qualité | Documentation, tests, CI/CD et préparation du déploiement. |

### 4.2 Fonctionnalités secondaires ou évolutives

| Fonctionnalité évolutive | Description |
|---|---|
| Import automatique du CV | Extraire automatiquement les informations depuis un fichier PDF. |
| Compétences implicites avancées | Déduire plus finement les soft skills et compétences transversales. |
| Scoring d’intérêt détaillé | Enrichir l’analyse des interactions entre recruteurs et candidats. |
| Statistiques avancées | Proposer davantage d’indicateurs aux recruteurs. |
| Support utilisateur complet | Mettre en place un système d’aide ou de contact dédié. |
| Recommandations personnalisées | Suggérer des offres ou profils selon l’activité utilisateur. |
| Amélioration UI | Harmoniser davantage les interfaces et les parcours. |
| Validation avancée | Renforcer la validation des profils, recruteurs ou entreprises. |

---

## 5. Acteurs et rôles utilisateurs

DevSpot repose sur trois grands types d’utilisateurs : le candidat, le recruteur et l’administrateur.

| Acteur | Description | Besoins principaux |
|---|---|---|
| Candidat / développeur | Développeur souhaitant présenter son profil professionnel, ses compétences, ses expériences, ses formations et ses projets. | Créer un profil clair, valoriser ses compétences, contrôler la visibilité de son profil, être contacté par des recruteurs et être évalué au-delà des simples mots-clés. |
| Recruteur | Utilisateur professionnel souhaitant rechercher des profils développeurs, publier des offres, sauvegarder des candidats et les contacter. | Créer un profil recruteur, gérer une entreprise, publier des offres, rechercher des candidats, suivre les conversations et prioriser les profils les plus engageants. |
| Administrateur | Utilisateur chargé de superviser la plateforme, les comptes, les rôles, les statuts et les actions sensibles. | Gérer les utilisateurs, contrôler les rôles, modérer les comptes, consulter les logs et garantir la fiabilité de la plateforme. |

---

## 6. Parcours utilisateur global

### 6.1 Visiteur non connecté

Un utilisateur non connecté arrive sur une page d’accueil publique. Cette page ne doit pas afficher directement l’annuaire complet des candidats, car la consultation des profils est liée aux rôles et à la logique de confidentialité.

| Élément de la home publique | Objectif |
|---|---|
| Présentation du concept DevSpot | Expliquer rapidement la valeur de la plateforme. |
| Bénéfices pour les candidats | Montrer comment DevSpot valorise les profils développeurs. |
| Bénéfices pour les recruteurs | Montrer comment DevSpot facilite le sourcing et le suivi. |
| Matching sémantique | Présenter l’intérêt d’une recherche au-delà des mots-clés. |
| Anonymisation | Mettre en avant l’approche de recrutement équitable. |
| Appels à l’action | Rediriger vers l’inscription ou la connexion. |

Après connexion, l’utilisateur doit être redirigé vers le dashboard correspondant à son rôle.

### 6.2 Parcours candidat

| Étape | Action |
|---|---|
| 1 | Le candidat crée un compte. |
| 2 | Il complète son profil développeur. |
| 3 | Il ajoute ses compétences, expériences, formations et liens externes. |
| 4 | Il choisit de publier ou non son profil. |
| 5 | Son profil peut être utilisé dans la recherche et le matching. |
| 6 | Il reçoit des messages ou notifications selon les actions des recruteurs. |

### 6.3 Parcours recruteur

| Étape | Action |
|---|---|
| 1 | Le recruteur crée un compte. |
| 2 | Il complète son profil recruteur. |
| 3 | Il rattache son profil à une entreprise. |
| 4 | Il publie une ou plusieurs offres d’emploi. |
| 5 | Il recherche des candidats via filtres ou matching. |
| 6 | Il consulte des profils anonymisés ou partiellement anonymisés selon les règles définies. |
| 7 | Il ajoute des profils en favoris. |
| 8 | Il contacte des candidats. |
| 9 | Il suit ses interactions via son dashboard. |

### 6.4 Parcours administrateur

| Étape | Action |
|---|---|
| 1 | L’administrateur accède à son espace de gestion. |
| 2 | Il consulte les utilisateurs, les rôles et les statuts. |
| 3 | Il effectue des actions de modération si nécessaire. |
| 4 | Il consulte les logs d’activité et d’administration. |
| 5 | Il vérifie la cohérence des notifications et des actions sensibles. |

---

## 7. Analyse fonctionnelle détaillée

## 7.1 Authentification et gestion des comptes

L’application doit permettre à un utilisateur de créer un compte, se connecter, se déconnecter et accéder uniquement aux fonctionnalités autorisées par son rôle.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Inscription, connexion sécurisée, déconnexion, gestion des rôles, restriction d’accès selon le rôle, protection des routes sensibles, mots de passe hashés, validation des formulaires. |
| Critères d’acceptation | Un utilisateur non connecté ne peut pas accéder aux espaces privés. Un candidat ne peut pas accéder aux fonctionnalités recruteur. Un recruteur ne peut pas accéder aux fonctionnalités administrateur. Un administrateur dispose d’un accès aux pages de supervision prévues. |

---

## 7.2 Profil développeur

Le profil développeur constitue le cœur des données candidat. Il doit permettre de représenter clairement le parcours professionnel et les compétences d’un développeur.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Création et modification du profil, ajout des informations principales, expériences professionnelles, formations, compétences, technologies, liens externes, avatar, publication ou dépublication du profil, consultation du profil public selon les règles de visibilité. |
| Critères d’acceptation | Un candidat peut créer et modifier son profil. Un profil incomplet peut rester privé. Un profil publié peut être consulté selon les règles définies. Les données du profil peuvent être utilisées dans le matching IA. |

---

## 7.3 Profil recruteur et entreprise

Le recruteur doit disposer d’un profil professionnel et pouvoir être rattaché à une entreprise.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Création et modification du profil recruteur, rattachement à une entreprise, création ou association d’une entreprise, consultation des informations liées à l’entreprise, accès aux offres publiées par le recruteur ou son entreprise. |
| Critères d’acceptation | Un recruteur peut compléter son profil. Un recruteur peut être lié à une entreprise. Il peut publier des offres uniquement après avoir les informations nécessaires. Les données recruteur restent cohérentes avec les droits d’accès. |

---

## 7.4 Offres d’emploi

Les offres d’emploi permettent aux recruteurs d’exprimer leurs besoins et servent de base au matching avec les profils développeurs.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Création, modification, suppression, fermeture, réouverture ou archivage d’une offre, description du poste, technologies recherchées, niveau d’expérience attendu, localisation ou modalités de travail, date limite de candidature, statut de l’offre, association à une entreprise ou un recruteur, utilisation dans le service de matching. |
| Critères d’acceptation | Un recruteur peut gérer ses offres. Une offre contient suffisamment d’informations pour être comparée à des profils. Une offre inactive ne doit pas être utilisée comme offre active dans les parcours principaux. Une offre fermée ou en brouillon avec une date limite dépassée doit pouvoir être modifiée, mais elle ne peut être republiée qu’après mise à jour de sa date limite. |

---

## 7.5 Recherche, filtres et favoris

La plateforme doit permettre aux recruteurs de rechercher des profils développeurs selon différents critères.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Recherche textuelle, filtres par compétences, technologies et niveau, tri des résultats, pagination, ajout ou retrait d’un profil en favori, consultation des profils favoris. |
| Critères d’acceptation | Les résultats sont cohérents avec les filtres sélectionnés. Un favori est bien rattaché au recruteur connecté. Un recruteur peut retrouver ses favoris depuis son espace. |

---

## 7.6 Messagerie interne

La messagerie permet au recruteur et au candidat d’échanger directement depuis la plateforme.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Création d’une conversation, envoi et réception de messages, consultation de l’historique, mise à jour dynamique via Mercure, association éventuelle d’une conversation à une offre, suivi des conversations actives ou sans réponse. |
| Critères d’acceptation | Un recruteur peut contacter un candidat. Un candidat peut répondre. Les messages sont conservés. Les échanges sont visibles uniquement par les participants autorisés. La messagerie contribue aux indicateurs du dashboard recruteur. |

---

## 7.7 Notifications

Les notifications permettent d’informer les utilisateurs d’événements importants sans remplacer les logs d’audit.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Notification lors d’un nouveau message ou d’une action importante, distinction selon les rôles, consultation des notifications, marquage comme lu, suppression, filtrage simple et limitation des notifications inutiles. |
| Critères d’acceptation | Les notifications sont visibles par le bon utilisateur. Elles peuvent être lues, marquées comme lues ou supprimées depuis une interface utilisable sur desktop et mobile. Une action administrative sensible peut être tracée sans forcément générer trop de notifications. Les notifications ne remplacent pas les logs. |

---

## 7.8 Logs d’activité et d’administration

Les logs permettent de conserver une trace des actions importantes dans une logique d’audit et de sécurité.

| Élément | Description |
|---|---|
| Fonctionnalités attendues | Journalisation des actions sensibles, journalisation des actions administratives, conservation de l’utilisateur concerné, de l’action réalisée et de la date, consultation par l’administrateur. |
| Critères d’acceptation | Les actions importantes sont traçables. Les logs sont séparés des notifications. L’administrateur peut consulter les événements nécessaires à l’audit. |

---

## 7.9 Dashboards

Après connexion, l’utilisateur doit accéder à un dashboard adapté à son rôle. Le dashboard devient donc la page centrale de l’application pour les utilisateurs connectés.

### Dashboard candidat

| Élément | Description |
|---|---|
| État de complétion du profil | Indique si le profil est suffisamment renseigné. |
| Statut de publication | Affiche si le profil est public ou privé. |
| Dernières notifications | Permet au candidat de voir les événements récents. |
| Derniers messages | Donne accès rapidement aux échanges. |
| Raccourcis | Permet de modifier rapidement le profil. |
| Visibilité du profil | Peut afficher des indicateurs simples liés à l’activité du profil. La visibilité reste à 0% tant que le profil est privé, même si certaines informations sont complétées. |
| Recommandations d’amélioration | Propose des actions contextualisées selon l’état réel du profil : informations manquantes, expérience récente absente, compétences insuffisantes, portfolio non publié ou profil encore privé. |

### Dashboard recruteur

Le dashboard recruteur doit être orienté suivi des interactions avec les candidats. Il ne doit pas seulement afficher quelques compteurs basiques, mais aider le recruteur à piloter ses actions.

| Indicateur | Objectif |
|---|---|
| Profils contactés | Suivre les candidats déjà approchés. |
| Profils ayant répondu | Identifier les candidats qui ont montré un intérêt. |
| Profils favoris | Retrouver les profils jugés pertinents. |
| Conversations actives | Suivre les échanges en cours. |
| Conversations sans réponse | Identifier les profils à relancer. |
| Profils liés à une offre | Relier les échanges à un besoin de recrutement. |
| Taux de réponse | Mesurer l’efficacité des prises de contact. |
| Profils à relancer | Aider le recruteur à prioriser ses actions. |
| Niveau d’intérêt estimé | Classer les profils selon leur engagement. |
| Gestion rapide des offres | Accéder rapidement aux actions utiles sur les offres : détail, modification, fermeture, réouverture, suppression et matching. |

Un score d’intérêt métier interne peut être calculé à partir de signaux simples.

| Signal | Pondération possible |
|---|---:|
| Profil ajouté en favori | +2 |
| Premier contact envoyé | +3 |
| Réponse reçue | +5 |
| Conversation active | +4 |
| Conversation liée à une offre | +3 |
| Action récente | +2 |

Ce score n’a pas vocation à remplacer la décision humaine, mais à aider le recruteur à prioriser les profils les plus engageants.

### Dashboard administrateur

| Élément | Description |
|---|---|
| Nombre d’utilisateurs | Donne une vision globale de la plateforme. |
| Comptes candidats | Suit la population candidate. |
| Comptes recruteurs | Suit la population recruteur. |
| Comptes à valider ou surveiller | Aide à la modération. |
| Dernières actions administratives | Permet de suivre les actions sensibles. |
| Logs récents | Donne accès à la traçabilité. |
| Notifications système | Signale les événements importants. |
| Accès rapide aux pages de gestion | Facilite l’administration quotidienne. |

---

## 8. Architecture technique

L’architecture de DevSpot repose sur une application Symfony principale, complétée par des services spécialisés.

| Composant | Rôle |
|---|---|
| Symfony 7 / PHP | Cœur métier, sécurité, contrôleurs, formulaires et vues Twig. |
| PostgreSQL | Stockage des données applicatives. |
| Doctrine ORM | Gestion des entités, relations et migrations. |
| FastAPI / Python | Service séparé pour le matching IA. |
| CamemBERT | Modèle utilisé pour l’analyse sémantique. |
| Mercure | Temps réel pour la messagerie. |
| Docker Compose | Environnement local reproductible. |
| Caddy | Reverse proxy local et cible de production. |
| MkDocs | Documentation technique. |
| GitHub Actions | Automatisation des contrôles qualité, tests et documentation. |

### 8.1 Application principale

L’application principale est développée avec Symfony 7 et PHP 8. Elle prend en charge la logique métier, les contrôleurs, les formulaires, la sécurité, la gestion des rôles, les vues Twig et les interactions avec la base de données via Doctrine ORM.

### 8.2 Base de données

La base de données repose sur PostgreSQL. Elle stocke les données liées aux utilisateurs, profils, compétences, entreprises, offres, favoris, conversations, messages, notifications et logs.

### 8.3 Service IA

Le matching IA est isolé dans un service séparé basé sur Python et FastAPI. Cette séparation permet de ne pas mélanger les dépendances machine learning avec le cœur Symfony.

| Fonction du service IA | Description |
|---|---|
| Préparation des données | Nettoyer et structurer les textes à comparer. |
| Analyse sémantique | Comparer les profils et les offres selon leur sens. |
| Calcul de scores | Produire des scores de pertinence exploitables. |
| Compétences implicites | Identifier certaines compétences non directement écrites. |
| Expérimentations | Tester différentes variantes du pipeline de matching. |

### 8.4 Temps réel

Mercure est utilisé pour rendre la messagerie plus dynamique. Il permet de recevoir des mises à jour en temps réel, notamment lors de l’envoi ou de la réception de messages.

### 8.5 Documentation et qualité

La documentation technique est centralisée avec MkDocs. Les contrôles qualité et tests sont intégrés via GitHub Actions.

| Outil ou pratique | Utilité |
|---|---|
| PHPUnit | Tests unitaires et fonctionnels. |
| Panther | Tests end-to-end. |
| Analyse statique | Détection de problèmes dans le code. |
| PHP CS Fixer | Harmonisation du style de code. |
| Couverture de tests | Suivi de la qualité des tests. |
| MkDocs | Centralisation de la documentation. |
| GitHub Actions | Automatisation des contrôles. |

---

## 9. Pipeline IA et matching sémantique

Le pipeline IA de DevSpot vise à comparer les profils développeurs avec les offres d’emploi de manière plus intelligente qu’un filtrage lexical classique.

### 9.1 Préparation des données

| Source de données | Utilisation dans le matching |
|---|---|
| Profil développeur | Base générale du candidat. |
| Expériences | Analyse du parcours professionnel. |
| Formations | Prise en compte du niveau et du domaine d’étude. |
| Compétences | Comparaison directe avec les besoins de l’offre. |
| Technologies | Identification des outils et langages maîtrisés. |
| Projets | Valorisation des réalisations concrètes. |
| Offre d’emploi | Expression du besoin recruteur. |
| Compétences attendues | Critères de comparaison principaux. |

### 9.2 Anonymisation

Avant certaines étapes d’analyse ou de consultation, les données personnelles identifiantes doivent être supprimées ou masquées.

| Donnée concernée | Traitement attendu |
|---|---|
| Nom | Masquage ou suppression. |
| Prénom | Masquage ou suppression. |
| Adresse e-mail | Masquage ou suppression. |
| Téléphone | Masquage ou suppression. |
| Localisation précise | Réduction ou généralisation. |
| Âge | Suppression si non nécessaire. |
| Informations personnelles non utiles | Suppression ou non-affichage. |

L’objectif est de permettre une première analyse centrée sur les compétences et le parcours, sans exposer les données personnelles inutiles.

### 9.3 Analyse sémantique

L’analyse sémantique repose sur l’idée que deux textes peuvent être proches en sens même s’ils n’utilisent pas exactement les mêmes mots.

Par exemple, un candidat peut ne pas écrire explicitement “gestion d’équipe”, mais décrire une expérience où il a coordonné un groupe, organisé un projet ou encadré d’autres personnes. Le système doit pouvoir mieux valoriser ce type d’information.

### 9.4 Calcul du score

| Dimension du score | Description |
|---|---|
| Compétences communes | Comparaison entre les compétences du profil et celles de l’offre. |
| Score sémantique | Mesure de proximité entre le contenu du profil et le contenu de l’offre. |
| Compétences implicites | Ajout de signaux issus des expériences, projets ou formulations. |
| Pondération | Ajustement selon l’importance des critères. |
| Équité | Analyse de certains effets de biais dans le classement. |

Le score final doit rester un outil d’aide à la décision. Il ne doit pas remplacer le jugement humain du recruteur.

---

## 10. Base de données

La base de données doit couvrir les principaux domaines fonctionnels de l’application.

### 10.1 Principaux ensembles de tables

| Ensemble | Données concernées |
|---|---|
| Utilisateurs et rôles | Comptes, authentification, permissions. |
| Profils développeurs | Informations candidat, visibilité, avatar, liens. |
| Expériences | Parcours professionnel des candidats. |
| Formations | Parcours académique ou professionnel. |
| Compétences | Compétences techniques ou transversales. |
| Technologies | Langages, frameworks, outils. |
| Profils recruteurs | Informations professionnelles des recruteurs. |
| Entreprises | Structures associées aux recruteurs et offres. |
| Offres d’emploi | Besoins de recrutement. |
| Favoris | Profils sauvegardés par les recruteurs. |
| Conversations | Échanges entre recruteurs et candidats. |
| Messages | Contenu des communications. |
| Notifications | Événements visibles par les utilisateurs. |
| Logs d’activité | Actions importantes côté utilisateur. |
| Logs d’administration | Actions sensibles côté administrateur. |

### 10.2 Contraintes attendues

| Contrainte | Objectif |
|---|---|
| Intégrité relationnelle | Garantir la cohérence des liens entre les tables. |
| Cohérence des rôles | Empêcher les accès non autorisés. |
| Protection des données sensibles | Limiter l’exposition des informations personnelles. |
| Séparation métier / audit | Distinguer les données fonctionnelles des traces de sécurité. |
| Traçabilité | Conserver les actions importantes. |
| Exploitabilité IA | Permettre l’utilisation des données dans le matching. |

---

## 11. Sécurité et protection des données

DevSpot manipule des données personnelles et professionnelles. La sécurité doit donc être intégrée dès la conception.

| Exigence de sécurité | Description |
|---|---|
| Hashage des mots de passe | Stocker les mots de passe de manière sécurisée. |
| Contrôle d’accès par rôles | Limiter les fonctionnalités selon le rôle utilisateur. |
| Protection CSRF | Protéger les formulaires contre les requêtes frauduleuses. |
| Protection XSS | Limiter l’injection de scripts malveillants. |
| Validation serveur | Contrôler les données côté serveur. |
| Vérification des fichiers uploadés | Limiter les risques liés aux fichiers envoyés. |
| Restriction des données sensibles | Empêcher l’accès non autorisé aux données personnelles. |
| Logs des actions sensibles | Permettre l’audit et la traçabilité. |
| Non-exposition des services internes | Ne pas exposer PostgreSQL ou le service IA publiquement. |
| Séparation des environnements | Distinguer local, test et production. |

| Principe | Application dans DevSpot |
|---|---|
| Privacy by Design | Protection des données dès la conception. |
| Moindre privilège | Chaque utilisateur accède uniquement à ce qui est nécessaire. |
| Traçabilité | Les actions importantes sont enregistrées. |
| Minimisation des données | Les données non nécessaires sont limitées. |
| Anonymisation | Les informations identifiantes sont masquées lorsque nécessaire. |

---

## 12. Contraintes techniques

### 12.1 Stack retenue

| Domaine | Technologie retenue |
|---|---|
| Backend | Symfony 7 / PHP 8 |
| Frontend | Twig / Tailwind CSS |
| ORM | Doctrine |
| Base de données | PostgreSQL |
| Temps réel | Mercure |
| Service IA | FastAPI / Python |
| Modèle sémantique | CamemBERT |
| Environnement local | Docker Compose |
| Reverse proxy | Caddy |
| Documentation | MkDocs |
| CI/CD | GitHub Actions |

### 12.2 Contraintes de développement

| Contrainte | Attendu |
|---|---|
| Qualité du code | Code structuré, maintenable et respectant les standards du projet. |
| Architecture | Séparation claire des responsabilités entre contrôleurs, services, entités, formulaires et templates. |
| Base de données | Entités Doctrine cohérentes et migrations versionnées. |
| Interface | Templates harmonisés et expérience utilisateur cohérente. |
| Tests | Tests progressifs sur les parcours critiques. |
| Documentation | Documentation maintenue et accessible depuis le dépôt du projet. |
| Qualité continue | Utilisation d’outils comme PHP CS Fixer, analyse statique et GitHub Actions. |

---

## 13. Organisation du projet

L’organisation du projet repose sur une approche itérative par sprints. Les fonctionnalités sont découpées en User Stories et suivies via GitHub Issues.

### 13.1 Méthodologie

| Élément | Description |
|---|---|
| Gestion du projet | Suivi par tickets et GitHub Issues. |
| Développement | Approche itérative par sprints. |
| Revue | Vérification régulière des fonctionnalités développées. |
| Backlog | Adaptation selon l’avancement réel. |
| Priorisation | Classement des fonctionnalités par valeur métier. |
| MVP | Maintien d’une version fonctionnelle et démontrable. |

### 13.2 Versionnage

Le code source est centralisé sur GitHub. Git est utilisé pour gérer les branches, les commits, les corrections et les évolutions.

### 13.3 Répartition des responsabilités

Trari Mehdi et Dufrénois Mélène interviennent comme co-développeurs fullstack.

| Responsabilité | Description |
|---|---|
| Entités Doctrine | Création et maintenance du modèle de données. |
| Logique métier | Développement des services et contrôleurs Symfony. |
| Formulaires | Création, validation et sécurisation des formulaires. |
| Interface | Intégration Twig et Tailwind CSS. |
| Sécurité | Gestion des accès, protections et validations. |
| Documentation | Rédaction et maintenance de la documentation technique. |
| Matching IA | Conception et intégration du service d’appariement. |
| Anonymisation | Mise en place de la logique de protection des données personnelles. |
| Tests | Stabilisation et vérification des parcours critiques. |

---

## 14. Planning prévisionnel et avancement

Le projet est organisé autour de plusieurs sprints.

| Sprint | Objectif principal | État |
|---|---|---|
| Sprint 1 | Fondations, base de données, authentification, profil développeur | Réalisé |
| Sprint 2 | Consultation publique, espace recruteur, formulaire de contact | Réalisé |
| Sprint 3 | Recherche, filtres, favoris, administration | Réalisé avec quelques tickets en standby |
| Sprint 4 | Notifications, sécurité, tests, messagerie temps réel | Réalisé |
| Sprint 5 | Matching IA, support, UI, CI/CD, documentation | En cours / consolidation |

Le projet a dépassé la phase de conception initiale. Les principales briques techniques sont déjà mises en place. Les travaux restants concernent principalement la stabilisation, la consolidation de l’interface, les tests, certains tickets d’administration, le support utilisateur et l’enrichissement du dashboard recruteur.

---

## 15. Protocole expérimental

Afin d’évaluer l’intérêt du matching sémantique, DevSpot peut être comparé à une approche classique basée sur des mots-clés.

### 15.1 Objectif de l’évaluation

L’objectif est de vérifier si le système de matching sémantique permet de mieux identifier des profils pertinents, en particulier lorsque les candidats n’utilisent pas exactement les mêmes termes que les offres.

### 15.2 Approches comparées

| Approche | Description |
|---|---|
| Approche témoin | Recherche classique par mots-clés. |
| Approche DevSpot | Matching sémantique basé sur le sens des profils et des offres. |
| Approche enrichie | Score combinant compétences explicites, similarité sémantique et compétences implicites. |

### 15.3 Indicateurs possibles

| Indicateur | Utilité |
|---|---|
| Recall@K | Mesurer si un bon profil apparaît dans les K premiers résultats. |
| MRR | Mesurer la qualité du rang du premier bon résultat. |
| NDCG@5 | Évaluer la qualité globale de l’ordre des cinq premiers profils. |
| Top1 changed rate | Observer les changements de premier résultat entre deux variantes. |
| Top5 overlap | Mesurer la stabilité du classement. |
| Comparaison juniors / non-juniors | Analyser les effets possibles sur les profils juniors. |
| Taux de sélection | Comparer les profils retenus à seuil fixe. |
| Analyse des variantes du score | Comprendre l’effet des différentes composantes du pipeline. |

Ces métriques permettent d’évaluer à la fois la pertinence du classement et certains effets liés à l’équité.

---

## 16. Contraintes d’interface et d’expérience utilisateur

L’interface doit être claire, cohérente et adaptée aux différents rôles.

| Exigence UX/UI | Description |
|---|---|
| Home publique claire | Présenter DevSpot aux visiteurs non connectés. |
| Dashboard par rôle | Rediriger chaque utilisateur connecté vers son espace adapté. |
| Navigation simple | Permettre de trouver rapidement les fonctionnalités principales. |
| Distinction des rôles | Adapter les pages aux candidats, recruteurs et administrateurs. |
| Tableaux lisibles | Faciliter la consultation des données. |
| Filtres compréhensibles | Aider le recruteur à affiner ses recherches. |
| Actions visibles | Mettre en avant les actions principales. |
| Cohérence graphique | Harmoniser les pages de l’application. |
| Écrans moins chargés | Éviter une interface trop dense. |
| Messages clairs | Afficher des confirmations et erreurs compréhensibles. |
| Responsive mobile | Adapter les tableaux, cartes, notifications, dashboards et pages d’aide aux petits écrans. Certains contenus secondaires ou lourds, comme les vidéos d’aide, peuvent être masqués sur mobile. |
| États vides explicites | Afficher des messages clairs lorsqu’un utilisateur n’a encore aucune offre, aucun favori, aucune notification ou aucune donnée disponible. |
| Actions contextuelles | Éviter les actions redondantes et afficher l’action la plus utile selon l’état courant, par exemple modifier une offre expirée avant de la réouvrir. |
| Navigation de retour | Prévoir des liens de retour explicites dans les sous-pages importantes, notamment dans les paramètres. |

La priorité est de proposer une application fiable, claire et démontrable, plutôt qu’une interface trop complexe.

---

## 17. Livrables attendus

| Livrable | Description |
|---|---|
| Application web DevSpot | Plateforme fonctionnelle accessible aux différents rôles. |
| Base de données structurée | Modèle relationnel cohérent avec les besoins métier. |
| Service de matching IA | Service permettant de comparer offres et profils. |
| Messagerie fonctionnelle | Échanges entre recruteurs et candidats. |
| Dashboards par rôle | Espaces synthétiques adaptés aux utilisateurs. |
| Documentation technique | Documentation centralisée et maintenue. |
| Rapport d’avancement | Synthèse de l’état du projet. |
| Mémoire ou rapport final | Analyse complète du projet et de ses résultats. |
| Supports de présentation | Éléments pour la soutenance ou démonstration. |
| Tests et validation | Vérification des parcours critiques. |
| Démonstration finale | Présentation fonctionnelle du projet. |

---

## 18. Risques identifiés

Plusieurs risques doivent être pris en compte afin de sécuriser la finalisation du projet.

| Type de risque | Risques identifiés | Mesures de réduction |
|---|---|---|
| Risques techniques | Complexité de l’intégration IA, difficulté à stabiliser le service FastAPI, cohérence entre Symfony et le service IA, gestion du temps réel avec Mercure, dette technique sur certains templates, complexité des dashboards. | Séparer clairement Symfony et le service IA, documenter les choix techniques, tester les parcours critiques et limiter les fonctionnalités avancées si le socle n’est pas stabilisé. |
| Risques fonctionnels | Surcharge fonctionnelle, manque de temps pour finaliser toutes les améliorations, dashboard recruteur trop peu utile, parcours utilisateur trop complexe. | Prioriser les fonctionnalités essentielles, conserver un MVP démontrable, simplifier les parcours et concentrer l’effort sur les fonctionnalités à forte valeur métier. |
| Risques liés aux données | Profils incomplets, données insuffisantes pour évaluer correctement le matching, difficulté à mesurer objectivement la pertinence, anonymisation imparfaite. | Prévoir un protocole expérimental clair, utiliser plusieurs métriques, contrôler manuellement certains résultats et documenter les limites du système. |

---

## 19. Critères de réussite

| Critère | Résultat attendu |
|---|---|
| Profil candidat | Un candidat peut créer, compléter et publier son profil. |
| Parcours recruteur | Un recruteur peut rechercher, consulter, favoriser et contacter des profils. |
| Administration | Un administrateur peut superviser les utilisateurs et les actions importantes. |
| Dashboards | Chaque rôle dispose d’un dashboard adapté. |
| Dashboard recruteur | Le recruteur dispose d’une vraie vision de suivi des interactions. |
| Messagerie | Les échanges fonctionnent correctement et sont sécurisés. |
| Notifications et logs | Les notifications et les logs sont cohérents et séparés. |
| Matching IA | Le service IA produit un score exploitable. |
| Anonymisation | La protection des données personnelles est prise en compte. |
| Sécurité | Les parcours principaux sont protégés. |
| Documentation | Le projet est documenté. |
| Démonstration | Une démonstration complète peut être réalisée. |

---

## 20. Conclusion

DevSpot est un projet de plateforme de recrutement tech qui combine des enjeux fonctionnels, techniques, éthiques et expérimentaux. Le projet cherche à répondre aux limites du filtrage lexical classique en proposant une approche plus sémantique, plus équitable et plus centrée sur le potentiel réel des candidats.

Le cahier des charges définit un périmètre complet mais progressif : un socle Symfony robuste, des espaces adaptés aux candidats, recruteurs et administrateurs, une messagerie interne, des dashboards par rôle, une logique de matching IA et une attention particulière portée à l’anonymisation et à la sécurité.

La priorité finale est de livrer une application cohérente, démontrable, documentée et suffisamment stable pour montrer l’intérêt d’un recrutement tech plus intelligent et plus juste.
