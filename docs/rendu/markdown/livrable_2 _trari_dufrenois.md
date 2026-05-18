# Livrable 2

**Projet :** DevSpot  
**Titre :** L’intelligence sémantique au service du recrutement équitable  
**Formation :** Master Cyber 2024 - 2026  
**Auteurs :** Trari Mehdi & Dufrénois Mélène  
**Rôles :** Co-développeurs fullstack 

---

## Rappel du projet

DevSpot est une plateforme web permettant à des développeurs de créer un profil professionnel structuré et à des recruteurs de rechercher, consulter, contacter et comparer ces profils. L’objectif n’est pas seulement de proposer un annuaire de candidats, mais de construire un outil d’aide au recrutement capable de valoriser les compétences techniques et humaines de manière plus juste.

---

## Avancement global du projet

À ce stade, le projet a dépassé la phase de simple conception. Les principales briques techniques sont mises en place et plusieurs fonctionnalités sont déjà opérationnelles.   
Les éléments suivants sont réalisés :

* initialisation du projet Symfony ;  
* configuration de la base de données et création des principales entités ;  
* authentification et gestion des rôles ;  
* profils développeurs, profils recruteurs et entreprises ;  
* offres d’emploi ;  
* favoris ;  
* messagerie interne et intégration de Mercure ;  
* notifications ;  
* administration, logs d’activité et d’administration ;  
* documentation technique ;  
* environnement Docker ;  
* base de déploiement production ;  
* service de matching IA ;  
* sécurisation d’une partie importante de l’application ;  
* premiers tests fonctionnels.

---

## Planning initial et positionnement actuel

Le planning initial prévoyait une progression en plusieurs sprints :

| Sprint | Objectif initial | État actuel |
| ----- | ----- | ----- |
| Sprint 1 | Fondations, BDD, authentification, profil développeur | **Réalisé** |
| Sprint 2 | Consultation publique, espace recruteur, formulaire de contact | **Réalisé** |
| Sprint 3 | Recherche, filtres, favoris, administration | **Réalisé (2 tickets en standby)** |
| Sprint 4 | Notifications, sécurité, tests, messagerie temps réel | **Réalisé** |
| Sprint 5 | Matching IA, support, UI, CI/CD, documentation | **En cours** |

Globalement, l’avancement est cohérent avec le planning. Certaines fonctionnalités prévues tardivement, comme le matching IA, la documentation technique ou la réflexion sur le déploiement, ont déjà été engagées. En revanche, certains éléments restent à finaliser, notamment la refonte UI, le support utilisateur, la consolidation de la CI/CD et certains tickets liés au dashboard recruteur.

---

## Conception fonctionnelle

La conception fonctionnelle de DevSpot est organisée autour de trois grands espaces : candidat, recruteur et administrateur.

#### Espace candidat

L’espace candidat permet à un développeur de créer son profil professionnel. Il peut renseigner ses informations principales, son niveau d’expérience, ses compétences, ses expériences professionnelles, ses formations, ses liens externes et son avatar. Le profil peut ensuite être publié ou dépublié.

Cette partie est essentielle, car elle fournit les données utilisées ensuite pour la recherche, la consultation publique et le matching avec les offres d’emploi.

#### Espace recruteur

L’espace recruteur permet de créer un profil recruteur, de rattacher ce profil à une entreprise, de publier des offres d’emploi, de consulter les profils développeurs, d’ajouter des candidats en favoris et de les contacter.

#### Espace administrateur

L’espace administrateur permet de gérer les utilisateurs, les rôles, les statuts et les actions de modération. Il doit permettre de valider ou refuser certains comptes, suspendre ou bannir des utilisateurs, gérer les notifications et conserver une trace des actions importantes.

La conception distingue deux notions importantes : les **notifications** et les **logs d’audit**. Cette séparation permet d’éviter d’envoyer trop de notifications tout en gardant une traçabilité correcte des actions administratives.

---

## Conception technique et documentation associée

L’architecture retenue repose sur une application Symfony principale, complétée par plusieurs services spécialisés.

