# A02 — Badges institutionnels AILCT : spécification des huit catégories et du cycle de vie

Statut : **proposition de conception, documentaire — révision R01** (mission AILCT-A02-R01 : sections 15 et 16 ajoutées ; sections 4.3, 7.2, 7.3 et 9 rectifiées). Aucune table, migration, vue, code d'impression, donnée ni permission n'est modifié par ce lot. Elle attend la validation du dirigeant avant toute implémentation.
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

**Statut après la revue R01** : l'option C (hybride) est **étudiée en détail en section 15.4 sans être approuvée**. L'option A reste écartée pour la production sans garde-fous de conservation. Le contrat métier de la section 15 (objets, invariants, relations) est **indépendant du choix de stockage** : il doit rester vrai quelle que soit l'option retenue par l'ADR. **Ne rien migrer tant que [D-07] n'est pas tranché.**

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
- **Révocation** [C] : irréversible, motivée, auditée. Le badge Annulé est refusé au contrôle par le serveur **dès l'annulation**. Une tablette **déconnectée** ne la connaît qu'après synchronisation. **Cette fenêtre n'est pas traitée par une acceptation du client** : elle est **bornée techniquement** par le contrat hors ligne de la section 16 (durée maximale de confiance, refus au-delà, contrôle en ligne obligatoire pour les zones sensibles). Constat [C] à corriger : le réglage `poste_hors_reseau_max_heures` (12 h) n'est aujourd'hui évalué qu'**au déverrouillage** de la tablette (`PosteGate.vue:113`, `runtime.ts:94`), pas à chaque contrôle.
- **Perte ou vol** : annulation immédiate par un superviseur, émission d'un **nouveau** badge ; l'ancienne carte reste matériellement valable jusqu'à synchronisation de toutes les tablettes. Rien n'efface à distance un tirage papier ou un PDF (`ATELIER_BADGES_SECURITE.md`).

### 7.3. Incompatibilité majeure : la restitution quotidienne

Le produit est construit pour un **visiteur qui reçoit un badge à l'entrée et le restitue à la sortie** : la **sortie** passe par `restituer`, qui rend le badge `Disponible` (ou `Clos` si « non réutilisable »). Un **membre du personnel ou un partenaire permanent** porte son badge tous les jours et ne le restitue pas. Faire sortir un permanent dans le moteur actuel **fermerait** ou **libérerait** son badge.

**Exigence fondamentale (mission R01) : la sortie quotidienne d'un permanent ne provoque ni restitution, ni annulation, ni désactivation de son badge.** Le contrat est formalisé en **section 15** (objets, invariants INV-P, relations, conséquences F01–F09). Constat [C] précis : `SiteControl::exit` (`app/Services/Poste/SiteControl.php`, l. 107-127) enregistre la sortie, passe la fiche à `sorti` **et appelle `BadgeService::restituer`** dans la même transaction ; un badge restitué est ensuite refusé au contrôle. **Rien n'est modifié par ce lot.**

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
| Badge **perdu ou volé** | Annulation par le superviseur ; alerte ; contrôle serveur | Contrat hors ligne de la section 16 (durée maximale de confiance, refus, zones sensibles en ligne) ; plan d'incident de perte |
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
**[D-02]** couleurs officielles ; **[D-07]** titulaires permanents ; **[D-08]** identifiant imprimé ; **[D-09]** durées de validité ; **[D-10]** autorité de révocation et de duplicata ; **[D-11]** champs exacts des 24 modèles ; **[D-12]** duplex, fond perdu, matériel ; **[D-13]** contrat de sécurité hors ligne (section 16) ; **[D-14]** invariant de sortie des permanents (section 15) ; **[D-21]** zones sensibles ; **[D-22]** valeurs de durée de confiance ; **[D-15]** mentions du verso ; **[D-16]** base légale et conservation des données du personnel ; **[D-18]** éléments de sécurité physiques ; **[D-19]** préfixe du QR.

## 15. Contrat métier des titulaires permanents (révision R01 — D-07, D-14)

Statut : **proposition de contrat, à valider**. Le contrat décrit **ce qui doit rester vrai**, pas la façon de le stocker. Les choix de stockage (section 15.4) ne sont **pas** approuvés. Les noms d'objets sont des **termes de conception** ; aucun nom de table ou de colonne n'est arrêté ici. Les invariants sont numérotés **INV-P** pour la traçabilité (plan, section 12).

