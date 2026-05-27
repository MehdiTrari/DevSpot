# Calcul des pourcentages des dashboards

Ce document explique les pourcentages affiches dans les dashboards candidat et recruteur de DevSpot.

## Dashboard candidat

Source principale : `src/Controller/ApplicantController.php`.

### Profil complete

Le pourcentage de completion du profil est calcule a partir de 4 etapes.

```text
profil_complete = nombre_etapes_terminees * 25
```

Valeurs possibles :

- 0 %
- 25 %
- 50 %
- 75 %
- 100 %

Les 4 etapes sont :

- etape 1 : informations generales du profil
- etape 2 : experiences, education et competences
- etape 3 : lien GitHub, LinkedIn ou portfolio
- etape 4 : postes recherches

### Score des offres recommandees

Le score des offres recommandees cote candidat est un scoring heuristique local.

Il n'utilise pas le matching IA enrichi de `OfferMatchingService`. Il est calcule directement dans le dashboard candidat, a partir des informations du profil et du contenu de l'offre.

Formule :

```text
score =
  points_competences
  + points_postes_recherches
  + points_localisation
  + points_experience
```

Details :

- competences trouvees dans le texte de l'offre : `15 points` par competence, avec un maximum de `45 points`
- poste recherche trouve dans le texte de l'offre : `25 points`, avec un maximum de `25 points`
- meme type de localisation entre le profil et l'offre : `+15 points`
- experience proche :
  - `+15 points` si l'ecart entre les annees d'experience du profil et le niveau demande est inferieur ou egal a 2
  - `+5 points` si une seule des deux valeurs est renseignee, ou si l'ecart est plus important

Le score final est plafonne a `100 %`.

Interpretation des libelles :

- `>= 70 %` : compatibilite elevee
- `>= 40 %` : compatibilite moyenne
- `< 40 %` : compatibilite a verifier


### Score de visibilite

Le score de visibilité mesure l'exposition réelle du profil aux recruteurs.

- **Si le profil est privé** :
  - La visibilité est toujours de **0%**.
  - Un message explicite est affiché :
    > "Publiez votre portfolio afin d'obtenir de la visibilité."

- **Si le profil est public** :
  - La visibilité dépend de la complétude réelle du profil, et non plus des étapes.
  - Les critères pris en compte sont :
    - **Taille de la description** (plus la description est longue et détaillée, plus le score augmente)
    - **Nombre d'expériences** renseignées
    - **Nombre de compétences** renseignées
    - **Nombre de formations** renseignées
    - **Nombre de postes recherchés**
    - **Présence de liens externes** (GitHub, LinkedIn, portfolio)
    - **Interactions avec les recruteurs** (messages, réponses)

Exemple de formule indicative :

```text
score_visibilite =
  points_description
  + points_experiences
  + points_competences
  + points_formations
  + points_postes_recherches
  + points_liens_externes
  + points_interactions
```

Chaque critère apporte un certain nombre de points, le total étant plafonné à **100%**.

**Remarque :** La visibilité n'est calculée que si le profil est public **et** que toutes les étapes du profil sont complétées. Sinon, la visibilité reste à 0%.

Libellés :

- `>= 80 %` : élevée
- `>= 55 %` : bonne
- `>= 30 %` : à renforcer
- `< 30 %` : faible

## Dashboard recruteur

Source principale : `src/Controller/RecruiterController.php`.

### Taux de reponse

Le taux de reponse mesure la part des profils contactes qui ont repondu.

```text
taux_reponse = round((profils_ayant_repondu / profils_contactes) * 100)
```

Si aucun profil n'a ete contacte, le taux vaut `0 %`.

### Score d'interet

Le score d'interet est un indicateur interne de suivi recruteur. Il ne mesure pas la qualite absolue du candidat.

Formule :

```text
score_interet =
  bonus_favori
  + bonus_conversation
  + bonus_reponse
  + bonus_messages
  + bonus_offre_liee
  + bonus_recent
```

Details :

- profil ajoute en favori : `+20 points`
- conversation existante : `+20 points`
- reponse recue du candidat : `+25 points`
- au moins 4 messages dans la conversation : `+15 points`
- conversation liee a une offre : `+10 points`
- interaction recente, moins de 14 jours : `+10 points`

Le score final est plafonne a `100 %`.

Libelles :

- `>= 75 %` : eleve
- `>= 45 %` : a suivre
- `< 45 %` : initial

### Top matching enrichi

Le `Top matching enrichi` affiche le meilleur score de matching enrichi pour une offre recruteur.

Contrairement au score des offres recommandees cote candidat, ce score vient du pipeline de matching :

- `OfferMatchingService`
- `SemanticMatchingService`
- `EnrichedMatchingService`

Le score de base est une similarite semantique entre le texte de l'offre et le profil candidat.

Cette similarite est ensuite convertie en pourcentage :

```text
pourcentage = score * 100
```

Le score enrichi ajoute des bonus lies aux competences techniques et soft skills inferees, puis applique des garde-fous de compatibilite.

Le dashboard recruteur affiche le meilleur `semanticEnrichedPercentage` trouve pour l'offre.

## Reponse courte sur les offres recommandees candidat

Le score des offres recommandees dans le dashboard candidat n'est pas calcule via l'algorithme IA de matching enrichi.

C'est un scoring simple et deterministe base sur :

- les competences du profil presentes dans le texte de l'offre
- les postes recherches presents dans le texte de l'offre
- le type de localisation
- le niveau d'experience

Le matching IA enrichi est utilise cote recruteur pour les scores de matching d'une offre vers les candidats publics, notamment le `Top matching enrichi`.
