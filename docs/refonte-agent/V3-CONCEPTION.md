# Agent V3 — poste de travail AILCT : conception validée (phase A)

Statut : maquette **validée par le dirigeant** ; phase B (implémentation) non commencée. Références visuelles : `references/v3/` (propositions graphiques, pas des fonctions livrées). Charte : tableau de bord Superviseur AILCT.

## Principe
L'agent ouvre un poste de travail, pas un menu : une action principale immédiate, adaptée au poste (capacités `accueil`, `scan`, `sortie`), à ses droits, à l'activité du jour et à l'état de la copie locale. Les règles métier, permissions, API, schémas et la synchronisation ne changent pas.

## Adaptation par poste (source : `PostCapabilities`, contrat V1)
| Poste ou tablette | Action principale | Autres |
|---|---|---|
| Poste 2 (accueil, scan, sortie) | Scanner un badge ou une carte | Accueillir un visiteur (7 étapes), visite non annoncée, sortie, restitution (réseau requis), arrivée d'un car |
| Point 5 « La Soute » (scan, sens obligatoire) | Choisir Entrée ou Sortie, puis scanner | — |
| Postes 1, 3, 4 (aucune capacité) | Message clair : aucune opération numérique à ce poste | — |
| Tablette sans poste (hôtel) | Groupes et cars (appel, badges de groupe, embarquement, départ) | — |
| Mode site (aucune activité) | Contrôle des cartes permanentes | Choix de l'activité du jour pour accueillir |
Les cars n'apparaissent que si F06 est activé. L'arrivée d'un car exige la capacité `scan`.

## Décisions
1. **Fusion** : poste, contrôle et groupes deviennent des vues d'un seul écran déverrouillé (même coffre, même copie, même synchronisation), sans visite de page ; faisabilité démontrée (section suivante). Les adresses `/poste`, `/controle`, `/groupe` restent des points d'entrée.
2. **Derniers passages** : enrichis si l'historique local le permet sans modifier le moteur ; sinon, badges remis et refus d'accueil (donnée actuelle `DeskSummary.recent`).
3. **Consigne après un refus** : le message du serveur (motif, action) reste la base. « Le superviseur est prévenu » n'est affiché que pour les causes qui déclenchent réellement une alerte (voir ci-dessous) ; sinon aucune promesse.
4. Les sept étapes réelles sont conservées (recherche, fiche, identité/photo, décision, scan/association du badge, remise, entrée). La maquette les groupe en quatre phases pour la lisibilité ; aucune « sélection de badge » fictive.
5. Rôle affiché : « Agent d'accueil ». Aucun écran d'aide, d'historique ni de statistiques de poste n'est créé.

## Quand le superviseur est alerté (lu dans le code, à ne pas étendre sans décision)
- Contrôle de badge refusé : annulé (critique) ; restitué, non associé, invité retiré, autre événement, inconnu (hautes) ; hors période (normale). Zone non autorisée, badge présenté à deux points, rafale de refus (hautes). Cartes permanentes refusées (critiques ou hautes). `ControlAlerts`, `SiteControl`.
- Accueil : accès refusé, validation hors période, visiteur non listé en attente, hôte qui refuse ou ne répond pas. `Desk`, `HostNotifications`.
- Synchronisation : conflits d'opérations (« le superviseur est informé »). `SyncProcessor`.
Hors ligne, l'alerte est créée à la synchronisation ; la consigne doit le dire (« sera signalé dès le retour du réseau »).

## Test de faisabilité de la fusion
- `PosteGate` héberge déjà les trois applications derrière un seul coffre/PIN et une seule `PosteRuntime` ; seul le choix de l'application venait de la page (`app`). Remplacé par une vue interne (`view`) et une fonction `agentNavigate` fournie aux écrans ; les adresses de page ne sont plus nécessaires pour changer d'écran.
- Menu (`AgentLayout`) et entrées de « Autres opérations » utilisent cette fonction quand elle existe ; sinon ils gardent leurs liens (pages seules inchangées).
- `ControleApp` accepte un mode de départ (`initialMode`) en plus du paramètre d'adresse.
- Preuve : `tests/front/agent-fusion.test.ts` — vrais PosteApp, ControleApp et GroupeApp, vrai coffre (IndexedDB simulée), réseau coupé : accueil → contrôle → accueil → groupes sans recharger, sans nouveau PIN, même coffre et même copie. Suite front : 413 tests réussis ; lint 0 erreur ; typecheck OK.
- Limites constatées : (a) ce test ne remplace ni l'e2e réel, ni une recette sur tablette (caméra, rotation) ; (b) le bouton Précédent du navigateur ne suit pas les vues (à traiter en phase B) ; (c) `ControleApp` redemande le point de contrôle quand il n'est pas mémorisé : à un poste identifié, le point de la tablette pourrait être repris automatiquement (à valider) ; (d) la restitution reste en ligne uniquement ; (e) `SiteModeNotice` garde un lien de page vers `/controle` (à convertir).
- Conclusion : faisable sans toucher au moteur, aux API ni aux schémas ; l'effort est dans la présentation et la navigation.