### 15.1. Les cinq objets et leur frontière

| # | Objet | Ce qu'il est | Ce qu'il n'est pas | Existe aujourd'hui [C] |
|---|---|---|---|---|
| O1 | **Titulaire institutionnel** | La personne reconnue par l'institution (personnel, partenaire permanent…) : identité, catégorie institutionnelle AILCT, période d'engagement, état **Actif** ou **Clos**. Existe **hors de tout événement**. | Un visiteur d'événement. Ni une carte. Ni un droit d'accès. | **Non.** Seule la fiche `visitors`, rattachée à un événement. |
| O2 | **Carte physique** | L'objet badge : identifiant imprimé AILCT (section 4.4), jeton QR opaque (inchangé), état, période d'émission, destination nominative immuable. | L'identité de la personne. Un droit. | **Oui** (`badges`, par événement). |
| O3 | **Passage** | Un fait daté : entrée, sortie, contrôle, refus, à un point donné, par un agent ou une tablette. **Ajout seul.** | Un changement d'état de la carte ou du titulaire. Une autorisation. | **Oui** (`access_events`), mais la sortie y est aujourd'hui couplée à la restitution. |
| O4 | **Autorisation d'accès** | La conséquence **calculée**, pour un événement ou un site donné, de la **catégorie d'événement** associée au titulaire : zones de `category_zone`. | Une propriété de la couleur, du code `AILCT-0n` ou de la catégorie institutionnelle. Une donnée saisie sur la carte. | **Oui** (visiteur → catégorie → zones). |
| O5 | **Validité et fin de vie** | Dates de validité, **expiration** (calculée, jamais un statut), **perte** (déclarée), **révocation** (acte). Elles s'appliquent à O1 (fin d'engagement) **et/ou** à O2 (carte). | Une suppression. Un effacement d'historique. | **Partiel** : dates de la fiche ; annulation irréversible de la carte. |

### 15.2. Relations

```
Titulaire (O1) 1 ───< Carte (O2)            une seule carte ACTIVE à la fois par titulaire ;
                                             historique conservé (cartes annulées, remplacées)
Carte (O2)     1 ───< Passage (O3)          un passage référence une carte, un point, un événement
Titulaire (O1) 1 ───< Participation         « le titulaire est attendu dans cet événement/site »
Participation  n ─── 1 Catégorie d'événement ───< Zone     (= Autorisation O4, par category_zone)
```

La **participation** (terme de conception) est le seul lien par lequel un titulaire obtient des droits dans un événement ou un site. Sans participation couvrant l'instant du contrôle, **le contrôle refuse** (refus par défaut).

**Règle de décision d'un contrôle (O1–O5).** Un passage est accepté si et seulement si **toutes** les conditions suivantes sont vraies à l'instant du contrôle : (1) la carte est reconnue et appartient à l'événement ou au site contrôlé ; (2) la carte est dans l'état **Associé**, non annulée, non expirée ; (3) le titulaire est **Actif** et son engagement couvre l'instant ; (4) une participation du titulaire couvre l'instant pour cet événement ; (5) la zone du point de contrôle appartient aux zones de la catégorie d'événement de cette participation ; (6) les données utilisées pour décider respectent le contrat de fiabilité de la section 16. Aucune condition ne lit la couleur, le code `AILCT-0n` ni la catégorie institutionnelle.

### 15.3. Invariants

