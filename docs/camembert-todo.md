# DevSpot – To-Do List CamemBERT

## Objectif
Intégrer un modèle **CamemBERT** dans DevSpot pour faire un matching **CV ↔ offre d'emploi** plus intelligent que la baseline actuelle par mots-clés, tout en gardant :
- l'anonymisation des données,
- la valorisation des profils juniors,
- la mesure de fairness.

## Point de départ
Aujourd'hui, le projet contient déjà :
- une baseline Symfony de matching heuristique,
- une anonymisation simple des CV,
- un audit fairness junior vs non-junior,
- une page de démonstration avec dataset de test.

Le projet **n'utilise pas encore de vraie IA sémantique** dans le scoring.  
CamemBERT sera donc une **nouvelle brique** à intégrer dans l'architecture.

## Décision d'architecture
La meilleure approche est :
- garder **Symfony/PHP** pour l'application principale,
- ajouter un **service Python ML** dans le même repo,
- faire tourner **CamemBERT** dans ce service,
- appeler ce service depuis Symfony via HTTP,
- conserver la baseline actuelle pour comparaison.

## To-Do List
1. Créer un dossier `ml/` dans le repo pour le service CamemBERT.
2. Ajouter un environnement Python avec `transformers`, `torch`, `fastapi` et `uvicorn`.
3. Étendre `compose.yaml` avec un service `ml`.
4. Charger CamemBERT au démarrage du service ML.
5. Exposer un endpoint `GET /health` pour vérifier que le service ML fonctionne.
6. Exposer un endpoint `POST /embed` pour transformer un texte en vecteur.
7. Définir le contrat JSON entre Symfony et le service ML.
8. Créer un client Symfony `AiMatchingClient` pour appeler le service ML.
9. Ajouter un mode de matching `semantic` en plus du mode `baseline`.
10. Encoder les CV et les offres avec CamemBERT.
11. Calculer une similarité cosinus entre embeddings.
12. Ajouter l'affichage du score `baseline` et du score `semantic` dans la démo.
13. Renforcer l'anonymisation avant envoi du texte au modèle.
14. Étendre la fairness pour comparer baseline vs CamemBERT.
15. Construire un dataset d'évaluation en français.
16. Mesurer les performances avec précision, recall, F1 et top-k.
17. Préparer une page ou un écran de démo pour l'oral.
18. Documenter clairement ce qui est déjà implémenté et ce qui relève du futur pipeline IA.

## Ordre recommandé
1. Service ML Python
2. Endpoint `/embed`
3. Client Symfony
4. Similarité sémantique offre/profil
5. Double affichage `baseline` vs `semantic`
6. Évaluation
7. Fairness
8. Démo mémoire

## Ce qu'il faudra pouvoir dire à l'oral
- La baseline actuelle valide l'architecture générale.
- L'intégration CamemBERT apporte la compréhension sémantique.
- L'objectif est de comparer une méthode classique et une méthode IA.
- Le système final doit être performant, éthique et plus favorable aux profils juniors.
