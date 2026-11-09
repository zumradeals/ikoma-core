# V1 — Audit « Personnes et habilitations » (inventaire, écarts, plan)

Statut : **projet de travail**, non adopté. Rédigé avant toute implémentation. Branche `claude/v1-personnes-habilitations`, empilée sur la PR #102 (menu à sept entrées, charte de l'administration, postes). Aucune donnée ni règle n'est modifiée par ce document.

## 1. Inventaire du code existant

**Modèle de données (déjà qualifié)**
- `persons` : identité unique (prénom, nom, nom complet, organisation, `status` actif/archive). Recherche normalisée (accents/casse), index trigramme. Aucun téléphone, aucune photo, aucun matricule, aucune fonction, aucune catégorie sur l'identité.
- `visitors` = **participation** d'une personne à une activité (`person_id` obligatoire, unique par activité ; catégorie d'activité, période, source, statut, clôture/retrait).
- `permanent_holders` = **profil permanent** d'une personne (`person_id` obligatoire et unique) : identifiant AILCT, fonction (`position`), catégorie institutionnelle (8), engagement début/fin, statut actif/clos, qualité.
- `participation_entitlements` : habilitations liées à une participation (zone, type standard/exceptionnelle, motif, période, suspension, retrait).
- `holder_entitlements` : habilitations permanentes (zone d'un contexte permanent, période, retrait ; pas de suspension).
- `badge_assignments`, `audit_logs` (chaîne scellée), portrait de titulaire (service de profil).

**Services réutilisables** : `Persons` (recherche, homonymes, création avec confirmation, historique), `Participations` (inscription, clôture), `PermanentHolders` (registre, création, mise à jour, clôture, import, groupes), `ParticipationEntitlements` (accorder, suspendre, reprendre, retirer, durée exceptionnelle maximale), `HolderEntitlements` (accorder, accorder en groupe, retirer, politique de zone), `PermanentAccess` (décision et hors ligne), `InstitutionalCategories`, `HolderCards`.

**Écrans** : `Personnes` (recherche + création, 50 résultats, sans pagination), `Personne` (fiche : participations, inscription, historique des badges), `Titulaires` (intitulé « Registre des personnes » : registre permanent, import, groupes, cartes), `Habilitations` (« Qui peut entrer où ? », permanents), `AccesZones` (habilitations des participants, correspondances poste–zone), `CategoriesInstitutionnelles`, `Imports`.

**Sécurité / audit** : routes sous `listes.importer` ; octroi d'habilitation sous `listes.importer` + `zones.gerer` ; limitation de débit ; audit : `personne.creee`, `participation.creee/cloturee`, `titulaire.cree`, `habilitation.accordee/retiree` (+ suspension/reprise côté participation).

**Tests existants** : PersonnesTest, RegistrePersonnesTest, PermanentHoldersTest, PermanentAccessTest, HolderCardsTest, ListImportTest ; front personnes / habilitations / acces-zones / import ; e2e registre-personnes, titulaires, habilitations, import.

## 2. Écarts et risques constatés

**A. Écart de contrat (à traiter avant tout)**
1. **Réutilisation d'une identité pour un titulaire permanent : absente.** `PermanentHolders::create` et l'import ne reçoivent pas de `person_id` ; un déclencheur crée **toujours une nouvelle personne** à partir du nom du titulaire. Une personne déjà connue (par exemple invitée à une activité) qui devient personnel permanent est donc dupliquée.
2. **Divergence d'identité** : modifier le nom ou l'organisation d'un titulaire met à jour `permanent_holders` mais pas `persons` ; le nom « canonique » n'est pas défini.

**B. Éléments de la maquette sans support dans le code (non reproduits)**
- Contact / téléphone ; photo hors titulaires permanents ; matricule hors titulaires permanents ; fonction hors titulaires.
- Statut « Suspendu » au niveau de la **personne** (la suspension existe seulement sur une habilitation de participation).
- Bouton « modifier » d'une habilitation : interdit par contrat (une habilitation ne se modifie pas : retrait puis nouvelle attribution).
- « 1 248 », « 912 », « 28 », « 36 » : valeurs illustratives ; les indicateurs seront calculés sur les données réelles.

**C. Décision de conception requise**
- « Catégorie » : deux taxinomies distinctes (catégories institutionnelles des titulaires permanents ; catégories propres à chaque activité). Le filtre unique de la maquette doit dire laquelle il porte.

**D. Dette sans décision de règle**
- Pas de pagination du registre des personnes (50 résultats) ; deux écrans intitulés « registre » (Personnes, Titulaires) ; navigation entre fiche, habilitations et accès dispersée.

## 3. Dépendances avec la PR #102
- Menu à sept entrées, sous-menus, charte (en-tête, barre latérale, composants `site-*`) : **requis**.
- « Postes autorisés » dans la synthèse d'accès : dérivés de `checkpoint_zones` (relation poste–zone de la PR #102).
- État de la PR #102 : CI non encore entièrement verte (un test e2e instable de badges, étranger à ce chantier). Les travaux de lecture seule peuvent avancer ; la base ne sera déclarée stable qu'à la CI verte.

## 4. Architecture proposée (sans moteur parallèle)
- **Registre unifié en lecture** : un service de lecture (aucune nouvelle table) qui compose `persons` + profil permanent + comptes de participations + synthèse d'habilitations des deux moteurs ; pagination, recherche, filtres, indicateurs réels.
- **Fiche en panneau contextuel** (onglets Identité, Habilitations, Badges, Activités, Historique) : consultation des badges et participations (leur gestion reste dans « Badges et cartes » et « Activités »).
- **Habilitations** : actions branchées sur les routes et services existants (accorder, suspendre/reprendre [participations], retirer), avec les mêmes contrôles serveur ; actions masquées sans permission.
- Aucun écran supprimé, aucune donnée fusionnée.

## 5. Lots proposés
1. **Lot P1 — lecture** (indépendant des décisions A/C) : service de registre, indicateurs, pagination, nouvel écran « Personnes » à la charte, fiche en lecture. Tests, captures.
2. **Lot P2 — identité** (après décision A) : reprise d'une personne existante pour un titulaire permanent ; cohérence du nom ; tests anti-doublon.
3. **Lot P3 — habilitations dans la fiche** : attribution, suspension, retrait via les moteurs existants ; synthèse des accès (postes via PR #102).
4. **Lot P4 — convergence** : sort des écrans redondants (Titulaires/Habilitations), sans suppression de données.

## 6. Questions pour le dirigeant
1. (A1) Valide-t-on d'ajouter la **reprise d'une identité existante** à la création d'un titulaire permanent (champ `person_id` optionnel, recherche préalable obligatoire), et la mise à jour symétrique de `persons` lors d'une modification de nom ?
2. (C) Le filtre « Catégorie » : catégorie institutionnelle pour les permanents et catégorie de la participation la plus récente pour les autres, présentées avec leur origine ?
3. (B) Confirme-t-on de **ne pas afficher** contact, photo et matricule pour les personnes qui n'en ont pas dans le code actuel (ajout éventuel plus tard, sur décision) ?