| Composant | Rôle |
| ----- | ----- |
| Symfony 7 / PHP | Cœur métier, sécurité, contrôleurs, formulaires, vues Twig |
| PostgreSQL | Stockage des données |
| Doctrine ORM | Gestion des entités et des migrations |
| Mercure | Temps réel pour la messagerie |
| FastAPI / Python | Service de matching IA |
| CamemBERT | Modèle utilisé pour l’analyse sémantique |
| Docker Compose | Environnement local reproductible |
| Caddy | Reverse proxy local et cible de production |
| MkDocs | Documentation technique |
| GitHub Actions | Automatisation des contrôles et documentation |

Ce choix permet de garder une application principale cohérente tout en isolant les fonctionnalités spécialisées.

La conception technique de DevSpot est désormais structurée autour des principaux besoins du projet. La base de données couvre les grands domaines fonctionnels de l’application : comptes utilisateurs, profils développeurs, expériences, formations, compétences, profils recruteurs, entreprises, offres d’emploi, favoris, messagerie, notifications ainsi que logs d’activité et d’administration. Elle constitue donc un socle cohérent pour supporter les parcours candidat, recruteur et administrateur.

Certaines briques techniques importantes ont également été conçues ou intégrées. Le matching IA repose sur un service Python/FastAPI séparé du cœur Symfony, afin d’isoler les dépendances liées au machine learning. Il permet de comparer les offres avec les profils développeurs à partir des compétences, du sens des textes et de certaines compétences implicites. Une attention particulière est aussi portée à l’anonymisation et à l’équité du classement.

La messagerie interne a évolué avec l’intégration de Mercure, qui permet de rendre les échanges plus dynamiques grâce à des mises à jour en temps réel. La sécurité est également prise en compte dès la conception, avec la protection des formulaires, la validation serveur, la gestion des accès par rôles, la validation des fichiers uploadés et le contrôle des accès aux données sensibles.

Enfin, une première organisation documentaire et qualité a été mise en place. La documentation est centralisée avec MkDocs, tandis qu’un workflow GitHub Actions permet d’exécuter les contrôles de qualité, les tests PHPUnit, les tests E2E avec Panther et la génération de couverture. La cible de production est aussi anticipée avec une séparation entre l’environnement local et la production, un reverse proxy en frontal et des services internes non exposés publiquement, comme PostgreSQL et le service ML.

Les détails d’implémentation de ces éléments sont volontairement séparés du présent rapport d’avancement et disponibles dans la documentation spécialisée du dépôt GitHub de DevSpot, notamment pour la base de données, le matching IA, Mercure, la sécurité, la CI/CD et le déploiement.

---

## Ce qui reste à faire

Les travaux restants concernent surtout la finalisation et la consolidation.

#### Fonctionnalités

* finaliser tickets liés à l’administration  
* enrichir le dashboard recruteur  
* terminer ou stabiliser le système d’audit  
* finaliser le support utilisateur  
* vérifier les notifications selon les rôles

#### Interface

* corriger certains éléments UI et harmoniser les pages  
* rendre l’expérience plus cohérente entre candidat, recruteur et administrateur

#### Qualité

* compléter et stabiliser les tests  
* vérifier les parcours critiques  
* consolider la CI/CD

Les détails d’implémentation, les difficultés rencontrées, les arbitrages techniques approfondis et les retours d’expérience seront développés dans le rapport final. Ce livrable d’avancement se concentre principalement sur l’état actuel du projet, la conception retenue et les éléments restants à finaliser.

---

## Conclusion

Le projet dispose d’une conception fonctionnelle et technique solide, d’une architecture cohérente et d’une base de données structurée.

Le projet respecte globalement le planning initial, avec une avance sur certains sujets techniques comme la documentation, le matching IA, Mercure et la préparation du déploiement. Les travaux restants concernent principalement la consolidation, les tests, la finalisation de certains tickets et la préparation de la démonstration finale.

DevSpot est donc aujourd’hui dans une phase de transition entre développement fonctionnel et stabilisation. L’enjeu n’est plus seulement de construire les fonctionnalités, mais de garantir un rendu propre, fiable et compréhensible.