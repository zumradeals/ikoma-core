# A02 — Badges institutionnels AILCT : spécification des huit catégories et du cycle de vie

Statut : **proposition de conception, documentaire**. Aucune table, migration, vue, code d'impression, donnée ni permission n'est modifié par ce lot. Elle attend la validation du dirigeant avant toute implémentation.
Base de code étudiée : `main` au commit `9a7729f`. Références : `AILCT-BADGE-001` et `A01-ARCHITECTURE-ET-COMPATIBILITE.md` (PR n° 61, non fusionnée), `docs/MODULES_FONDATEURS.md`, `docs/ATELIER_BADGES.md`, `docs/ATELIER_BADGES_SECURITE.md`, `docs/LOT33_CONTRATS.md`.
Documents frères : [A02-IDENTITE-INSTITUTIONNELLE.md](A02-IDENTITE-INSTITUTIONNELLE.md), [A02-PLAN-IMPLEMENTATION.md](A02-PLAN-IMPLEMENTATION.md).

## 0. Légende et limites

Mêmes étiquettes que le document d'identité : **[V]** validé, **[C]** constat dans le code ou la documentation de `main`, **[H]** hypothèse, **[D-nn]** décision à arbitrer (registre dans le plan, section 8). Classement des adaptations : **E** existe, **C** à configurer, **P** évolution du produit.

**Ce qui n'a pas pu être consulté.** Le cahier des charges AILCT et les **24 images de modèles** (8 catégories × recto, verso, assemblage) ne sont pas dans le dépôt. Les **couleurs exactes**, le **dessin**, les **champs précis** et les **mentions** de chaque modèle sont donc inconnus ici. Ce document ne les invente pas : il fixe le **cadre** (zones, règles, données), propose des valeurs de travail marquées **[H]**, et prévoit (section 6.5) une **fiche de relevé** à remplir sur les originaux. **Il ne faut substituer aucune illustration générique aux originaux** (BADGE-001, « Nomenclature »).

## 1. Exigences validées

| Réf. | Exigence | Source |
|---|---|---|
| V-BD-01 | Huit catégories officielles, codes de conception `AILCT-01` à `AILCT-08` : Personnel AILCT (vert), Partenaires permanents (orange), Intervenants occasionnels (rouge), Délégations officielles (or), Visiteurs (argent), Accompagnateurs (bronze), Stagiaires (bleu), Experts (jaune). Ces codes sont **distincts des identifiants persistants existants**. | BADGE-001 ; mission A02 §3 |
| V-BD-02 | Sources de conception : cahier des charges AILCT et 24 images de modèles. **Pas d'illustrations génériques à leur place.** | BADGE-001 |
| V-BD-03 | Le référentiel institutionnel est **distinct** des catégories liées aux événements, des rôles utilisateurs et des permissions d'accès. | BADGE-001, « Frontières métier » |
| V-BD-04 | Une association **explicite et vérifiable** relie catégorie institutionnelle, personne et catégorie d'événement si nécessaire. **Aucune déduction de droits depuis la couleur.** | BADGE-001 |
| V-BD-05 | Le statut permanent d'une personne **ne dépend pas artificiellement d'un événement**. La stratégie de persistance reste à décider après conception détaillée. | BADGE-001 |
| V-BD-06 | L'**identifiant imprimé AILCT** est distinct de l'UUID, du code interne `B-xxx` et du jeton QR ; **unique** dans son périmètre, **stable**, **non réattribuable**. Périmètre global et traitement des années à préciser avant A04. | BADGE-001 |
| V-BD-07 | Le QR contient un **jeton opaque existant** (ou un contrat compatible), jamais de donnée personnelle en clair ; les contrôles d'autorisation restent côté serveur et métier. | BADGE-001 |
| V-BD-08 | Format **CR80 vertical, 53,98 × 85,60 mm**. Recto : photo, identité, qualité, catégorie, identité AILCT. Verso : QR, référence, validité, mentions institutionnelles, selon modèles approuvés. | BADGE-001 |
| V-BD-09 | Révocation, expiration, restitution, réimpression et synchronisation hors ligne respectent les règles de sécurité et de traçabilité existantes. | BADGE-001 |
| V-BD-10 | **Une réimpression ne crée ni nouvelle identité ni nouveau droit.** Les tests incluent un tirage physique, un scan réel, des zones autorisées et interdites, l'expiration et la révocation. | BADGE-001 |
| V-BD-11 | Ne pas réutiliser directement les huit catégories comme rôles. Ne pas modifier les données historiques ni les QR existants sans contrat de migration et de compatibilité. | BADGE-001, « Compatibilité » |
| V-BD-12 | **Ne jamais confondre catégorie de badge et autorisation d'accès.** | Mission A02 §3 |
| V-BD-13 | Points à préciser (restent ouverts) : champs exacts des 24 modèles, dates et durée de validité par catégorie, politique de badge permanent, autorité de révocation, périmètre de numérotation, impression duplex, comportement hors ligne en cas de révocation non synchronisée. | BADGE-001 |

## 2. Trois notions à ne jamais confondre

C'est le point qui conditionne toute la conception. Aujourd'hui [C] :

