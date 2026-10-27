# PROMPT L3b — Droits dédiés et décisions du 27 octobre 2026

> À exécuter après la fusion de L3 et avant L4. Petit lot, uniquement des droits et de la documentation.

CONTEXTE
Lis AGENTS.md, docs/MATRICE_DROITS.md, config/roles.php et docs/JOURNAL_DECISIONS.md. Pars de main à jour. Le dirigeant a validé le 27 octobre 2026 les décisions suivantes.

DÉCISIONS À ENREGISTRER (docs/JOURNAL_DECISIONS.md, sans rien inventer d'autre)
1. Les lots de badges sont constitués par catégorie (hypothèse de travail à confirmer avec le client).
2. Le badge est à usage unique par défaut (réglage badge_reutilisable = non, modifiable) ; règle définitive à confirmer avec le client.
3. Le contrôle des stagiaires se fait par défaut par confirmation de l'encadreur (réglage controle_stagiaires = encadreur, modifiable) ; à confirmer avec le client, qui peut exiger la pièce d'identité.
4. Des droits dédiés remplacent le rattachement provisoire à badges.gerer : groupes.gerer, postes.gerer, evenements.gerer. Les photos ne sont consultables par aucun rôle ; seule la prise de photo à l'accueil existe.
Statut de chaque décision : « validée par le dirigeant le 27 octobre 2026 », « à confirmer avec le client » là où indiqué.

À FAIRE
1. Ajoute les permissions groupes.gerer, postes.gerer, evenements.gerer au rôle administrateur uniquement (config/roles.php, seeders, docs/MATRICE_DROITS.md avec leur description et la mention « Lot L3b »). Aucun autre rôle ne les reçoit. Vérifie quelles routes ou écrans existants de gestion d'événement, de zones ou de catégories doivent être couverts : applique evenements.gerer à la gestion de l'événement lui-même (création, dates, réglages) seulement s'il existe déjà un écran ; sinon, ne crée aucun écran. Ne change pas zones.gerer ni categories.gerer.
2. Mets à jour la matrice dans docs/MATRICE_DROITS.md (tableau et nombre total de permissions) et le test qui la compare à la configuration.
3. Ne crée ni écran ni fonctionnalité nouvelle.

CONTRAINTES
- Aucune donnée réelle. Aucune permission supprimée ni renommée. Le principe « un rôle par compte » et la 2FA obligatoire restent inchangés.
- Ne supprime ni n'affaiblis aucun test existant. Ne modifie pas les droits du compte applicatif sur audit_logs, access_events et identity_checks.

CRITÈRES D'ACCEPTATION
- Test Pest : seul l'administrateur possède les trois nouvelles permissions ; la matrice documentée et la configuration concordent.
- make test, make lint et la CI verts.

LIVRABLE
Pull request « L3b: droits dédiés et décisions » sur une branche dédiée, non fusionnée, et un rapport court.
