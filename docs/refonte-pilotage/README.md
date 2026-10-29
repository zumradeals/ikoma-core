# Pilotage superviseur V2

Validation du dirigeant le 29 octobre 2026 : reprendre les trois maquettes, avec **menu repliable en tablette paysage** et **annulation dans Autres actions**, tout en conservant l’accès depuis la fiche visiteur. Branche issue de main `8c6ac0e` (administration PR #26).

## Contrat visuel et responsive

- Cadre `PilotageLayout` commun à tableau, alertes, présents, litiges, groupes/cars, évacuation, fiche visiteur et journal du superviseur. L’administrateur garde son propre cadre.
- Marine `#0B3C6B`, bleu `#0F5AA6`, orange `#F26B21` avec texte marine, fond clair et cartes bordées blanches. Styles isolés par `.pilotage-*` ; aucun changement du poste d’accueil.
- À partir de 1200 px : menu latéral de 256 px, quatre indicateurs et deux colonnes (priorités / terrain).
- Entre 900 et 1199 px : menu replié, quatre indicateurs, deux colonnes lorsque possible. Le menu ouvert reste dans le flux, sans masquer les actions.
- Sous 900 px : indicateurs 2 × 2, cartes empilées, navigation inférieure fixe Tableau / Alertes / Présents / Litiges / Évacuation avec réserve de défilement et zone sûre. Groupes et journal dans le menu.
- Cibles d’action ≥ 56 px, champs 18 px, focus visible. Le contrôle sonore réduit sur téléphone garde son libellé accessible et son état `aria-pressed`.
- Situation : logique existante conservée (alertes, critiques en priorité, puis litiges, puis calme). Texte, symbole et couleur associés. Les valeurs viennent exclusivement du serveur. Aucun KPI hôtes, accueil ouvert ou proportion de tablettes actives n’a été ajouté.
- Les références PNG sont des propositions graphiques, pas des captures exécutables ni des spécifications de données. Le présent contrat et les permissions existantes priment sur leurs détails illustratifs. La dernière vue mobile est la référence retenue.

## Parcours conservés

`/espace`, derrière le middleware 2FA existant, redirige le superviseur vers `/pilotage`. Priorité administrateur conservée en cas de cumul ; les autres rôles restent sur Mon espace. Aucun remplacement de Fortify ni modification des liens profonds demandés avant connexion.

Permissions filtrées dans les menus et actions ; contrôles serveur inchangés. Groupes nécessite toujours les deux droits `groupes.suivre` et `cars.suivre`. Le journal conserve sa portée globale et ses filtres : l’événement affiché sert au retour vers le pilotage, ne filtre pas le journal. Aucun droit d’export ajouté.

Événement conservé dans les liens du cadre, onglets des alertes et fiches. Aucun changement des fréquences (tableau et alertes 3 s, groupes 5 s), prise en compte, gravité, escalade, son ou vibration. Les rechargements partiels existants restent en place.

Les litiges gardent leurs décisions et justification conditionnelle, les présents leurs recherches/filtres/pagination. Autres actions ne modifie pas l’annulation : code/visiteur, motif puis confirmation définitive, accès à la fiche inchangé.

L’évacuation garde son code métier, liste par zone, impression, chiffrement local, PIN, verrouillage, fraîcheur, expiration et effacement. Le cadre public hors ligne n’affiche ni compte ni navigation authentifiée. La déconnexion utilise `wipePilotageLocal`, comme l’ancien espace. Ouvrir la liste ne déclenche aucune évacuation.

## Vérifications

- `npm run lint`, `npm run typecheck`, `npm test`, `npm run build`.
- Tests Vue de navigation, permissions, contexte événement, son et placement de l’annulation ; tests préexistants de copie chiffrée, PIN et prise en compte maintenus.
- Tests PHP de redirection par rôle, 2FA et contexte du journal ; suites serveur existantes conservées.
- `tests/e2e/pilotage-responsive.spec.ts` : 1440 × 900, 1024 × 768, 768 × 1024 et 390 × 844 ; débordement, menu replié, accès évacuation, cadre commun et captures dans `test-results/pilotage-v2-*.png` (artefact CI).
- Recette complète existante : alerte créée sur un autre poste, recherche, annulation en deux étapes, décision de litige, impression, réouverture hors ligne et effacement à la déconnexion.

Aucune migration, modification de règles métier ou déploiement dans ce lot. Fusion soumise à validation du dirigeant.