| Réf. | Invariant | Pourquoi |
|---|---|---|
| **INV-P01** | **Un passage de sortie n'entraîne jamais, ni directement ni indirectement, la restitution, l'annulation, l'expiration ni la désactivation de la carte d'un titulaire permanent.** Il crée un fait O3 et met à jour la présence, rien d'autre. | Exigence fondamentale R01. |
| **INV-P02** | Un passage (entrée, sortie, refus) ne crée, n'étend ni ne retire **aucune** autorisation. | Séparer fait et droit. |
| **INV-P03** | Les droits d'un titulaire dans un événement viennent **exclusivement** de la participation et de sa catégorie d'événement. La catégorie institutionnelle n'ouvre aucune zone. | V-BD-04, V-BD-12. |
| **INV-P04** | À tout instant, un titulaire a **au plus une carte active**. Une carte n'est destinée qu'à **un** titulaire ; cette destination est **immuable** (principe déjà appliqué à la préparation nominative). | Pas de partage de carte. |
| **INV-P05** | L'identifiant imprimé d'une carte n'est **jamais réattribué** (même après annulation), et la **réimpression** d'une carte ne crée ni nouvel identifiant, ni nouvelle autorisation, ni nouveau jeton. | V-BD-06, V-BD-10. |
| **INV-P06** | L'**annulation** d'une carte est **irréversible** et motivée. La **perte** se traite par annulation puis émission d'une **nouvelle** carte (nouvel identifiant de carte, **même titulaire**). | Règle existante `BadgeService::annuler`. |
| **INV-P07** | La **fin d'engagement** du titulaire (état **Clos**, irréversible) rend **toutes** ses cartes inutilisables : annulation de ses cartes actives dans la même opération, motivée et auditée. Une carte physique non rendue reste signalée. | Pas de carte valide sans titulaire actif. |
| **INV-P08** | L'**expiration** est **calculée** (date de fin dépassée), jamais un statut ; elle refuse le contrôle côté serveur **et** hors ligne. Elle ne modifie ni la carte ni l'historique. | Pas de nouveau statut dans le déclencheur de base. |
| **INV-P09** | La **restitution** d'une carte permanente est un **acte explicite** (fin d'engagement ou remplacement), **distinct** de tout passage. Le mode « carte permanente » est porté par un **marqueur explicite** de la carte ou de son lien au titulaire, **jamais déduit** de la couleur, du code ou du nom de catégorie. | Éviter une règle cachée. |
| **INV-P10** | La **conservation** et l'**anonymisation** d'un événement n'effacent, n'anonymisent ni ne désactivent un titulaire **actif** ni sa carte active. Le titulaire suit sa **propre** politique de conservation. | Risque P3 du plan. |
| **INV-P11** | Le flux **visiteur** est inchangé : la sortie d'une carte **non permanente** restitue la carte comme aujourd'hui. Les QR et cartes existants, leur jeton et le préfixe `IKOMA1:` ne sont pas modifiés. | V-BD-11, D-19. |
| **INV-P12** | Aucune donnée personnelle dans le QR, le journal d'audit ni la copie des tablettes au-delà du strict nécessaire au contrôle (pas de photo ni de pièce). | Confidentialité existante. |

### 15.4. Cycle de vie du titulaire et de sa carte

| Événement métier | Titulaire (O1) | Carte (O2) | Passage (O3) | Remarques |
|---|---|---|---|---|
| Admission | créé **Actif** | — | — | Source : décision de l'institution (D-16). |
| Émission de carte | inchangé | préparée puis **Associée** | — | Remise à l'accueil ou par la sécurité ; destination immuable. |
| Entrée | inchangé | inchangée | entrée enregistrée | Contrôle selon 15.2. |
| **Sortie quotidienne** | inchangé | **inchangée** | sortie enregistrée | **INV-P01.** Présence recalculée. |
| Nouvelle entrée le lendemain | inchangé | inchangée | entrée enregistrée | Aujourd'hui impossible [C] : à traiter au lot A06. |
| Expiration | inchangé | inchangée (calculée) | contrôles refusés | INV-P08. Renouvellement = nouvelle période, décision humaine. |
| Perte / vol | inchangé | **Annulée** (motif) puis **nouvelle** carte | — | INV-P06. Alerte superviseur. |
| Réimpression (carte abîmée) | inchangé | **même** carte | — | INV-P05. |
| Révocation de carte | inchangé | **Annulée** | contrôles refusés | Autorité : D-10. |
| Fin d'engagement | **Clos** | cartes actives **Annulées** | — | INV-P07. Restitution physique tracée. |
| Fin d'événement | inchangé | inchangée | — | INV-P10. |

### 15.5. Étude du registre hybride (option C) — **non approuvée**

L'obstacle central [C] : une carte appartient à **un événement** (`badges.event_id`) et le contrôle refuse un badge d'un **autre** événement (`SiteControl.php`, motif `autre_evenement`). Un permanent qui entre dans plusieurs événements ou sites pose donc la question suivante, sur laquelle l'ADR devra trancher.

