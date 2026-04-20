# DevSpot — Documentation CI/CD avec GitHub Actions

> État du projet au 20 avril 2026.  
> Cette documentation décrit la **CI réellement en place** et la **CD visée** mais non encore implémentée.

---

## 1. Objectif

La chaîne CI/CD de DevSpot a pour but de :

- garantir la qualité du code avant intégration,
- bloquer les régressions fonctionnelles et E2E,
- produire une couverture de code exploitable,
- préparer un futur déploiement continu sécurisé vers un VPS.

Aujourd’hui, la partie **CI** est active.  
La partie **CD** n’est **pas encore implémentée**, mais la cible est un déploiement via **SSH sur VPS**.

---

## 2. Fichier source de la pipeline

La pipeline GitHub Actions actuelle est définie dans :

- `.github/workflows/tests.yml`

Elle est déclenchée sur :

- `push`
- `pull_request`

Autrement dit, chaque push et chaque PR déclenchent la vérification automatisée du projet.

---

## 3. Vue d’ensemble de la CI actuelle

La CI actuelle exécute 4 jobs :

1. `quality`
2. `phpunit`
3. `e2e`
4. `coverage`

### Ordre logique d’exécution

```text
push / pull_request
        |
        v
     quality
        |
        v
     phpunit
        |
        v
       e2e
        |
        v
     coverage
```

### Règle importante

Le pipeline est **bloquant** :

- si `quality` échoue, les tests unitaires ne démarrent pas,
- si `phpunit` échoue, les tests E2E ne démarrent pas,
- si `quality`, `phpunit` ou `e2e` échouent, la couverture n’est pas générée.

Cela garantit qu’aucune étape finale n’est atteinte si les étapes amont ne sont pas valides.

---

## 4. Job `quality`

Le job `quality` est la première barrière de qualité statique.

### Environnement

- runner GitHub : `ubuntu-latest`
- PHP : `8.3`
- extensions : `pdo_sqlite`, `sqlite3`
- outil Composer v2
- cache Composer activé

### Étapes

1. checkout du dépôt,
2. installation de PHP,
3. installation des dépendances Composer,
4. exécution de `PHP CS Fixer`,
5. exécution de `PHPStan`.

### Commandes exécutées

- `composer lint:cs`
- `composer lint:stan`

### Rôle de chaque outil

#### PHP CS Fixer

`PHP CS Fixer` vérifie que le style du code est conforme à la convention choisie.

Configuration actuelle :

- fichier : `.php-cs-fixer.dist.php`
- périmètre : `src`, `config`, `bin`
- approche : adoption progressive pour éviter une dette de style trop large sur les tests historiques.

Scripts disponibles :

- `composer lint:cs` : vérification sans modification,
- `composer lint:cs:fix` : correction automatique.

#### PHPStan

`PHPStan` effectue l’analyse statique du code PHP.

Configuration actuelle :

- fichier : `phpstan.neon.dist`
- niveau : `1`
- périmètre : `src`
- cache : `var/phpstan`

Script disponible :

- `composer lint:stan`

### Pourquoi ce job est important

Ce job permet de détecter très tôt :

- les incohérences de style,
- certaines erreurs de typage,
- des problèmes de code avant même de lancer les tests.

---

## 5. Job `phpunit`

Le job `phpunit` lance les tests applicatifs après validation du job `quality`.

### Dépendance

- `needs: quality`

### Commande exécutée

- `composer test:parallel`

### Détail

Le projet utilise `ParaTest` pour paralléliser les tests PHPUnit et réduire le temps total d’exécution.

Cela permet de valider plus rapidement :

- la logique métier,
- les services Symfony,
- les composants unitaires et fonctionnels couverts par la suite `app`.

---

## 6. Job `e2e`

Le job `e2e` lance les tests end-to-end avec Panther.

### Dépendance

- `needs: phpunit`

### Variables d’environnement

