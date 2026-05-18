# ADR 0005 — Stratégie de tests progressive et orientée coverage

## Contexte

DevSpot combine plusieurs couches techniques : Symfony, assets front, Mercure, Docker, et un service ML séparé. Une stratégie de test uniquement fondée sur des scénarios end-to-end aurait été coûteuse à maintenir, lente à exécuter et peu adaptée au rythme du projet.

À l'inverse, une stratégie uniquement unitaire n'aurait pas suffisamment sécurisé les parcours critiques visibles côté produit.

## Décision

Nous retenons une **stratégie de tests progressive** :

- priorité aux tests ciblés sur la logique métier et les composants critiques ;
- exécution régulière des tests applicatifs en parallèle ;
- maintien d'un périmètre E2E plus réduit, réservé aux parcours à plus forte valeur de vérification ;
- génération d'un rapport de couverture séparé dans la CI.

Dans la chaîne GitHub Actions actuelle, cela se traduit par une séparation claire entre :

- `quality` ;
- `phpunit` ;
- `e2e` ;
- `coverage`.

## Conséquences

Conséquences positives :

- temps de retour plus raisonnable dans la CI ;
- meilleure maîtrise des coûts de maintenance des tests ;
- couverture plus utile sur les règles métier et les zones sensibles du produit.

Contraintes assumées :

- les E2E ne couvrent pas exhaustivement tous les cas ;
- la qualité dépend d'un bon arbitrage continu sur les parcours réellement critiques ;
- la lecture des résultats de couverture doit rester qualitative, pas uniquement métrique.

## Alternatives écartées

### Tout miser sur l'E2E

Écarté car trop fragile, plus lent, et disproportionné pour sécuriser tout le projet.

### Ne faire que des tests unitaires

Écarté car insuffisant pour contrôler certains parcours intégrés, notamment côté interface et flux applicatifs complets.