| Variante d'étude | Principe | Atouts | Points durs / risques |
|---|---|---|---|
| **C1 — Conteneur permanent pour les cartes** | Les cartes permanentes sont émises dans un contexte « installation permanente » (événement de longue durée, protégé contre clôture, anonymisation et purge). Le **registre** porte les titulaires ; les participations relient aux événements ordinaires. | Moteur de contrôle quasi inchangé ; peu d'évolution de schéma. | Le contrôle doit accepter une carte du contexte permanent dans un autre événement : **modification du cœur de contrôle**. Protection de ce contexte contre `DataRetention` à garantir (INV-P10). Risque de ressemblance avec l'option A rejetée. |
| **C2 — Reconnaissance inter-événements** | La carte et le titulaire existent **hors événement** ; le contrôle d'un événement accepte toute carte active dont le titulaire a une participation couvrant l'instant. | Conforme à V-BD-05 (statut permanent indépendant d'un événement). | **Plus grand changement** : contrôle serveur et hors ligne, instantané tablette, annulation, présence (F05, F07), migration du modèle de badges. Test de non-régression très large. |
| **C3 — Une carte par événement pour le permanent** | Le registre fournit l'identité ; une carte est émise **par événement**. | Aucun changement du contrôle. | **Contredit l'usage** : un permanent devrait changer de carte à chaque événement ; identifiant imprimé instable ; coût et risque de perte. Écartée en principe [H]. |

**Critères de choix proposés pour l'ADR** : respect des invariants INV-P01 à INV-P12 ; surface de modification du contrôle serveur et hors ligne ; compatibilité des cartes et QR existants (aucune réécriture) ; réversibilité tant que rien n'est utilisé ; effort de test ; conformité à la conservation (D-16).

**Compatibilité avec les contraintes de compatibilité d'A01 et de BADGE-001** : les trois variantes n'exigent que des **ajouts nullables** pour les données existantes (aucun jeton, QR, association ni historique réécrit). La variante C2, la plus conforme à V-BD-05, est aussi la plus invasive. **Aucune n'est recommandée définitivement** : l'ADR (lot A02.2) doit la trancher, avec essais sur copie de données fictives.

### 15.6. Conséquences sur les modules fondateurs

| Module | Conséquence du contrat permanent | Risque | Tests d'acceptation |
|---|---|---|---|
| F01 Administration | Gestion des titulaires et des participations ; **aucune permission nouvelle présumée** (à confirmer en ADR, sans en créer ici). | Moyen | TA-P10 |
| F02 Personnes et listes | Registre (identité, doublons, homonymes) ; la fiche d'événement se rattache au titulaire sans le remplacer ; import de participations. | Élevé | TA-P08, TA-P09 |
| F03 Badges | Mode « carte permanente » explicite ; lots et destination nominative ; identifiant imprimé. | Élevé | TA-P01, TA-P05 |
| F04 Poste d'accueil | Remise d'une carte permanente ; le parcours visiteur en sept étapes est inchangé. | Moyen | TA-P11 |
| F05 Contrôle et sorties | **Sortie sans restitution** ; ré-entrée possible ; refus d'une carte expirée ou d'un titulaire clos. | **Élevé** | TA-P01 à TA-P04 |
| F06 Groupes et cars | Aucun lien : un permanent n'appartient pas à un groupe d'arrivée. Module toujours désactivable. | Faible | TA-P12 |
| F07 Pilotage et évacuation | Présence des permanents calculée par passages ; liste d'évacuation correcte. | Moyen | TA-P06 |
| F08 Espace hôte | L'hôte ne crée ni ne modifie de titulaire permanent. | Faible | TA-P10 |
| F09 Audit et conservation | Nouvelles actions d'audit ; conservation propre aux titulaires ; INV-P10. | Moyen | TA-P07 |

### 15.7. Tests d'acceptation du contrat permanent

| Réf. | Scénario | Invariants |
|---|---|---|
| TA-P01 | Un titulaire permanent entre puis sort **5 jours de suite** : la carte reste **Associée**, aucun événement de restitution, aucune alerte `badge_annule`. | INV-P01, P02 |
| TA-P02 | Après une sortie, le lendemain, **nouvelle entrée acceptée** en zone autorisée, refusée en zone non autorisée. | INV-P01, P03 |
| TA-P03 | Même scénario pour un **visiteur** : la carte est restituée à la sortie comme aujourd'hui. | INV-P11 |
| TA-P04 | Un permanent dont la carte est **expirée** : entrée refusée serveur **et** hors ligne ; la carte n'est pas modifiée. | INV-P08 |
| TA-P05 | **Perte** : annulation, nouvelle carte, nouvel identifiant de carte ; l'ancienne est refusée ; le titulaire est inchangé. | INV-P04 à P06 |
| TA-P06 | **Évacuation** : permanents présents listés, absents exclus. | INV-P02 |
| TA-P07 | **Purge/anonymisation** d'un événement clos : un titulaire actif et sa carte active sont intacts. | INV-P10 |
| TA-P08 | **Changer la catégorie institutionnelle** d'un titulaire ne change aucun verdict ; changer sa participation le change. | INV-P03 |
| TA-P09 | **Fin d'engagement** : toutes les cartes actives sont annulées dans la même opération ; contrôles refusés. | INV-P07 |
| TA-P10 | **Droits** : seuls les rôles prévus créent ou modifient un titulaire ; hôte et agent d'accueil refusés. | INV-P03 |
| TA-P11 | Parcours visiteur de bout en bout **identique** avant/après. | INV-P11 |
| TA-P12 | Tous les scénarios avec F06 **inactif** puis **actif**. | INV-P11 |
| TA-P13 | QR et badges existants lus avant/après ; aucun jeton réécrit. | INV-P05, P11 |

## 16. Contrat de sécurité des tablettes déconnectées (révision R01 — D-13)

Statut : **proposition de contrat, à valider**. Les valeurs chiffrées sont des **hypothèses [H]** ; les **mécanismes** sont l'objet du contrat. Les invariants sont numérotés **INV-H**.

**Principe directeur (mission R01) : aucune autorisation n'est accordée sur la base de données devenues insuffisamment fiables selon ce contrat.** L'acceptation écrite du risque par le client n'est **pas** une mesure de protection : elle ne peut que documenter le risque **résiduel** qui subsiste après application des mesures techniques ci-dessous.

### 16.1. Ce qui existe [C]

| Constat | Source |
|---|---|
| Copie locale chiffrée de la liste (IndexedDB) ; contrôle hors ligne : statut `Associé`, période de la fiche, catégorie → zone. | `resources/js/poste/local/site.ts` |
| Réglage `poste_hors_reseau_max_heures` (12 h par défaut) : au-delà, la tablette **exige une synchronisation réussie, mais seulement au déverrouillage**. | `runtime.ts:94`, `PosteGate.vue:113`, migration `2026_11_08_000000` |
| Indicateur « données anciennes » d'**affichage** (seuil d'une heure), sans effet sur la décision. | `resources/js/poste/local/sync.ts:34` |
| Le serveur **recontrôle** chaque contrôle hors ligne à la synchronisation ; si l'appareil avait jugé « valide » et que le serveur refuse, l'opération est **mise en quarantaine** (`annule_entre_temps`), un conflit `sync_conflicts` est créé, une alerte `conflit_sync` est levée. | `SyncProcessor.php:216-222, 275-277` |
| Décalage d'horloge détecté (`clock_skew_seconds`) : conflit et alerte. | `SyncProcessor.php:64-71` |
| Révocation et effacement d'un poste ; séquences incompatibles → conflit critique ; opérations idempotentes par identifiant. | `Devices`, `SyncProcessor.php:118-120`, `Operations.php` |
| Révision de liste (`list_revision`) et empreinte des réglages dans l'instantané. | `ListSnapshot.php` |

