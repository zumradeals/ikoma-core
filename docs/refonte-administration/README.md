# Administration unifiée — contrat et vérification

Mission du dirigeant du 29 octobre 2026. Base : main `935bb0a`.

## Contrat

Un seul `AdminLayout` pour Tableau, Zones, Catégories, Liste d'invités, Badges, Groupes et cars, Postes et tablettes, Comptes, Journal, Sécurité et Conservation. `/espace` redirige l'administrateur vers `/administration` après les protections habituelles. Les quatre autres rôles conservent leur espace. Le journal partagé conserve son ancien cadre pour le superviseur. La confirmation du mot de passe conserve le cadre administrateur.

Les écrans d'impression des badges et les exports restent des documents autonomes : aucune navigation ne doit être imprimée sur les badges. Aucune route administrative de détail supplémentaire n'existe dans la base de référence ; les liens profonds testés portent sur les filtres et la pagination des badges, et les développements de lignes restent dans le cadre commun.

Les chiffres viennent exclusivement des contrôleurs et services existants. Comptes ajoute seulement un booléen public de confirmation 2FA ; aucun secret ni code n'est exposé. Création, modifications, mots de passe, rattachements, réglages et réinitialisation 2FA utilisent les mêmes routes, validations, permissions et services. Aucun changement de schéma ou de dépendance.

## Événement de référence et portée des modules

Le tableau conserve en session son événement de référence. La barre commune l'identifie sur les modules, avec le libellé explicite « Événement de référence ». Cela **ne filtre pas** les modules qui géraient plusieurs événements : ils conservent leurs sélecteurs et leurs libellés d'événement. Les comptes et leurs réglages restent globaux. Aucun changement implicite de portée métier.

## Références visuelles

Les deux PNG joints à la mission sont conservés à l’identique dans [`references/tableau.png`](references/tableau.png) et [`references/comptes.png`](references/comptes.png). Ils constituent le contrat visuel. Leurs empreintes sont consignées dans `references.sha256`. Ils ne sont ni retouchés ni utilisés comme interface d'application.

Comparaison attendue :

| Zone | Référence | Implémentation |
|---|---|---|
| Cadre | Barre marine, colonne latérale blanche, fond clair | Même cadre sur tous les modules, largeur latérale 256 px sur grand écran |
| Navigation | 11 rubriques, accent orange actif | 11 rubriques, permissions existantes, menu repliable sous 1024 px |
| Tableau | Synthèse marine, 4 indicateurs, préparation à gauche, surveillance et journal à droite | Même organisation à 1448 px ; réorganisation sous 1400 px |
| Comptes | 3 indicateurs, création en ligne, liste tabulaire, 3 blocs de réglage | Même organisation ; actions dans des lignes dépliables, défilement local du tableau |
| Données | Exemples de démonstration | Valeurs du serveur, états vides et recherche sans résultat explicites |
| Accessibilité | Référence bitmap | Texte marine sur orange pour le contraste, cibles agrandies sur écran tactile, focus et libellés clavier |

Écarts intentionnels : compteurs et alertes selon les données réelles ; « tablettes enrôlées » plutôt que « prêtes », car le service mesure l'enrôlement et non l'état opérationnel ; résumé honnête si la préparation n'a pas commencé ; compte des fiches hôte plutôt qu'un nombre d'hôtes ambigu ; réinitialisation 2FA dans le panneau de la ligne ; pas d'icône décorative suggérant une action inexistante.

La fidélité visuelle ne peut être déclarée qu'après inspection des captures de l'application. Les résultats effectivement observés seront consignés après la recette.

## Recette reproductible

- `npm run lint`, `npm run typecheck`, `npm run build`, `npm test`.
- `make lint`, `make test`, `make test-front`, `make e2e` dans l'environnement Docker du dépôt.
- `tests/Feature/AdminDashboardTest.php` : orientation par rôle, contexte partagé, permissions manquantes, liens directs, confidentialité du statut 2FA.
- `tests/front/admin-accounts.test.ts` : recherche, réglages serveur, permissions, annulation/confirmation des actions.
- `tests/e2e/tableau.spec.ts` : vraie connexion avec TOTP, navigation Comptes → Badges → Tableau, autres modules, historique navigateur, lien profond, sécurité, ordinateur 1448 px, tablette 820 px, téléphone 380 px.
- Captures de recette dans `test-results/administration/`, collectées par l'artefact CI existant `captures-l3`. Elles ne montrent aucun mot de passe, QR TOTP, code de secours ou jeton de préparation.

## Déploiement

Pas de fusion ni de déploiement demandé. `.github/workflows/ci.yml` réalise des installations **de recette isolées** sur PR et sur push main, pas de déploiement du site. `deploy/demo/deploy.sh` utilise `BRANCH=main` par défaut, mais accepte une autre branche explicitement. Un automatisme externe au dépôt reste invérifiable depuis ce code ; ne pas présumer qu'il n'existe pas. Aucun script de déploiement n'est lancé par cette mission.
