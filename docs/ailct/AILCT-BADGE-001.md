# AILCT-BADGE-001 — Contrat institutionnel des badges
Statut : VALIDÉ (conception V1, 2026-11-01). Lot A01 documentaire.

## Nomenclature institutionnelle
Les codes ci-dessous sont des identifiants de conception proposés, distincts des identifiants persistants existants :
| Code | Catégorie | Couleur |
|---|---|---|
| AILCT-01 | Personnel AILCT | Vert |
| AILCT-02 | Partenaires permanents | Orange |
| AILCT-03 | Intervenants occasionnels | Rouge |
| AILCT-04 | Délégations officielles | Or |
| AILCT-05 | Visiteurs | Argent |
| AILCT-06 | Accompagnateurs | Bronze |
| AILCT-07 | Stagiaires | Bleu |
| AILCT-08 | Experts | Jaune |

Sources de référence de conception : cahier des charges AILCT fourni par le client et 24 images de modèles (8 catégories × recto/verso/assemblage). Ne pas substituer des illustrations génériques aux originaux.

## Frontières métier
- Le référentiel institutionnel est distinct des catégories liées aux événements, des rôles utilisateurs et des permissions d'accès.
- Une association explicite et vérifiable relie catégorie institutionnelle, personne et catégorie événementielle si nécessaire ; aucune déduction de droits depuis la couleur.
- Le statut permanent d'une personne ne doit pas dépendre artificiellement d'un événement. La stratégie de persistance est à décider après conception détaillée.
- L'identifiant imprimé AILCT est distinct de l'UUID, du code interne B-xxx et du jeton QR. Il est unique dans son périmètre, stable et non réattribuable ; le périmètre global et le traitement des années restent à préciser avant A04.
- Le QR contient un jeton opaque existant ou son contrat compatible, jamais les données personnelles en clair ; les vérifications d'autorisation demeurent serveur/métier.
- Format physique CR80 vertical, 53,98 × 85,60 mm ; recto photo, identité, qualité, catégorie et identité AILCT ; verso QR, référence, validité et mentions institutionnelles selon modèles approuvés.
- La révocation, expiration, restitution, réimpression et synchronisation hors ligne respectent les règles de sécurité et de traçabilité existantes.
- Une réimpression ne crée pas une nouvelle identité ni un nouveau droit. Les tests incluent un tirage physique, scan réel, zones autorisées/interdites, expiration et révocation.

## Compatibilité et décisions ouvertes
Le code actuel contient BadgeLots, BadgeService, BadgePreparation, BadgeArtwork, trois modèles graphiques et catégories événementielles. Ne pas réutiliser directement les huit catégories comme rôles. Ne pas modifier les données historiques ni les QR existants sans contrat de migration et de compatibilité.
À préciser : champs exacts des 24 modèles, dates et durée de validité par catégorie, politique de badge permanent, autorité de révocation, périmètre de numérotation, impression duplex et comportement hors ligne en cas de révocation non synchronisée.

## Hors périmètre A01
Aucune table, migration, vue, code d'impression, génération de badge, donnée ou permission modifiée.