**Écart de conception à corriger [C → P]** : la limite d'âge des données n'est vérifiée **qu'au déverrouillage**. Une tablette déverrouillée avant le seuil peut continuer à **autoriser** des entrées après le seuil. Le contrat ci-dessous exige une vérification **à chaque décision**.

### 16.2. Notions

- **Instant de référence des données** : l'heure **du serveur** consignée dans le dernier instantané **reçu et validé en entier** (jamais l'heure d'une tentative, jamais l'heure de l'appareil seule).
- **Âge des données** : durée écoulée depuis l'instant de référence, mesurée avec la meilleure horloge disponible et **garde d'intégrité** (16.4).
- **Durée maximale de confiance** (DMC) : âge au-delà duquel les données **ne suffisent plus** pour autoriser.

### 16.3. Invariants

| Réf. | Invariant |
|---|---|
| **INV-H01** | Toute décision d'**autorisation** hors ligne (entrée, passage de zone) vérifie, **au moment de la décision**, que l'âge des données est ≤ DMC. Sinon : **refus**. |
| **INV-H02** | Au-delà de la DMC, la tablette ne produit **jamais** un verdict « valide ». Elle produit un refus de motif explicite « données trop anciennes » et oriente l'agent vers la procédure de repli. |
| **INV-H03** | Une **révocation non reçue** ne peut pas être connue. La protection est donc **structurelle** : (a) l'âge des données est borné par la DMC ; (b) les zones classées **sensibles** exigent un contrôle **en ligne** et sont refusées hors ligne ; (c) toute acceptation hors ligne est **recontrôlée** par le serveur à la synchronisation. |
| **INV-H04** | Le serveur est **seule autorité** ; un verdict local n'est jamais définitif et ne peut jamais **élargir** un droit par rapport au verdict serveur. |
| **INV-H05** | Toute décision hors ligne (acceptation **ou refus**) est **journalisée localement** avec ses éléments de preuve et transmise à la synchronisation ; aucune décision n'est perdue ni modifiée. |
| **INV-H06** | Une acceptation hors ligne **contredite** par le serveur n'est jamais effacée ni réécrite : elle devient un **conflit visible**, traité par un humain habilité. |
| **INV-H07** | Une anomalie d'intégrité (horloge, instantané, appareil) **abaisse la confiance à zéro** : refus jusqu'à resynchronisation réussie. |
| **INV-H08** | Les fonctions de **sécurité des personnes** (liste d'évacuation locale, enregistrement des **sorties**) restent disponibles hors confiance : elles n'accordent aucun accès. [H, à valider] |
| **INV-H09** | Les paramètres de sécurité sont des **réglages métier** de l'administration (table de réglages et écran d'administration), jamais des constantes de code ni de fichiers techniques. |
| **INV-H10** | Un instantané n'est utilisé que pour **son** événement ou site, à **sa** version de contrat ; sinon refus. |

