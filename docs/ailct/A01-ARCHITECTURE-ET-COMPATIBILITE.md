# A01 — Frontières et compatibilité
Statut : conception V1, documentaire, soumise à revue Git.

## Références
- docs/MODULES_FONDATEURS.md : contrat F01–F09.
- docs/ATELIER_BADGES.md : atelier, trois modèles, mode nominatif et limites d'impression.
- docs/LOT33_CONTRATS.md : cycle de vie, sécurité, cloisonnement par installation.
- app/Services/BadgeLots.php : codes B-n et jetons opaques.
- app/Services/BadgeService.php : association, contrôle de catégorie événementielle.
- app/Services/BadgePreparation.php : destination nominative.
- app/Services/BadgeArtwork.php : présentation et formats.
- app/Models/Category.php : catégorie rattachée à un événement.
- resources/views/app.blade.php et resources/js/pages/Auth/Login.vue : libellés de marque codés en dur.

## Découpage
1. Identité de présentation (AILCT-IDENTITY-001) : configuration, assets et surfaces publiques, sans pouvoirs métier.
2. Référentiel institutionnel (AILCT-BADGE-001) : catégories officielles et règles d'association, sans duplication du moteur des événements.
3. Moteur IKOMA ACCESS : F01–F09, permissions, QR, badges, historique, poste et contrôle.

## Plan ordonné
A02 : résolution centralisée de la marque et audit des surfaces.
A03 : conception puis réalisation du référentiel institutionnel et correspondances.
A04 : référence imprimée et validité indépendantes des identifiants existants.
A05 : huit modèles recto-verso fidèles aux originaux.
A06 : raccordement atelier et impression.
A07 : recettes fonctionnelles, sécurité, hors ligne et impression réelle.

## Règles de migration
Aucune suppression ou réécriture des identifiants, jetons, associations, catégories historiques et journaux. Toute évolution de schéma requiert migration additive, test de reprise et stratégie de retour arrière documentée. Ne pas introduire de multi-tenancy implicite.

## Portes de validation
Chaque lot dispose d'un diff limité, de tests pertinents, de vérification des permissions et de preuves de non-régression. Aucune fusion ou mise en production automatique par la validation documentaire A01.