| Notion | Où elle vit aujourd'hui | Ce qu'elle gouverne |
|---|---|---|
| **Catégorie d'événement** | Table `categories` : **propre à un événement** (`event_id`), avec nom, couleur, `priority_circuit`. Table pivot `category_zone` : zones accessibles. Un visiteur a une catégorie (`visitors.category_id`) ; un badge et un lot en ont une (`badges.category_id`, `badge_batches.category_id`). | Les **zones autorisées** : « les zones accessibles à un visiteur sont uniquement `visitor.category.zones` » (`docs/schema.md`). C'est la **seule** source d'autorisation d'accès. |
| **Rôle utilisateur** | Spatie : `administrateur`, `superviseur`, `agent_accueil`, `hote`, `encadreur` (`config/roles.php`) | Ce que **fait** un compte dans l'application (permissions). Sans rapport avec la qualité de la personne badgée. |
| **Catégorie institutionnelle** (nouvelle) | N'existe pas. | L'**identité visuelle et la qualité** du porteur : couleur, intitulé, code `AILCT-0n`. **Aucun droit.** |

**Règle de conception [V-BD-04, V-BD-12]** : la catégorie institutionnelle est une **étiquette de présentation et de classement**. Elle est reliée explicitement à une catégorie d'événement ; c'est cette dernière — et seulement elle — qui ouvre des zones. Conséquences :

1. Aucune ligne du contrôle, de l'association de badge, de la synchronisation hors ligne ni des politiques d'accès ne lit la couleur, le code `AILCT-0n` ni le nom de la catégorie institutionnelle.
2. Un badge « Délégations officielles » (or) peut ouvrir moins de zones qu'un badge « Visiteurs » (argent) : c'est la **catégorie d'événement** qui le décide.
3. **Test de garde** à prévoir : modifier la catégorie institutionnelle d'une personne ne change aucun verdict de contrôle ; modifier sa catégorie d'événement le change.
4. Précédent à respecter [C] : l'atelier actuel dit déjà « le choix graphique ne modifie jamais les droits » (`ATELIER_BADGES.md` §2) ; le mot « stagiaire » dans le nom d'une catégorie ne pilote que l'**intitulé imprimé**.

## 3. Les huit catégories

Les couleurs ci-dessous sont des **valeurs de travail [H]**, choisies pour que le dossier soit vérifiable ; **elles ne remplacent pas celles des originaux du client [D-02, D-11]**. Les contrastes sont calculés (formule WCAG 2.1) sur ces valeurs.

| Code | Catégorie | Couleur nominale [V] | Teinte de travail [H] | Texte sur la teinte | Contraste du texte | Symbole (repère non chromatique) [H] |
|---|---|---|---|---|---:|---|
| AILCT-01 | Personnel AILCT | Vert | `#1E7B45` | Blanc | 5,3 : 1 | ● (rond plein) |
| AILCT-02 | Partenaires permanents | Orange | `#E8710A` | Quasi-noir `#111827` | 5,7 : 1 | ▲ |
| AILCT-03 | Intervenants occasionnels | Rouge | `#C62828` | Blanc | 5,6 : 1 | ▼ |
| AILCT-04 | Délégations officielles | Or | `#C9A227` | Quasi-noir | 7,3 : 1 | ★ |
| AILCT-05 | Visiteurs | Argent | `#B8BEC6` | Quasi-noir | 9,5 : 1 | ◆ |
| AILCT-06 | Accompagnateurs | Bronze | `#A9693B` | Blanc | 4,4 : 1 (limite) | ✚ |
| AILCT-07 | Stagiaires | Bleu | `#1F5FBF` | Blanc | 6,1 : 1 | ■ |
| AILCT-08 | Experts | Jaune | `#F2C200` | Quasi-noir | 10,6 : 1 | ✱ |

### 3.1. Constats de lisibilité à retenir

1. **Le texte blanc sur or (2,4 : 1), sur jaune (1,7 : 1) et sur argent (1,9 : 1) est illisible** ; ces trois catégories exigent un texte foncé. L'orange (3,1 : 1 en blanc) aussi. La règle « texte blanc partout » de l'atelier actuel ne convient pas.
2. **Séparation par la luminosité insuffisante** entre catégories voisines : bleu / vert (ratio 1,15), rouge / bronze (1,28), argent / or (1,29), or / jaune (1,44). Sur une impression en niveaux de gris, une photocopie ou pour une personne daltonienne, **plusieurs couleurs se confondent**.
3. **Conséquence — la couleur ne doit jamais être le seul repère.** Chaque badge porte, en plus de la couleur : le **nom de la catégorie en toutes lettres**, le **code `AILCT-0n`** et un **symbole**. Le produit applique déjà ce principe (`CategorySymbol.php`, « la couleur n'est jamais le seul repère ») mais avec **quatre symboles connus seulement** (VIP, Visiteur, Prestataire, Stagiaire) : il en faudra huit stables, rattachés à la catégorie institutionnelle et non à l'ordre alphabétique des catégories de l'événement (aujourd'hui l'index dans la liste triée des identifiants **change selon l'événement** pour les autres catégories : à éviter).
4. Le **bronze** et l'**argent** sont des couleurs métalliques : leur rendu sur PVC dépend de l'imprimante (aplat, dégradé ou impression métallique). Aucune promesse ne peut être faite avant les **essais d'impression réels** [D-12].

### 3.2. Nature des catégories et validité [H]