### 16.4. Durée maximale de confiance et intégrité du temps

| Élément | Contrat proposé |
|---|---|
| Réglage général | La DMC générale reprend `poste_hors_reseau_max_heures` (**défaut actuel 12 h**), évalué **à chaque décision**. Valeur par défaut AILCT à arbitrer (**[H] 4 h**) ; 0 = pas de limite est **interdit** pour une installation AILCT [H]. |
| Seuil d'alerte | Un seuil d'avertissement (**[H]** 50 % de la DMC) alerte l'agent et le superviseur avant le refus. |
| Âge de référence | Heure serveur de l'instantané **complet et validé** ; une synchronisation partielle ou interrompue **ne renouvelle pas** la confiance. |
| Horloge de l'appareil | Si l'heure de l'appareil est **antérieure** à la dernière heure connue, ou si le décalage dépasse `clock_skew_seconds`, la confiance est **nulle** (INV-H07) jusqu'à resynchronisation. |
| Redémarrage | Le calcul d'âge survit au rechargement (valeur persistée) ; il ne repart jamais de zéro. |

### 16.5. Comportement à l'expiration de la confiance

1. Les verdicts d'autorisation deviennent des **refus motivés** (« données trop anciennes — synchronisez ou orientez vers le contrôle manuel »). Le refus est **journalisé** (INV-H05).
2. L'écran indique l'âge des données, l'heure de la dernière synchronisation et la procédure de repli.
3. **Procédure de repli** [H] : retour du réseau ; sinon décision **humaine** hors application sous l'autorité du superviseur, **sans** que l'outil enregistre une entrée acceptée. Cette procédure est un document d'exploitation, non une fonctionnalité.
4. Les sorties peuvent être enregistrées (INV-H08). Les entrées ne sont pas autorisées.
5. Une synchronisation réussie complète rétablit la confiance **immédiatement**, sans intervention.

### 16.6. Traitement d'une révocation non encore reçue

| Situation | Traitement |
|---|---|
| Carte annulée côté serveur, tablette hors ligne **dans la DMC** | La tablette ne peut pas le savoir. **Mesures structurelles (INV-H03)** : zones sensibles refusées hors ligne ; à la synchronisation, l'opération est recontrôlée, mise en **quarantaine**, un conflit et une **alerte** sont créés (comportement existant). **Risque résiduel borné par la DMC et limité aux zones non sensibles.** |
| Carte annulée, tablette **en ligne** | Refus immédiat (contrôle serveur). |
| Tablette récupérant la révocation à la prochaine synchronisation | La carte est marquée localement ; tout contrôle ultérieur est refusé **avant** la fin de la synchronisation des opérations. |
| Fin d'engagement d'un titulaire (INV-P07) | Même traitement : annulation de ses cartes, propagée à la synchronisation. |
| Tablette perdue ou volée | Révocation de l'appareil (existant) ; effacement local à la reconnexion ; DMC bornant l'usage hors ligne ; verrouillage par inactivité et code (existants). |

