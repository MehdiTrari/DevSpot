# DevSpot IA Matching – Architecture proposée

## Objectif
Mettre en place un matching **CV ↔ offre d'emploi** plus intelligent que les filtres par mots-clés, avec un focus sur :
- la valorisation des profils juniors,
- l'anonymisation des données personnelles,
- la mesure de biais (fairness).

## MVP implémenté dans Symfony
Un endpoint `POST /api/matching/preview` permet de tester rapidement une logique de matching :

1. **Anonymisation** des CV (`CvAnonymizer`)
2. **Scoring compétences** hard/soft skills (`SkillMatcher`)
3. **Classement des candidats**
4. **Audit fairness** junior vs non-junior (`FairnessAuditor`)

> Ce MVP est volontairement simple pour valider l'architecture avant branchement d'un modèle NLP plus avancé (CamemBERT/Sentence-BERT).

## Évolution recommandée (phase mémoire)
1. **Extraction sémantique des compétences**
   - Entités compétences depuis CV/offres
   - Normalisation vers un référentiel (ESCO, O*NET, taxonomie interne)
2. **Embeddings**
   - Encoder CV et offres (CamemBERT/SentenceTransformer)
   - Similarité cosinus en base vectorielle (pgvector, Qdrant, Weaviate)
3. **Fairness**
   - Suivre des métriques (disparate impact, equal opportunity)
   - Auditer périodiquement les scores
4. **Observabilité**
   - Logger `scoreBreakdown`
   - Tableaux de bord qualité/fairness

## Contrat JSON du endpoint

```json
{
  "offer": {
    "id": "offer-1",
    "title": "Junior Symfony Developer",
    "requiredHardSkills": ["php", "symfony", "sql"],
    "desiredSoftSkills": ["communication"],
    "description": "..."
  },
  "candidates": [
    {
      "id": "cand-1",
      "yearsOfExperience": 1,
      "hardSkills": ["php", "symfony"],
      "softSkills": ["communication"],
      "rawCv": "john.doe@example.com ..."
    }
  ]
}
```

## Exemple curl

```bash
curl -X POST http://localhost:8000/api/matching/preview \
  -H "Content-Type: application/json" \
  -d @payload.json
```