La **validité n'est pas décidée** (V-BD-13). Proposition de départ, à valider :

| Code | Nature | Délivrance typique | Validité par défaut [H] | Photo [H] |
|---|---|---|---|---|
| AILCT-01 Personnel | **Permanent** | Ressources humaines / sécurité | 12 mois, renouvelable | Obligatoire |
| AILCT-02 Partenaires permanents | **Permanent** | Convention de partenariat | 12 mois, renouvelable | Obligatoire |
| AILCT-03 Intervenants occasionnels | Mission | Par mission ou session | Durée de la mission | Obligatoire |
| AILCT-04 Délégations officielles | Événement | Par événement officiel | Durée de l'événement | À arbitrer (protocole) |
| AILCT-05 Visiteurs | Jour | À l'accueil | Journée | Non (reste inchangé) |
| AILCT-06 Accompagnateurs | Jour / événement | Accompagne un visiteur ou une délégation | Journée ou événement | Non |
| AILCT-07 Stagiaires | Session | Par session de formation | Durée de la session | Obligatoire |
| AILCT-08 Experts | Mission | Par mission | Durée de la mission | Obligatoire |

Ces durées sont **des hypothèses de travail**, pas des règles. **[D-09]**

## 4. Modèle de données : ce qui existe, ce qu'il faut décider

### 4.1. Ce qui existe [C]