**Réduction supplémentaire proposée [P]** : à la reconnexion, la tablette **récupère d'abord** les révocations (téléchargement léger par révision) **avant** d'autoriser un nouveau contrôle, puis envoie ses opérations.

### 16.7. Conditions imposant un refus de contrôle

Le contrôle hors ligne **refuse** (jamais « valide ») dans chacun des cas suivants, avec un **code de motif** distinct et journalisé :

| Code de motif | Condition |
|---|---|
| `donnees_perimees` | Âge des données > DMC (générale ou de la zone). |
| `horloge_anormale` | Horloge antérieure à la dernière connue, ou décalage > seuil. |
| `instantane_invalide` | Instantané absent, corrompu, d'un autre événement, ou de version de contrat inférieure au minimum (INV-H10). |
| `appareil_revoque` | Poste révoqué ou effacement demandé. |
| `evenement_clos` | Événement clos ou archivé. |
| `zone_sensible_en_ligne` | La zone du point de contrôle exige un contrôle en ligne et le serveur est injoignable. |
| `inconnu` / `annule` / `restitue` / `non_associe` | Badge inconnu ou dans un état refusé (existant). |
| `hors_periode` / `expire` | Période de la fiche, du titulaire ou de la carte non valide à l'instant (existant et INV-P08). |
| `participation_absente` | Aucune participation couvrant l'instant (INV-P03, option de stockage retenue par l'ADR). |
| `coffre_verrouille` | Copie locale non déchiffrable ou verrouillée. |
| `format_qr_inconnu` | QR non reconnu (préfixe `IKOMA1:` inchangé). |

### 16.8. Journalisation des décisions hors ligne

Pour chaque décision, acceptation **ou** refus, la tablette enregistre localement, dans la file chiffrée existante, et transmet à la synchronisation : identifiant d'opération (idempotent), code de motif, verdict local, **âge des données** et **révision de l'instantané** à l'instant de la décision, heure déclarée de l'appareil, point de contrôle, agent et poste. **Aucune** donnée personnelle (nom, photo) dans le journal d'audit (règle existante). Le serveur ajoute l'heure de réception et son propre verdict. Ces éléments exigent une **évolution [P]** : les champs âge et révision n'existent pas aujourd'hui.

### 16.9. Resynchronisation et conflits

1. Ordre proposé : **(i)** récupérer révocations et révision de liste ; **(ii)** envoyer les opérations en attente, **dans l'ordre**, de façon **idempotente** ; **(iii)** recevoir le nouvel instantané complet ; **(iv)** rétablir la confiance seulement à l'issue de (iii).
2. Le serveur **recontrôle** chaque opération avec les règles actuelles (comportement existant). Un verdict local « valide » contredit par le serveur crée un **conflit** `sync_conflicts` (motif, éléments de preuve), une **alerte** `conflit_sync` et laisse l'**historique intact**.
3. **Aucune réécriture** rétroactive : un passage ne se « dé-valide » jamais ; il est annoté par la résolution du conflit.
4. **Résolution** : par le superviseur habilité (existant), avec motif obligatoire, journalisée. Un conflit **critique** (séquences incompatibles, appareil révoqué) bloque l'appareil jusqu'à traitement (existant).
5. Les collisions (même carte à deux points en un temps trop court, `badge_double`) restent des alertes (existant).
6. Un conflit **ouvert** empêche la clôture d'événement (existant, LOT33).

### 16.10. Paramètres propres aux sites sensibles

Pour respecter l'exigence de simplicité du produit (la suppression antérieure des « profils de sécurité » est maintenue), **aucun profil groupé n'est réintroduit** : seulement des **réglages individuels** dans l'administration, avec des valeurs par défaut prudentes pour l'installation AILCT.

