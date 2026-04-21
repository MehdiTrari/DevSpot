# Documentation du dépôt

Ce dossier regroupe la documentation technique et projet de DevSpot.

## Objectif

L'objectif est d'éviter une collection de fichiers Markdown isolés en organisant la documentation selon une logique stable :

- **documents actifs** utiles à l'équipe,
- **documents de recherche** utiles au mémoire,
- **décisions d'architecture** à tracer dans le temps,
- **archives** pour conserver l'historique sans polluer la lecture principale.

## Organisation proposée

```text
docs/
├── index.md
├── guide.md
├── architecture/
├── dev/
├── ops/
├── research/
├── adr/
└── archive/
```

Les sujets d'infrastructure et de déploiement sont documentés explicitement dans :

- `docs/architecture/system-overview.md`
- `docs/ops/deployment-production.md`

## Règles simples de maintenance

1. **Une doc active doit avoir un propriétaire implicite** : équipe, feature ou chantier identifié.
2. **Une doc obsolète ne reste pas à la racine** : elle est soit supprimée, soit déplacée vers `archive/`.
3. **Les décisions importantes** (choix d'architecture, d'outils, de stratégie) devraient être tracées dans `docs/adr/`.
4. **Le README racine** reste court et orienté onboarding ; les détails vont dans `docs/`.
5. **Une documentation de recherche** peut vivre dans le dépôt, mais doit rester séparée de la documentation d'exploitation.

## Publication

Le projet est prêt à publier cette documentation via MkDocs et GitHub Pages grâce à :

- `mkdocs.yml`
- `.github/workflows/docs.yml`

À ce stade, la documentation couvre :

- la structure documentaire,
- l'architecture locale Docker,
- la cible de production,
- les choix de reverse proxy et d'exposition réseau.

La stack locale utilise également **Caddy** comme reverse proxy frontal, ce qui est documenté dans :

- `docs/architecture/system-overview.md`
- `docs/ops/deployment-production.md`

Les domaines locaux retenus pour le travail de développement sont désormais :

- `http://devspot.localhost`
- `http://mercure.devspot.localhost/.well-known/mercure`

Les accès historiques en `localhost:8000` et `localhost:3000` restent disponibles pour des raisons de compatibilité.

## URLs locales utiles

- Application : `http://devspot.localhost`
- Application compatibilité : `http://localhost:8000`
- Mercure public : `http://mercure.devspot.localhost/.well-known/mercure`
- Mercure public compatibilité : `http://localhost:3000/.well-known/mercure`
- Service ML : `http://localhost:8001`
- Santé du service ML : `http://localhost:8001/health`
- Documentation MkDocs : `http://localhost:8002`
- Adminer : `http://localhost:8081`
- Mailpit : `http://localhost:8025`
- SMTP Mailpit : `localhost:1025`
- PostgreSQL : `localhost:5433`

Les équivalents en `127.0.0.1` restent également valides pour les services exposés directement par port.

En local, la publication peut être testée avec :

```bash
pip install mkdocs-material
mkdocs serve
```

Ou directement via Docker Compose depuis la racine du projet :

```bash
docker compose up -d docs
```

La documentation est alors accessible sur `http://127.0.0.1:8002`.