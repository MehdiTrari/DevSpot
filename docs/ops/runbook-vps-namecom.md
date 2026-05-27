# Runbook VPS, Name.com et CD

Cette page décrit le chemin le plus simple pour mettre DevSpot en ligne sur `devspot.software` sans partir sur une infrastructure complexe.

## Choix retenu

Pour ce dépôt, le bon point de départ est :

- **un VPS Linux unique**,
- **Docker Compose** pour exécuter la stack existante,
- **Ansible** pour préparer le serveur et déployer,
- **GitHub Actions** pour la CD sur `main`.

Nous **ne mettons pas Terraform dans la boucle pour l'instant**. Terraform devient utile quand l'infrastructure est pilotée par API côté hébergeur : VM, réseau privé, load balancer, volumes managés, DNS provider, base managée. Tant que vous n'avez pas encore choisi un provider d'infrastructure précis, Terraform ajouterait surtout de la complexité.

## Architecture visée

Le dépôt contient déjà la stack de production dans `compose.prod.yaml`.

La cible de déploiement est donc :

- un VPS Ubuntu 22.04 ou 24.04,
- `devspot.software` pointé vers l'IP publique du VPS,
- `mercure.devspot.software` pointé vers la même IP,
- Caddy exposé en `80/443`,
- Symfony, PostgreSQL, Mercure et le service ML démarrés via Docker Compose.

## Prérequis minimaux

Avant la première mise en ligne, il vous faut :

1. un VPS public avec accès SSH et `sudo`,
2. une clé SSH dédiée au déploiement,
3. le domaine `devspot.software`,
4. un dépôt GitHub contenant ce code,
5. les secrets de production prêts dans un fichier `.env.prod`.

## DNS sur Name.com

Dans Name.com, créez les enregistrements suivants :

1. un enregistrement `A` pour `@` vers l'IP publique du VPS,
2. un enregistrement `A` pour `mercure` vers la même IP,
3. optionnellement un enregistrement `CNAME` pour `www` vers `devspot.software`.

Le certificat TLS sera ensuite géré automatiquement par Caddy une fois les DNS propagés.

## Bootstrap initial du VPS

Le bootstrap est volontairement séparé de la CD. Il se lance une seule fois depuis votre machine.

### 1. Préparer l'inventaire

Copiez `ansible/inventory/production.ini.example` vers `ansible/inventory/production.ini`, puis remplacez :

- `ansible_host` par l'IP ou le nom du serveur,
- `ansible_user` par votre utilisateur SSH,
- `ansible_port` si vous n'utilisez pas `22`.

### 2. Installer la collection Ansible

```bash
python -m pip install ansible
ansible-galaxy collection install -r ansible/requirements.yml
```

### 3. Exécuter le bootstrap

```bash
ansible-playbook -i ansible/inventory/production.ini ansible/playbooks/bootstrap.yml
```

Ce playbook :

- installe Docker Engine et le plugin Compose,
- démarre Docker au boot,
- ajoute l'utilisateur SSH au groupe `docker`,
- prépare le dossier `/opt/devspot`.

## Premier déploiement manuel

Créez d'abord un fichier `.env.prod` à partir de `.env.prod.example`, puis renseignez vos vraies valeurs.

Exemple de première release :

```bash
cp .env.prod.example .env.prod
DEVSPOT_ENV_FILE=$PWD/.env.prod ansible-playbook -i ansible/inventory/production.ini ansible/playbooks/deploy.yml
```

Le playbook de déploiement :

- synchronise le dépôt sur le serveur,
- copie `.env.prod` sur la cible,
- exécute `docker compose up -d --build`,
- lance les migrations Doctrine,
- nettoie les images Docker non utilisées.

## Secrets GitHub pour la CD

Une fois le bootstrap validé, configurez les secrets GitHub suivants :

1. `DEPLOY_HOST` : IP publique ou FQDN du VPS.
2. `DEPLOY_USER` : utilisateur SSH de déploiement.
3. `DEPLOY_PORT` : port SSH si différent de `22`.
4. `DEPLOY_SSH_KEY` : clé privée utilisée par GitHub Actions.
5. `PROD_ENV_FILE` : contenu complet du fichier `.env.prod`.

Le workflow `.github/workflows/deploy.yml` se déclenche :

- manuellement via `workflow_dispatch`,
- automatiquement après succès du workflow `Tests` sur `main` ou `master`.

## Variables de production à renseigner

Le minimum attendu dans `PROD_ENV_FILE` est :

- `APP_DOMAIN=devspot.software`
- `MERCURE_PUBLIC_HOST=mercure.devspot.software`
- `APP_SECRET=` une vraie valeur aléatoire
- `MERCURE_JWT_SECRET=` une vraie valeur aléatoire
- `POSTGRES_PASSWORD=` un mot de passe fort
- `MAILER_DSN=` votre SMTP réel ou `null://null`

## Ouverture réseau recommandée

Sur le VPS, ouvrez au minimum :

- `22/tcp` pour SSH,
- `80/tcp` pour la validation HTTP et la redirection,
- `443/tcp` pour HTTPS.

N'exposez pas PostgreSQL ni le service ML publiquement.

## Vérification après déploiement

Après la première release, contrôlez :

1. `https://devspot.software`
2. `https://mercure.devspot.software/.well-known/mercure`
3. `ssh <user>@<host> 'cd /opt/devspot && docker compose -f compose.prod.yaml --env-file .env.prod ps'`

## Quand ajouter Terraform

Ajoutez Terraform seulement si vous décidez ensuite de piloter automatiquement :

- la création de VPS,
- les firewalls ou security groups,
- les volumes persistants,
- les snapshots,
- les enregistrements DNS via un provider Terraform pris en charge.

À ce stade, **Ansible seul est le meilleur compromis** : moins d'outillage, moins de surface de panne, mise en ligne plus rapide.