| Paramètre | Rôle | Valeur AILCT proposée [H] | Existe |
|---|---|---|---|
| DMC générale | Âge maximal pour autoriser | 4 h | Réglage **existant** (12 h), contrôle à étendre |
| Seuil d'avertissement | Alerte avant refus | 50 % de la DMC | À créer [P] |
| DMC par **zone** | Âge maximal plus court pour une zone | 0 à 1 h | À créer [P] |
| Zone « contrôle en ligne obligatoire » | Refus hors ligne | Zones à haute sensibilité (D-21) | À créer [P] |
| Tolérance d'horloge | Seuil de décalage | existante | **Existe** (`clock_skew_seconds`) |
| Fréquence minimale de synchronisation | Rafraîchissement quand le réseau est présent | 5 min | À vérifier [C] |
| Nombre maximal d'opérations en attente | Alerte de dérive | à fixer | Partiel (compteurs) |
| Verrouillage à l'inactivité, tentatives de code | Protection de l'appareil | existants | **Existe** |

La **classification** des zones « sensibles » et la **valeur** de la DMC sont des **décisions humaines** (D-21, D-22) ; le contrat ne les devine pas.

### 16.11. Risque résiduel après contrat

Avec le contrat appliqué : (i) **zéro** fenêtre pour les zones à contrôle en ligne obligatoire ; (ii) pour les autres zones, la fenêtre est **bornée par la DMC** et **détectée a posteriori** (conflit, alerte) ; (iii) une carte contrefaite sans jeton valide reste refusée. Ce qui demeure : un **badge annulé depuis moins de DMC**, présenté à une tablette **hors ligne** devant une **zone non sensible**. Ce risque résiduel est **à documenter**, non à « accepter à la place d'une mesure ».

### 16.12. Conséquences sur les modules fondateurs

| Module | Conséquence | Risque | Tests |
|---|---|---|---|
| F01 | Réglages DMC, zones sensibles ; écran de lecture des conflits. | Moyen | TA-H09 |
| F02 | Instantané : contenu minimal, version de contrat, révision. | Moyen | TA-H08 |
| F03 | Révocation : propagation et marquage local. | Moyen | TA-H03 |
| F04 | Poste d'accueil : même règle de confiance ; messages de refus. | Moyen | TA-H02 |
| F05 | **Cœur** : vérification d'âge à chaque décision, motifs, journal. | **Élevé** | TA-H01 à TA-H07 |
| F06 | Contrôle de groupe hors ligne : même DMC. F06 reste désactivable. | Moyen | TA-H10 |
| F07 | Évacuation locale conservée hors confiance (INV-H08). | Moyen | TA-H06 |
| F08 | Aucun effet. | Nul | — |
| F09 | Nouveaux motifs et champs d'audit ; conflits conservés. | Moyen | TA-H05 |

### 16.13. Tests d'acceptation du contrat hors ligne

| Réf. | Scénario | Invariants |
|---|---|---|
| TA-H01 | Tablette déverrouillée, données à DMC − 1 min : acceptée ; à DMC + 1 min **sans redémarrage ni déverrouillage** : **refus** `donnees_perimees`. | INV-H01, H02 |
| TA-H02 | Après refus par âge, une synchronisation complète rétablit la confiance immédiatement ; une synchronisation **interrompue** ne la rétablit pas. | INV-H02, H07 |
| TA-H03 | Carte annulée côté serveur pendant le hors ligne : acceptation locale, puis à la synchronisation **quarantaine + conflit + alerte** ; historique intact ; contrôle suivant refusé. | INV-H03, H04, H06 |
| TA-H04 | Zone « en ligne obligatoire » : hors ligne **refusée** même avec données fraîches. | INV-H03 |
| TA-H05 | Chaque décision (acceptation et refus) apparaît au journal avec motif, âge des données, révision ; aucune donnée personnelle. | INV-H05 |
| TA-H06 | Données périmées : sortie enregistrable, liste d'évacuation locale disponible, aucune entrée acceptée. | INV-H08 |
| TA-H07 | Horloge reculée ou décalée : refus `horloge_anormale` ; rechargement de l'application : l'âge ne repart pas de zéro. | INV-H07 |
| TA-H08 | Instantané d'un autre événement, ou de version de contrat obsolète : refus. | INV-H10 |
| TA-H09 | Les paramètres sont modifiables dans l'administration seulement par les rôles prévus ; aucune constante en code ; valeurs reprises à la prochaine synchronisation. | INV-H09 |
| TA-H10 | Mêmes scénarios avec F06 inactif puis actif. | — |
| TA-H11 | Un verdict local ne peut jamais ouvrir un droit que le serveur refuse (rejeu). | INV-H04 |
| TA-H12 | Permanent hors ligne : carte expirée refusée ; sortie sans restitution ; ré-entrée dans la DMC. | INV-P01, P08, H01 |
