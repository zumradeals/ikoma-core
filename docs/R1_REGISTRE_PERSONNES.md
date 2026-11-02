# R1 — Registre des personnes

Page : Administration → **Personnes et cartes permanentes** (`/administration/titulaires`), permission « Personnes et listes » (`listes.importer`).

## Ce que fait la page
- **Ajouter une personne** : prénom + nom (ou « nom complet seul »), organisation, fonction, qualité, catégorie, dates d'engagement. Enregistrer ne crée ni carte, ni badge, ni accès.
- **Photographie** (dans la fiche, bouton Modifier) : JPEG ou PNG, chiffrée, imprimée sur la carte seulement si autorisé.
- **Importer** un fichier CSV/XLSX : aperçu ligne par ligne (à enregistrer / à confirmer / doublon / erreur), puis confirmation « tout ou rien ». Modèle téléchargeable.
- **Rechercher et filtrer** ; **sélection multiple** : catégorie, clôture (avec motif et confirmation).

## Colonnes du fichier d'import
`prenom` + `nom`, **ou** `nom complet` (un seul « nom » est aussi lu comme nom complet, sans découpage) ; facultatives : `organisation`, `fonction`, `qualite`, `categorie` (code AILCT-0x ou libellé), `debut_engagement`, `fin_engagement` (AAAA-MM-JJ ou JJ/MM/AAAA). Séparateur `;` ou `,`. 500 lignes au plus.

## Vérification
`vendor/bin/pest tests/Feature/RegistrePersonnesTest.php` · `npx playwright test tests/e2e/registre-personnes.spec.ts` (compte `screen-titulaires`).