- `events` → `categories` (par événement) → `category_zone` → `zones` : le cœur de l'autorisation.
- `visitors` : une ligne **par personne et par événement** (`event_id`), avec `category_id`, `organisation`, `position` (fonction, ajoutée par la migration `2026_11_13_…profile_portraits_and_badge_preparation`), `valid_from` / `valid_until`, portrait chiffré facultatif et `profile_photo_print_allowed` (jamais coché par défaut).
- `badges` : `event_id`, `code` (`B-001`…, unique **dans l'événement**), `qr_token` (64 hexadécimaux, unique globalement), `status` (Disponible, Associé, Restitué, Annulé, Clos), `category_id`, `printed_for_visitor_id` (destination nominative immuable), réservations poste/groupe. Lots : `badge_batches` par événement et catégorie.
- `retired_badge_identifiers` : mémoire des codes `B-xxx` supprimés, pour ne jamais les réattribuer.
- `badge_assignments` : historique des remises (un seul actif par badge et par visiteur, contraintes en base).
- **Tout est rattaché à un événement.** Le produit n'a **pas de notion de personne permanente** ni de badge hors événement.

### 4.2. Le référentiel institutionnel (BD-01, BD-02) — **proposition [H]**

- **Définition versionnée** des huit catégories (code, nom, couleur, couleur de texte, symbole, libellés), portée par le profil institutionnel (voir le document d'identité), éventuellement reflétée en base pour y être référencée.
- **Liaison explicite** : une catégorie d'événement peut porter une référence à une catégorie institutionnelle (colonne **additive et nullable**, par exemple `categories.institutional_code`). Une catégorie sans liaison garde le comportement actuel (installations non-AILCT inchangées, V-ID-11).
- **Unicité par événement** : une seule catégorie d'événement par catégorie institutionnelle dans un événement donné **[H]**, pour que « le badge Experts » ait un sens univoque dans cet événement.
- **Aucune** colonne de droits n'est ajoutée à la catégorie institutionnelle.

### 4.3. Les titulaires permanents (BD-03) — **décision structurante [D-07]**

Le problème : le personnel et les partenaires permanents (AILCT-01, 02) n'appartiennent pas à un événement ; le produit ne sait représenter une personne qu'**à l'intérieur** d'un événement (V-BD-05). Trois options :

| Option | Principe | Avantages | Inconvénients et risques |
|---|---|---|---|
| **A — Événement permanent** | Créer un événement « Académie — permanent », longue durée, qui porte les zones, catégories et visiteurs permanents. | Aucune évolution de schéma ; tout le moteur fonctionne tel quel. | Contraire à l'esprit de V-BD-05 (« artificiellement ») : le statut permanent dépend d'un événement. Les fonctions de **clôture, anonymisation et purge** (`DataRetention`, `docs/LOT33_CONTRATS.md`) traiteraient cet événement comme les autres : **risque d'effacement ou d'anonymisation du personnel**. Le contrôle compare les dates de l'événement. Zones et catégories à dupliquer pour chaque événement ordinaire où un permanent entre aussi. |
| **B — Registre de titulaires** | Nouvelle entité « titulaire institutionnel » (personne permanente), reliée par une clé **additive** aux fiches `visitors` de chaque événement où elle participe. | Respecte V-BD-05 : le statut permanent est indépendant d'un événement. Permet un historique unique par personne et des durées de validité propres. | Évolution de schéma importante et nouvelles écrans/permissions. Impacte F02 (listes, doublons, homonymes), F09 (conservation : base légale propre au personnel) et la synchronisation tablette. Risque de **second moteur** de personnes à maintenir. |
| **C — Hybride** (recommandée en principe [H]) | Registre de titulaires **léger** (identité + catégorie institutionnelle + validité + identifiant imprimé), et le moteur d'événements continue de porter les **passages** : à chaque événement, une fiche `visitors` est créée ou rattachée au titulaire. | Garde le moteur d'accès, de contrôle et d'audit intact ; porte le statut permanent hors événement ; limite le périmètre de l'évolution. | Exige une décision sur la **synchronisation** (qui crée la fiche d'événement ?), la **conservation** du registre face à la purge et la **cohérence** entre la fiche d'événement et le titulaire. À instruire par une note de décision d'architecture avant le lot A04. |

**Recommandation [H]** : option C, **sous réserve d'une note de décision d'architecture** (ADR) validée avant A04. L'option A est à écarter pour la production sans garde-fous de conservation. **Ne rien migrer tant que [D-07] n'est pas tranché.**

### 4.4. L'identifiant imprimé AILCT (BD-04) — **proposition [H]**

Quatre identifiants coexistent ; il ne faut jamais les confondre :

| Identifiant | Rôle | Visible ? | Où |
|---|---|---|---|
| UUID du badge / de la personne | Clé technique | Non | Base |
| Code interne `B-xxx` | Numéro de lot, unique **par événement**, lu à la main à l'accueil | Oui (accueil) | `badges.code` |
| Jeton QR (64 hexadécimaux, préfixe `IKOMA1:`) | Secret opaque lu par le contrôle | Dans le QR seulement | `badges.qr_token` |
| **Identifiant imprimé AILCT** (nouveau) | Référence institutionnelle du porteur ou de la carte, lisible par un humain, sur le verso | Oui, imprimé | à créer |

Propriétés exigées [V-BD-06] : unique dans son périmètre, stable, non réattribuable. Propositions :

- **Forme [H]** : `AILCT-<code catégorie>-<numéro séquentiel>-<clé de contrôle>`, par exemple `AILCT-07-000123-4`. Exemple purement illustratif.
- **Séquence** : générée par une **séquence PostgreSQL** (jamais décrémentée, jamais recyclée), au niveau de l'installation entière **[D-08]** (pas de remise à zéro par année, qui créerait des doublons après deux ans).
- **Clé de contrôle** (somme de contrôle, par exemple modulo 97 ou algorithme de Luhn) pour détecter une faute de saisie à la lecture manuelle.
- **Non-réattribution** : sur le modèle de `retired_badge_identifiers`, une table de mémoire des identifiants **émis**, protégée en ajout seul.
- **Réimpression** : le même identifiant reste imprimé (V-BD-10). Un **duplicata après perte** : nouvelle carte, donc nouvel identifiant de carte **[D-10]** ; l'identifiant de la **personne** peut rester stable (si le registre existe, option B ou C). Il faut décider lequel des deux est « l'identifiant imprimé » : celui du titulaire ou celui de la carte **[D-08]**.
- Ne jamais déduire un droit de cet identifiant ni de son préfixe.

## 5. Les trois niveaux de vérification d'un badge [C]

Le contrôle d'un badge scanné suit la chaîne actuelle, **inchangée** par ce projet :

1. **QR lu** → jeton opaque, avec ou sans préfixe `IKOMA1:`.
2. **Serveur** (`BadgeService::controler`) : badge connu ? de cet événement ? statut `Associé` ? visiteur de la bonne période (`valid_from` / `valid_until`) ? sinon refus motivé, journal `badge.controle_refuse`, alerte au superviseur.
3. **Zone** : la catégorie d'**événement** du visiteur couvre-t-elle la zone du point de contrôle ? (`category_zone`). Sinon, « zone non autorisée ».
4. **Hors ligne** : la tablette applique les mêmes règles sur sa copie chiffrée (`resources/js/poste/local/site.ts`) : statut, période, catégorie → zone. Une **annulation décidée entre-temps n'est connue qu'après synchronisation** (`ATELIER_BADGES.md` §5).

La catégorie **institutionnelle** peut s'ajouter à l'**affichage** du verdict (couleur et intitulé, pour que l'agent reconnaisse d'un coup d'œil), **jamais à la décision**.

## 6. Spécification recto-verso (CR80 vertical)

### 6.1. Cadre commun [V-BD-08] et mesures

- Format fini : **53,98 × 85,60 mm**, orientation portrait. Le produit sait déjà produire ce format : `BadgeArtwork::dimensions('portrait')` renvoie `[53.98, 85.60]` et le CSS `@page` de l'impression PVC (`artwork-print.blade.php`) [C].
- **Fond perdu [H]** : 1,5 mm autour pour un aplat de couleur jusqu'au bord ; à confirmer selon l'imprimante (certaines laissent un bord blanc) **[D-12]**.
- **Zone de sécurité [H]** : aucun texte, logo ni QR à moins de **3 mm** du bord fini (règle déjà annoncée par l'atelier actuel).
- **QR (mesuré)** [C] : la charge utile actuelle (`IKOMA1:` + 64 caractères = 71 octets, niveau de correction Q) donne une matrice de **41 × 41 modules** (49 avec la zone de silence de 4 modules). Taille imprimée selon la taille d'un module : 0,4 mm → 19,6 mm ; **0,5 mm → 24,5 mm** ; 0,6 mm → 29,4 mm. Le produit imprime aujourd'hui à **27 mm** (paysage) et **30 mm** (portrait). **Proposition [H] : 28 mm minimum** au verso, noir sur blanc, jamais teinté (règle existante), sans logo au centre.
- **Photo [C]** : portrait de 360 × 480 pixels (rapport 3 : 4), JPEG réencodé sans métadonnées, chiffré au repos ; usage sur un badge **uniquement** si l'autorisation propre à la fiche est cochée (`profile_photo_print_allowed`). **Proposition [H] : cadre 24 × 32 mm** au recto.
- **Tailles de texte minimales [H]** : nom ≥ 10 pt gras ; fonction et organisation ≥ 7 pt ; référence et dates ≥ 6,5 pt ; mentions légales ≥ 5,5 pt. Valeurs à vérifier sur tirage physique.

### 6.2. Recto — proposition de répartition verticale [H]

| Zone | Hauteur indicative | Contenu | Source de la donnée |
|---|---:|---|---|
| Bandeau institutionnel | ≈ 14 mm | Logo AILCT (original fourni) et nom court | Profil institutionnel |
| Photographie | ≈ 32 mm | Portrait, cadre 3 : 4 | `ProfilePortrait` (autorisation de la fiche) |
| Identité | ≈ 16 mm | Nom complet (2 lignes au plus) ; **fonction / qualité** ; organisation | `visitors.full_name`, `position`, `organisation` |
| Bandeau de catégorie | ≈ 12 mm | **Couleur de la catégorie, nom en toutes lettres, code `AILCT-0n`, symbole** | Référentiel institutionnel |
| Référence | ≈ 6 mm | Identifiant imprimé AILCT | Nouveau (BD-04) |

Total ≈ 80 mm pour 85,6 mm : marge d'ajustement ≈ 5 mm.

### 6.3. Verso — proposition de répartition verticale [H]

| Zone | Hauteur indicative | Contenu | Source de la donnée |
|---|---:|---|---|
| Rappel de catégorie | ≈ 6 mm | Fine bande de la couleur de catégorie avec son nom | Référentiel |
| **QR** | ≈ 30 mm | QR du jeton opaque | `badges.qr_token` via `QrSvg` |
| Référence | ≈ 8 mm | Identifiant imprimé AILCT, lisible à la main | Nouveau |
| Validité | ≈ 8 mm | « Valable du JJ/MM/AAAA au JJ/MM/AAAA » | `visitors.valid_from`, `valid_until` |
| Mentions institutionnelles | ≈ 22 mm | Propriété de l'AILCT ; conduite à tenir en cas de perte (qui prévenir) ; obligation de restitution ; texte légal fourni par le client | **À fournir [D-15]** |

Aujourd'hui le **QR est au recto** et le **verso est un texte générique de consignes** (`card.blade.php`) : l'inversion demandée par le contrat (QR au verso) est une **évolution [C → P]**, non un réglage.

### 6.4. Champs variables par catégorie [H]

Le dossier prévoit un **jeu de champs par catégorie** (affichage de la photo, de la fonction, de l'organisation, de la période, mentions spécifiques). Exemple de principe : « Visiteurs » sans photo ; « Délégations officielles » avec le nom du pays ou de l'organisation à la place de la fonction. **Rien n'est fixé sans l'examen des originaux [D-11].**

### 6.5. Fiche de relevé des 24 modèles (à remplir sur les originaux)

Pour chacune des huit catégories, pour le recto, le verso et l'assemblage, relever :

| Élément à relever | Recto | Verso |
|---|---|---|
| Dimensions et orientation, fond perdu | ☐ | ☐ |
| Couleurs exactes (valeurs CMJN et RVB fournies par le client) | ☐ | ☐ |
| Polices et corps de texte | ☐ | ☐ |
| Position, taille et version du logo | ☐ | ☐ |
| Photo : position, taille, forme, obligatoire ou non | ☐ | — |
| Champs imprimés (nom, prénom, fonction, organisation, pays, autre) et casse | ☐ | ☐ |
| Libellé de la catégorie (exact, y compris langue) | ☐ | ☐ |
| Identifiant imprimé : format, position | ☐ | ☐ |
| QR : position, taille | — | ☐ |
| Dates de validité : format | — | ☐ |
| Mentions institutionnelles : texte exact | — | ☐ |
| Éléments de sécurité graphiques (guillochis, hologramme, motif, microtexte) | ☐ | ☐ |
| Fixation (cordon, pince, trou) | ☐ | — |

## 7. Cycle de vie d'un badge

### 7.1. États existants et ce qui s'y ajoute [C]

États (CHECK et déclencheur en base) : **Disponible → Associé → Restitué → (Disponible ou Clos)**, et **Disponible / Associé → Annulé**. Une annulation est **définitive**. Aucune transition automatique n'existe. Ce modèle est **conservé tel quel** ; les nouveautés ci-dessous sont des **états dérivés ou des attributs**, jamais de nouveaux statuts, pour ne pas modifier le déclencheur ni les copies hors ligne.

| Étape du cycle | Aujourd'hui | Pour les badges AILCT |
|---|---|---|
| Fabrication | `BadgeLots::generate` : lot par événement et catégorie, codes `B-n`, jetons aléatoires, marge de 10 % | Lot rattaché à la catégorie institutionnelle ; **identifiant imprimé attribué à la préparation ou à la fabrication** [D-08] |
| Préparation nominative | `BadgePreparation::prepare` : destination **immuable** (`printed_for_visitor_id`), refusée si badge déjà utilisé ou autre catégorie | Idem ; pour les permanents, rattachement au titulaire (option B/C) |
| Impression | Atelier : trois modèles, recto et verso **imprimés séparément**, tirage journalisé `badge.impression_preparee`, lot marqué « exposé » | Huit modèles recto-verso ; **appariement** des feuilles recto/verso ; compteur de tirages **[P]** |
| Remise | `BadgeService::associer` à l'accueil | Idem |
| Utilisation | Contrôle serveur et hors ligne, entrées/sorties | Idem |
| Expiration | Évaluée **au contrôle** (`hors_periode`) d'après les dates de la **fiche** du visiteur ; aucun statut « expiré » | Idem, **plus** un état dérivé affiché (« expiré ») et un état des lieux des badges expirés non restitués **[P]** |
| Restitution | `restituer` : Associé → Restitué → Disponible (réutilisable) ou Clos | **Incompatible avec les permanents** (7.3) |
| Révocation | `BadgeService::annuler` : motif obligatoire, permission `badges.annuler` (**superviseur** seulement, l'administrateur ne l'a pas), alerte `badge_annule`, personne « à rebadger » | Autorité à arbitrer [D-10] ; propagation hors ligne [D-13] |
| Réimpression | Même numéro, même QR, mêmes réservations ; ne neutralise pas les copies précédentes | Idem (V-BD-10) ; **perte = annulation puis nouveau badge** |
| Archivage | `Clos` ; conservation et anonymisation par `DataRetention` à l'échéance de l'événement | Registre permanent : base légale et durée à fixer [D-16] |

### 7.2. Expiration, révocation, hors ligne

- **Expiration** [C] : la validité est portée par la **fiche du visiteur dans l'événement**, pas par le badge. Pour un permanent, il faudra une validité **propre au titulaire** (option B/C) ; avec l'option A, elle est limitée par les dates de l'événement permanent.
- **Révocation** [C] : irréversible, motivée, auditée. Le badge Annulé est refusé au contrôle par le serveur **dès l'annulation** ; une tablette **déconnectée** continue de l'accepter jusqu'à sa prochaine synchronisation. Le produit atténue le risque : alerte `badge_annule`, réglage `poste_hors_reseau_max_heures` (12 h au départ : au-delà, la tablette se verrouille), détection d'un même badge présenté à deux points en un temps trop court (`badge_double`). **Pour une institution de lutte contre le terrorisme, la fenêtre résiduelle doit être explicitée et acceptée par le client [D-13]** ; elle peut être réduite en abaissant ce réglage pour les postes sensibles.
- **Perte ou vol** : annulation immédiate par un superviseur, émission d'un **nouveau** badge ; l'ancienne carte reste matériellement valable jusqu'à synchronisation de toutes les tablettes. Rien n'efface à distance un tirage papier ou un PDF (`ATELIER_BADGES_SECURITE.md`).

### 7.3. Incompatibilité majeure : la restitution quotidienne

Le produit est construit pour un **visiteur qui reçoit un badge à l'entrée et le restitue à la sortie** : la **sortie** passe par `restituer`, qui rend le badge `Disponible` (ou `Clos` si « non réutilisable »). Un **membre du personnel ou un partenaire permanent** porte son badge tous les jours et ne le restitue pas. Faire sortir un permanent dans le moteur actuel **fermerait** ou **libérerait** son badge.

À traiter explicitement (BD-13), par exemple :

- une **catégorie institutionnelle « permanente »** pour laquelle la sortie enregistre un **mouvement** (présence/absence) sans restitution ni changement de statut ; **ou**
- un mode de remise distinct du mode visiteur (remise unique, retour seulement en fin de contrat).

Cette décision touche **F04 (accueil), F05 (sorties et présences) et F07 (évacuation : « qui est présent »)**. À instruire en détail au lot A04 **[D-14]**. Rien n'est modifié par ce lot.

## 8. Impression PVC

### 8.1. Ce que fait le produit aujourd'hui [C]

Page d'impression à 100 % (taille réelle) en navigateur ; format CR80 portrait ou paysage, ou planche A4 ; un badge par page en PVC ; **recto et verso sont deux impressions distinctes** ; contrôle de mise en page (chevauchement, débordement) avant impression ; aucune confirmation matérielle de sortie du papier. Le produit **ne contrôle ni le pilote ni le matériel** (`ATELIER_BADGES.md` §4 et `ATELIER_BADGES_SECURITE.md`, « Réserves matérielles »).

### 8.2. Ce que demande AILCT et ce qui manque

| Besoin | Statut |
|---|---|
| Recto-verso fidèle aux originaux | **P** : moteur de rendu à étendre (huit modèles, éléments par catégorie) |
| **Duplex** | **D-12** : imprimante recto-verso native ou deux passes. Le produit ne promet **aucune** gestion automatique du duplex ; il faut au minimum **apparier** recto et verso (même ordre, même numéro) et donner une procédure de réinsertion. |
| Fond perdu et marges propres au pilote | **C/P** : à calibrer avec le matériel réel |
| Fidélité des couleurs | Les originaux seront probablement fournis en **CMJN** ; l'écran et le navigateur travaillent en **RVB**. Les teintes métalliques (argent, or, bronze) ne se reproduisent pas fidèlement en quadrichromie. **Épreuve d'impression obligatoire** avant tirage de production. |
| Contrôle de qualité | Essais physiques : lisibilité du QR (scan réel), tenue des couleurs, alignement recto-verso, résistance de la carte |
| Éléments de sécurité physiques (hologramme, guillochis, UV, microtexte) | **Hors périmètre** par défaut ; à décider si le client les exige **[D-18]** |

## 9. Sécurité et vie privée

| Risque | Parade existante [C] | Complément proposé |
|---|---|---|
| Badge **perdu ou volé** | Annulation par le superviseur ; alerte ; contrôle serveur | Délai de propagation hors ligne explicité [D-13] ; plan d'incident de perte |
| **Copie du QR** (photo ou photocopie) | Jeton opaque ; alerte `badge_double` ; aucune donnée personnelle dans le QR | Information du client ; la photo au recto est le contrôle visuel de la personne |
| **Contrefaçon visuelle** d'une carte (couleur, logo) | Aucune : une carte contrefaite sans QR valide est refusée au contrôle | Éléments de sécurité physiques si exigés [D-18] ; **ne jamais accepter une carte sur sa seule apparence** |
| Fuite de la **photo** ou de l'identité | Portrait chiffré, jamais envoyé aux tablettes ni mis en cache ; accès authentifié et audité | Base légale spécifique pour le personnel permanent [D-16] |
| Lecture du QR par un **tiers** | Le contenu `IKOMA1:<jeton>` ne révèle aucune donnée | Information du client sur le préfixe visible dans un lecteur tiers [D-19] |
| **Énumération** des identifiants imprimés | Non applicable aujourd'hui | Identifiant avec clé de contrôle ; **aucune** page publique de vérification ; jamais de droit déduit |
| Cartes **vierges** volées avant personnalisation | Un badge « Disponible » non associé est refusé au contrôle | Stock de cartes physiques sous clé ; hors du logiciel |
| PDF et feuilles d'impression conservés | Pages non mises en cache ; jetons dans les QR | Procédure de destruction des tirages ratés |

## 10. Traçabilité

Actions d'audit **existantes** [C] : `badge.lot_cree`, `badge.reserve`, `badge.associe`, `badge.restitue`, `badge.annule`, `badge.controle_refuse`, `badge.destination_preparee`, `badge.modele_enregistre`, `badge.impression_preparee`, `badge.reglage_modifie`. Aucune donnée personnelle n'y figure (nombres, identifiants techniques).

À **ajouter [P]** : attribution d'un identifiant imprimé ; liaison catégorie institutionnelle ↔ catégorie d'événement ; **tirage de chaque face** (recto, verso) avec numéro de tirage ; réimpression ; expiration constatée ; création et modification d'un titulaire permanent. Contraintes : pas de nom ni de photo dans le journal ; journal en ajout seul ; **aucune écriture rétroactive** sur l'historique existant.

## 11. Récapitulatif des adaptations, classées

| Réf. | Adaptation | Existe | À configurer | Évolution produit | Dépendances | Risques principaux |
|---|---|:-:|:-:|:-:|---|---|
| BD-01 | Référentiel des 8 catégories (code, nom, couleur, symbole) | | | **P** | Profil institutionnel (identité) | Couleurs officielles inconnues [D-02, D-11] |
| BD-02 | Liaison catégorie institutionnelle ↔ catégorie d'événement | | | **P** (colonne additive) | BD-01 | Confusion catégorie/autorisation (test de garde) |
| BD-03 | Titulaires permanents | | | **P** (ADR requise) | [D-07] | Conservation/purge, doublon de moteur |
| BD-04 | Identifiant imprimé AILCT | | | **P** | [D-08], BD-03 | Périmètre, réattribution, années |
| BD-05 | Validité par catégorie | partiel (dates de fiche) | **C** | P (validité propre au titulaire) | [D-09], BD-03 | Durées non décidées |
| BD-06 | Huit modèles recto-verso | trois modèles | | **P** | 24 originaux | Fidélité, couleurs, polices |
| BD-07 | Photographie | **E** (portrait volontaire) | **C** (obligation par catégorie) | | [D-11], [D-16] | Base légale, consentement |
| BD-08 | QR (jeton opaque, `IKOMA1:`) | **E** | | | — | Taille d'impression, lecteurs tiers |
| BD-09 | Impression PVC duplex, fond perdu, épreuves | simple face | | **P** / essais | [D-12], matériel | Alignement, couleurs métalliques |
| BD-10 | Vérification serveur et hors ligne | **E** | | | — | Révocation non synchronisée |
| BD-11 | Expiration (état dérivé, état des lieux) | contrôle à la volée | | **P** (affichage, rapport) | BD-05 | Faux sentiment de sécurité |
| BD-12 | Révocation | **E** (superviseur) | **C** (autorité) | | [D-10], [D-13] | Rôle dédié refusé (simplicité) |
| BD-13 | Sortie / restitution des permanents | restitue à la sortie | | **P** | [D-14], BD-03 | **Impact F04, F05, F07** |
| BD-14 | Réimpression | **E** | | **P** (compteur de tirages) | BD-04 | Copies antérieures non neutralisées |
| BD-15 | Traçabilité (nouvelles actions d'audit) | partiel | | **P** | — | Aucune donnée personnelle au journal |
| BD-16 | Saisie et import (catégorie institutionnelle, fonction) | partiel (`position`) | **C** | **P** (colonnes d'import) | BD-01 | Homonymes, doublons |
| BD-17 | Atelier : jeu de modèles du profil, couleurs verrouillées | trois modèles, titre et couleurs libres | | **P** | BD-06 | Un opérateur ne doit pas pouvoir « personnaliser » une couleur officielle |
| BD-18 | Étiquettes de lot et lots par catégorie institutionnelle | **E** | **C** | | BD-02 | — |

## 12. Impact sur les neuf modules fondateurs

Aucun module n'est retiré, réduit ni renommé (ACCESS-FOUNDATION-001). Cet impact s'ajoute à celui de l'identité.

| Module | Impact des badges institutionnels | Risque | Tests |
|---|---|---|---|
| **F01 Administration** | Écrans de gestion du référentiel et de la liaison (lecture seule d'abord) ; aucune permission nouvelle prévue [H]. | Faible | Droits inchangés ; accès refusé aux non-administrateurs. |
| **F02 Personnes et listes** | Colonnes d'import (catégorie institutionnelle, fonction) ; **titulaires permanents** (option B/C) ; doublons et homonymes. | **Élevé** si registre | Import avec et sans colonnes nouvelles ; recherche ; homonymes. |
| **F03 Badges** | Cœur du changement : référentiel, identifiant imprimé, huit modèles, impression duplex, tirages. | **Élevé** | Cycle complet ; réimpression idempotente ; QR inchangé. |
| **F04 Poste d'accueil** | Affichage de la catégorie institutionnelle dans la fiche et le verdict ; remise de badge permanent. | Moyen | Parcours en sept étapes inchangé pour les visiteurs. |
| **F05 Contrôle et sorties** | **Sortie d'un permanent sans restitution** ; verdict affichant la catégorie. QR et préfixe inchangés. | **Élevé** | Entrées/sorties répétées d'un permanent ; zones autorisées/interdites ; hors ligne. |
| **F06 Groupes et cars** | Catégorie « Stagiaires » (AILCT-07) et « Accompagnateurs » ; le module reste **désactivable** : le référentiel ne doit pas supposer F06 actif. | Moyen | Mêmes tests avec F06 inactif puis actif. |
| **F07 Pilotage** | Liste d'évacuation : catégorie visible ; présence des permanents. | Moyen | Évacuation avec permanents et visiteurs. |
| **F08 Espace hôte** | L'hôte ajoute des invités de catégorie « Visiteur » : correspondance avec AILCT-05 ; l'hôte **ne choisit pas** d'autre catégorie institutionnelle. | Faible | Droits de l'hôte inchangés. |
| **F09 Audit, conservation** | Nouvelles actions d'audit ; durée de conservation du registre permanent ; reçu d'export. | Moyen | Journal en ajout seul ; purge qui **n'efface pas** un titulaire permanent actif. |

## 13. Tests nécessaires

1. **Garde « catégorie ≠ autorisation »** : changer la catégorie institutionnelle ne change aucun verdict ; changer la catégorie d'événement le change ; aucune lecture de la couleur ou du code AILCT dans les services de contrôle (test d'architecture).
2. **Non-régression des QR et des badges existants** : badges déjà imprimés lus avant/après ; aucune réécriture de jeton, d'association ou de journal.
3. **Cycle de vie** : fabrication → préparation → impression → remise → contrôle → expiration → révocation → réimpression ; statut et audit à chaque étape ; transitions interdites toujours refusées par le déclencheur.
4. **Identifiant imprimé** : unicité sous concurrence, clé de contrôle, non-réattribution après suppression d'un lot, stabilité à la réimpression.
5. **Hors ligne** : tablette avec copie ancienne face à un badge annulé ; délai de verrouillage ; resynchronisation.
6. **Permanents** : entrées et sorties répétées sans changer le statut du badge ; expiration de la validité.
7. **Rendu** : huit modèles × recto/verso ; noms très longs, caractères accentués, absence de photo ; aucun débordement (contrôle de mise en page existant).
8. **Accessibilité** : contrastes calculés par catégorie ; présence systématique du nom, du code et du symbole.
9. **Impression réelle** : tirage physique PVC CR80, calibration, épreuve couleur comparée aux originaux, **scan réel** du QR avec les lecteurs de l'accueil, contrôle en zone autorisée et interdite, expiration, révocation, recto-verso apparié (exigence V-BD-10).
10. **Confidentialité** : aucune donnée personnelle dans le QR, le journal ni la copie des tablettes ; photo uniquement si autorisée.

## 14. Hypothèses et décisions (extrait)

Registre complet : `A02-PLAN-IMPLEMENTATION.md`, section 8.
**[D-02]** couleurs officielles ; **[D-07]** titulaires permanents ; **[D-08]** identifiant imprimé ; **[D-09]** durées de validité ; **[D-10]** autorité de révocation et de duplicata ; **[D-11]** champs exacts des 24 modèles ; **[D-12]** duplex, fond perdu, matériel ; **[D-13]** fenêtre de révocation hors ligne ; **[D-14]** sortie des permanents ; **[D-15]** mentions du verso ; **[D-16]** base légale et conservation des données du personnel ; **[D-18]** éléments de sécurité physiques ; **[D-19]** préfixe du QR.