- `PANTHER_NO_SANDBOX=1`
- `PANTHER_CHROME_ARGUMENTS='--disable-dev-shm-usage --window-size=1400,2000'`

### Étapes spécifiques

En plus de l’installation standard :

1. installation des drivers navigateur via `bdi`,
2. build des assets front,
3. création du dossier de screenshots,
4. exécution des tests E2E,
5. upload des captures d’écran Panther en artifact en cas d’échec.

### Commandes exécutées

- `vendor/bin/bdi detect drivers`
- `php bin/console tailwind:build`
- `php bin/console asset-map:compile`
- `composer test:e2e`

### Artifact produit

- `panther-error-screenshots`

Cet artifact est utile pour diagnostiquer visuellement un échec E2E dans GitHub Actions.

---

## 7. Job `coverage`

Le job `coverage` génère la couverture de code une fois que toute la chaîne amont est verte.

### Dépendances

- `needs: [quality, phpunit, e2e]`

### Particularité

Ce job active `Xdebug` pour produire les rapports de couverture.

### Commande exécutée

- `composer test:coverage`

### Artifact produit

- `coverage-report`

### Contenu attendu

Le rapport est généré dans :

- `var/coverage/clover.xml`
- `var/coverage/html`

Ce job n’a pas vocation à valider la qualité métier à lui seul, mais à fournir un indicateur complémentaire sur la surface effectivement testée.

---

## 8. Scripts Composer liés à la CI

Les scripts utilisés par la CI sont définis dans `composer.json`.

### Scripts qualité

- `lint:cs`
- `lint:cs:fix`
- `lint:stan`

### Scripts tests

- `test`
- `test:parallel`
- `test:e2e`
- `test:coverage`

### Intérêt

Centraliser les commandes dans Composer permet :

- d’aligner local et CI,
- de simplifier les workflows GitHub Actions,
- de changer les outils ou options à un seul endroit.

---

## 9. Ce que la CI garantit aujourd’hui

À l’instant T, un changement validé par GitHub Actions garantit :

- un style de code cohérent sur le périmètre outillé,
- une analyse statique minimale du code applicatif,
- le passage des tests PHPUnit parallélisés,
- le passage des tests E2E Panther,
- la génération d’un rapport de couverture si tout le reste est vert.

En pratique, la CI sert donc déjà de **pipeline de validation complète** pour la branche d’intégration.

---

## 10. Stratégie de branches visée

Même si le workflow GitHub Actions se déclenche actuellement sur tous les `push` et `pull_request`, la stratégie projet visée est la suivante :

### Branche `develop`

Rôle attendu :

- branche d’intégration,
- branche de validation continue,
- point de convergence des fonctionnalités avant stabilisation.

### Branche `prod`

Rôle attendu :

- branche stable,
- branche déployable,
- reflet de la version en production.

### Conséquence CI/CD attendue

- `develop` doit toujours passer la CI,
- `prod` ne doit recevoir que du code déjà validé,
- le futur déploiement devra être déclenché **uniquement** depuis `prod`.

---

## 11. Limites actuelles de la pipeline

La pipeline actuelle est solide côté validation, mais elle a encore plusieurs limites :

### 1. Pas de déploiement automatique

Il n’existe actuellement :

- aucun job `deploy`,
- aucune publication automatique sur serveur,
- aucune gestion d’environnement `production` via GitHub Actions.

### 2. Pas de filtrage fin par branche

Le workflow déclenche la CI sur tous les `push` / `pull_request`, sans spécialisation par branche.

### 3. PHP CS Fixer volontairement limité

Le périmètre de `PHP CS Fixer` a été restreint au code applicatif (`src`, `config`, `bin`) pour une adoption progressive.  
Les tests ne sont pas encore inclus dans le contrôle de style.

### 4. Niveau PHPStan encore modéré

Le niveau `1` permet une première sécurisation sans explosion de dette technique, mais il reste de la marge pour monter progressivement en exigence.

---

## 12. Cible CD : déploiement sur VPS via SSH

