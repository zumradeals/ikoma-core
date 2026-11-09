# V1 — Badges et cartes : espace de travail à trois colonnes

Écran d'arrivée de Administration → Badges et cartes (`/administration/badges`, permission `badges.gerer`).

| Colonne | Contenu | Source |
|---|---|---|
| Registre | cartes permanentes et badges temporaires, recherche (n° de support, nom, identifiant), type, état, pagination (8) | `SupportRegistry` (lecture seule), `GET /administration/badges/supports` |
| Préparer un badge | Personne → Détails → Génération → Impression, unitaire ou groupé (jusqu'à 100) | `BadgeIssuance` via `/administration/badges/preparer/*` |
| Aperçu | indicatif avant génération ; réel (numéro, QR) après émission ou pour un support existant | `POST /administration/badges/impression/donnees` (même composeur que l'impression) |

Règles : impression ≠ remise ≠ activation ≠ habilitation ; réimpression = même numéro et même QR, motif obligatoire ; un support perdu, révoqué, remplacé ou annulé ne s'imprime pas (refus serveur affiché). La préparation et l'aperçu réel exigent aussi `listes.importer`.

Compatibilité : lots et réservations → `/administration/badges/lots` ; filtres historiques redirigés ; `/administration/badges/preparer` ouvre le même espace ; `/administration/atelier/parcours` redirige vers l'espace.

Disposition : trois colonnes à partir de 1180 px ; en dessous, un volet à la fois (Registre / Préparer / Aperçu).
