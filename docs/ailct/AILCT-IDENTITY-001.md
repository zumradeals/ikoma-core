# AILCT-IDENTITY-001 — Identité institutionnelle
Statut : VALIDÉ (conception V1, 2026-11-01). Lot A01 documentaire.

## Vision
L'installation destinée à l'Académie Internationale de Lutte Contre le Terrorisme (AILCT) présente exclusivement l'identité AILCT aux utilisateurs. IKOMA ACCESS demeure le produit technique et commercial sous-jacent, réutilisable pour d'autres organisations.

## Contrat
- Nom public : AILCT ; dénomination : Académie Internationale de Lutte Contre le Terrorisme.
- Logo institutionnel : original fourni par le client, à intégrer après vérification du fichier source et des droits d'usage. Aucun substitut généré.
- Surfaces : accueil, connexion, espaces administration/supervision/agent, menus, titres, notifications, rapports, exports, impressions, badges, favicon, icônes PWA, métadonnées et titres navigateur.
- Aucun libellé IKOMA ACCESS visible dans l'installation AILCT, sans renommer aveuglément classes, routes, tables, permissions ou historiques techniques.
- Palette de travail (non charte officielle) : marine #0C2D66, or #D6B46B, blanc #FFFFFF, gris #E9EDF3. Validation graphique ultérieure obligatoire.
- Français initialement ; accessibilité, contraste, lisibilité mobile/tablette/ordinateur.
- Identité résolue depuis une configuration institutionnelle explicite, sans logique métier conditionnée par le logo ou le nom.
- Une identité par installation existante ; ne pas prétendre à une architecture multi-tenant. L'extension multi-installations exige une décision séparée.

## Invariants
Préserver F01–F09 (ACCESS-FOUNDATION-001), F06 désactivable selon son contrat, rôles, permissions, authentification, QR, contrôles, historiques et données existantes. La marque ne confère jamais de droits.

## Critères d'acceptation futurs
Inventaire exhaustif des surfaces visibles ; tests des valeurs de repli, titres, impressions et exports ; aucun changement de permission ou de données ; installation IKOMA non-ailct inchangée.

## Hors périmètre A01
Aucune modification UI, migration, déploiement, remplacement d'assets ni modification de l'authentification.
