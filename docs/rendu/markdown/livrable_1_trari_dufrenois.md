# Livrable 1

**Projet :** DevSpot  
**Titre :** L’intelligence sémantique au service du recrutement équitable  
**Formation :** Master Cyber 2024 - 2026  
**Auteurs :** Trari Mehdi & Dufrénois Mélène  
**Rôles :** Co-développeurs fullstack 

---

## Description

DevSpot est une plateforme de recrutement innovante dédiée aux métiers de la Tech et de l'IT, conçue pour neutraliser les biais cognitifs lors de l'embauche.   
En s'appuyant sur un moteur d'extraction sémantique, l'application analyse intelligemment les CV et les offres d'emploi pour identifier les compétences réelles au-delà des mots-clés. Pour garantir une égalité des chances totale, DevSpot repose sur un système d'anonymisation rigoureux, permettant aux recruteurs de se concentrer exclusivement sur le potentiel technique et humain des candidats.

---

## Objectif

DevSpot comprend 3 objectifs principaux :  
**Technologique** : Le matching sémantique

* Analyse Profonde : Dépasser la simple recherche par mots-clés pour comprendre le contexte des expériences (ex: comprendre qu'un candidat ayant géré des "infrastructures massives" possède des compétences en scalabilité, même si le mot n'est pas écrit).  
* Matching Haute Précision : Faire correspondre les besoins réels des entreprises avec les compétences extraites des profils IT pour réduire les erreurs de casting.

**Éthique** : Le recrutement "Blind" (aveugle)

* Suppression des Biais : Éliminer les discriminations conscientes ou inconscientes liées au genre, à l'origine, à l'âge ou au secteur géographique.  
* Focus sur le Talent : Forcer l'évaluation du candidat sur ses réalisations, ses formations et ses soft skills réels.  
* Diversité dans la Tech : Favoriser l'inclusion au sein des équipes techniques en présentant des profils basés uniquement sur la méritocratie technique.

**Business** : 

* Qualité des Shortlists : Proposer aux recruteurs des listes de candidats ultra-qualifiés dont la pertinence a été validée par l'algorithme sémantique.  
* Marque Employeur : Permettre aux entreprises de démontrer leur engagement concret en faveur de la diversité et de l'inclusion.

---

## Planning

Pour répondre à tous ces objectifs nous avons créé de nombreuses User Story, chacune répondant à un besoin et/ou ajoutant une fonctionnalité.   
Ci-dessous nos sprint prévisionnels :

| Jalon | Sprint | Objectifs et US |
| :---- | :---- | :---- |
| S8 \- S9 | Sprint 1 : Fondations & Cœur Produit | Initialisation, BDD, Auth (US1-5), Création profil, Portfolio, Upload photo, Liens externes, Publication (US6-12). |
| S10 \- S11 | Sprint 2 : Consultation & Recruteurs | Templates erreur, Page profil public, Annuaire paginé, Formulaire contact, Dashboard recruteur (US13-24). |
| S12 \- S13 | Sprint 3 : Recherche Avancée et accès admin | Moteur de recherche avec filtres, tris et gestion des favoris (US24-29). \+ Gestion administrateur. |
| S14 \- S17 | Sprints 4 & 5 : Algorithmes IA | Implémentation du pipeline d'anonymisation et du système de matching vectoriel. |

---

## Organisation du travail

L'organisation de notre travail repose sur une répartition claire des tâches et l'utilisation d'outils collaboratifs modernes pour assurer la traçabilité et la sécurité du code.

Méthodologie et Collaboration : 

* Approche Itérative : Chaque sprint se termine par une revue des fonctionnalités développées (US) pour ajuster les priorités du backlog.  
* Gestion de Projet : Suivi des tâches via Github (issues).  
* Versionnage : Utilisation de Git avec un dépôt distant sur GitHub pour la centralisation du code source.

Stack Technique et Environnement : 

* Développement : Environnement local sous Symfony 7 (Php 8), utilisant Composer pour les dépendances.  
* Qualité et CI/CD : Mise en place d'une CI/CD via GitHub Actions : exécution automatisée des tests unitaires et fonctionnels à chaque "push" pour garantir la non-régression.  
  * Analyse statique du code pour vérifier le respect des standards de sécurité et de syntaxe.  
* Sécurisation : Protection native contre les failles CSRF/XSS, validation stricte des formulaires et contrôle d'accès basé sur les rôles.

Principes de Priorisation et Flexibilité  
Pour garantir le respect des délais et la qualité des livrables, l'équipe applique les règles suivantes :

* Priorité à la Valeur Métier : Les fonctionnalités sont priorisées selon leur valeur métier afin de garantir la livraison d'un MVP fonctionnel dès la fin du Sprint 3\.  
* Gestion du Flux : Si les tâches prévues pour un sprint sont terminées avant la fin de celui-ci, l’équipe pourra intégrer des User Stories prévues pour les sprints suivants selon leur priorité.  
* Périmètre Principal : Les fonctionnalités d’amélioration seront réalisées uniquement si le périmètre principal est terminé avant la date de livraison.

Rôles et Responsabilités : Trari Mehdi & Dufrénois Mélène : Co-développeurs Fullstack.

* Responsables du développement des entités Doctrine, de la logique métier (Controllers Symfony) et de l'intégration UI (Twig/Tailwind) .  
* Focus spécifique sur l'architecture Privacy by Design et l'implémentation de la logique de vectorisation (Embeddings).