La cible de déploiement envisagée est un **VPS accessible en SSH**.

Cette partie n’est pas encore codée, mais la stratégie recommandée est la suivante.

### Déclenchement visé

Le déploiement doit partir :

- uniquement de la branche `prod`,
- uniquement après réussite complète de la pipeline CI.

### Principe de sécurité

Le déploiement ne doit jamais utiliser de mot de passe en clair.  
Il doit reposer sur :

- une **clé SSH privée** stockée dans les secrets GitHub,
- une vérification de l’hôte via `known_hosts`,
- un utilisateur dédié au déploiement sur le VPS.

### Secrets GitHub recommandés

Exemples de secrets à prévoir :

- `VPS_HOST`
- `VPS_PORT`
- `VPS_USER`
- `VPS_SSH_PRIVATE_KEY`
- `VPS_KNOWN_HOSTS`
- éventuellement `APP_ENV_PROD_FILE` ou secrets applicatifs si nécessaire.

### Étapes de déploiement recommandées

Un futur job `deploy` pourrait faire, par exemple :

1. checkout du dépôt,
2. vérification que tous les jobs CI sont passés,
3. chargement de la clé SSH dans un agent,
4. connexion au VPS,
5. récupération du code sur `prod`,
6. installation des dépendances de production,
7. migrations Doctrine si nécessaire,
8. warmup du cache Symfony,
9. build éventuel des assets,
10. redémarrage contrôlé des services.

### Exemple de logique cible

```text
push sur prod
     |
     v
 quality -> phpunit -> e2e -> coverage
     |
     v
   deploy
     |
     v
 connexion SSH au VPS
     |
     v
 mise à jour applicative sécurisée
```

---

## 13. Recommandations pour la future CD

Pour éviter les déploiements fragiles, il est recommandé de prévoir :

### Déploiement atomique

Idéalement :

- déploiement dans un nouveau répertoire de release,
- bascule d’un symlink `current` vers la nouvelle version,
- rollback facile en cas de problème.

### Utilisateur dédié

Créer un utilisateur de déploiement spécifique sur le VPS avec des droits limités.

### Protection de la branche `prod`

Activer sur GitHub :

- branche protégée,
- PR obligatoire,
- checks obligatoires,
- interdiction de push direct si possible.

### Environnement GitHub `production`

Déclarer un environnement GitHub Actions `production` pour :

- isoler les secrets,
- ajouter des règles d’approbation si nécessaire,
- tracer les déploiements.

### Rollback

Prévoir dès le départ une stratégie de retour arrière.

---

## 14. Commandes utiles en local

Pour reproduire localement une bonne partie de la CI :

### Qualité

```bash
composer lint:cs
composer lint:stan
```

### Correction automatique du style

```bash
composer lint:cs:fix
```

### Tests

```bash
composer test:parallel
composer test:e2e
composer test:coverage
```

Cela permet de détecter localement les problèmes avant push GitHub.

---

## 15. Feuille de route recommandée

### Étape 1 — Stabilisation CI

Déjà en place :

- `PHP CS Fixer`
- `PHPStan`
- `PHPUnit`
- `Panther`
- couverture

### Étape 2 — Montée en exigence

À prévoir ensuite :

- élargir `PHP CS Fixer` aux tests,
- augmenter progressivement le niveau de `PHPStan`,
- ajouter éventuellement des seuils de couverture si besoin.

### Étape 3 — Mise en place de la CD

À implémenter :

- job `deploy` sur `prod`,
- secrets SSH GitHub,
- déploiement sécurisé sur VPS,
- rollback et observabilité.

---

## 16. Résumé en une phrase

La CI/CD de DevSpot repose aujourd’hui sur une **CI complète de validation avec GitHub Actions** (qualité statique, tests, E2E, couverture), et prépare une **future CD sécurisée vers un VPS via SSH**, déclenchée uniquement depuis `prod` après succès total de la pipeline.
