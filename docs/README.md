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

Le projet est prêt à publier cette documentation via MkDocs et GitHub Pages grâce à :

- [mkdocs.yml](../mkdocs.yml)
- [.github/workflows/docs.yml](../.github/workflows/docs.yml)

En local, la publication peut être testée avec :

```bash
pip install mkdocs-material
mkdocs serve
```