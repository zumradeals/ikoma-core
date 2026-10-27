# Prompts d'exécution

À lancer **dans l'ordre**, un seul à la fois :

1. `L0.md` — Fondations du dépôt
2. `L1.md` — Comptes, rôles, 2FA, journal d'audit
3. `L2.md` — Modèle de données et données fictives

Les lots L3 à L12 (voir section 11 du plan) seront rédigés par l'architecte après validation des lots précédents.

## Méthode
1. Coller le prompt dans ChatGPT Work (avec accès au dépôt).
2. Récupérer le rapport de fin de tâche et la pull request.
3. Le faire relire par l'architecte (Claude) avant fusion.
4. Le dirigeant valide, puis la décision est notée dans `docs/JOURNAL_DECISIONS.md`.
5. Seulement ensuite : lot suivant.

Un lot n'est « terminé » que si les tests sont verts, les critères d'acceptation vérifiés et la validation du dirigeant obtenue.
