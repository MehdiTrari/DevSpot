# Documentation du dépôt

Ce dossier regroupe la documentation technique et projet de DevSpot.

## Objectif

Éviter un ensemble de fichiers Markdown isolés en organisant la doc selon une logique stable :

- **documents actifs** utiles à l'équipe,
- **documents de recherche** utiles au mémoire,
- **décisions d'architecture** à tracer dans le temps,
- **archives** pour conserver l'historique sans polluer la lecture principale.

## Organisation proposée

```text
docs/
├── index.md
├── README.md
├── architecture/
├── dev/
├── ops/
├── research/
├── adr/
└── archive/
```

## Règles simples de maintenance

1. **Une doc active doit avoir un propriétaire implicite** : équipe, feature ou chantier identifié.
2. **Une doc obsolète ne reste pas à la racine** : elle est soit supprimée, soit déplacée vers `archive/`.
3. **Les décisions importantes** (choix d'architecture, d'outils, de stratégie) devraient être tracées dans `docs/adr/`.
4. **Le README racine** reste court et orienté onboarding ; les détails vont dans `docs/`.
5. **Une documentation de recherche** peut vivre dans le repo, mais doit être séparée de la doc d'exploitation.

## Publication

Le projet peut construire cette documentation via MkDocs et GitHub Actions grâce à :

- [mkdocs.yml](../mkdocs.yml)
- [.github/workflows/docs.yml](../.github/workflows/docs.yml)

Le build documentaire est exécuté en mode strict. Le déploiement GitHub Pages reste conditionné à la variable de dépôt `ENABLE_GITHUB_PAGES=true` sur `main` ou `master`.

En local, l'aperçu peut être testé soit avec Python :

```bash
pip install mkdocs-material
mkdocs serve
```

Soit avec Docker Compose :

```bash
docker compose up -d docs
```

Dans ce cas, la documentation est accessible sur `http://localhost:8002/DevSpot/` et `http://localhost:8002` redirige vers cette URL.