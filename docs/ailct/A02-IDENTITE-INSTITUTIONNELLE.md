# A02 — Identité institutionnelle AILCT : spécification et inventaire des surfaces

Statut : **proposition de conception, documentaire**. Aucun fichier applicatif, aucune migration, aucune donnée ni permission n'est modifié par ce lot. Elle attend la validation du dirigeant avant toute implémentation.
Base de code étudiée : `main` au commit `9a7729f`.
Documents de référence (sur la PR n° 61, non fusionnée au moment de la rédaction) : `docs/ailct/AILCT-IDENTITY-001.md`, `docs/ailct/AILCT-BADGE-001.md`, `docs/ailct/A01-ARCHITECTURE-ET-COMPATIBILITE.md`. Contrat des modules : `docs/MODULES_FONDATEURS.md` (ACCESS-FOUNDATION-001).
Documents frères : [A02-BADGES-INSTITUTIONNELS.md](A02-BADGES-INSTITUTIONNELS.md), [A02-PLAN-IMPLEMENTATION.md](A02-PLAN-IMPLEMENTATION.md).

## 0. Comment lire ce document

Chaque affirmation porte une étiquette, pour que l'on sache ce qui est acquis et ce qui ne l'est pas.

| Étiquette | Sens |
|---|---|
| **[V]** | Exigence **validée** : citée des contrats AILCT-IDENTITY-001 ou AILCT-BADGE-001, ou de la mission A02. |
| **[C]** | **Constat** vérifié dans le code ou la documentation de `main` (fichier et ligne indiqués). |
| **[H]** | **Hypothèse** de travail : proposée pour avancer, à confirmer ; elle ne vaut pas décision. |
| **[D-nn]** | **Décision à arbitrer** : renvoie au registre du plan (`A02-PLAN-IMPLEMENTATION.md`, section 8). |

Classement de chaque adaptation : **E** = existe déjà dans IKOMA ACCESS ; **C** = à configurer (aucun code) ; **P** = exige une évolution du produit.

### Ce qui n'a pas pu être consulté

Le cahier des charges AILCT, les 24 images de modèles de badges et le fichier source du logo **ne se trouvent pas dans le dépôt** ni sur les branches consultées. Ce document ne décrit donc ni le dessin du logo ni le contenu graphique exact des modèles. Les points qui en dépendent sont marqués **[D-01]**, **[D-02]** ou **[D-11]**.

## 1. Exigences validées

| Réf. | Exigence | Source |
|---|---|---|
| V-ID-01 | L'installation AILCT présente **exclusivement** l'identité AILCT aux utilisateurs. Nom public : AILCT. Dénomination : Académie Internationale de Lutte Contre le Terrorisme. | IDENTITY-001, Vision et Contrat |
| V-ID-02 | IKOMA ACCESS reste le produit sous-jacent, réutilisable pour d'autres clients. | IDENTITY-001, Vision ; mission A02 |
| V-ID-03 | Logo : **original fourni par le client**, intégré après vérification du fichier source et des droits d'usage. **Aucun substitut généré.** | IDENTITY-001, Contrat |
| V-ID-04 | Surfaces à couvrir : accueil, connexion, espaces administration/supervision/agent, menus, titres, notifications, rapports, exports, impressions, badges, favicon, icônes PWA, métadonnées et titres navigateur. | IDENTITY-001, Contrat |
| V-ID-05 | Aucun libellé « IKOMA ACCESS » visible dans l'installation AILCT, **sans renommer aveuglément** classes, routes, tables, permissions ou historiques techniques. | IDENTITY-001, Contrat ; mission A02 §2 |
| V-ID-06 | Palette de **travail** (non officielle) : marine `#0C2D66`, or `#D6B46B`, blanc `#FFFFFF`, gris `#E9EDF3`. Validation graphique ultérieure obligatoire. | IDENTITY-001, Contrat |
| V-ID-07 | Français au départ ; accessibilité, contraste, lisibilité mobile, tablette et ordinateur. | IDENTITY-001, Contrat |
| V-ID-08 | Identité résolue depuis une **configuration institutionnelle explicite** ; aucune logique métier conditionnée par le logo ou le nom. | IDENTITY-001, Contrat |
| V-ID-09 | Une identité par installation existante. **Ne pas prétendre à une architecture multi-tenant** ; l'extension multi-installations exige une décision séparée. | IDENTITY-001, Contrat |
| V-ID-10 | Invariants : F01–F09 préservés, F06 désactivable, rôles, permissions, authentification, QR, contrôles, historiques et données inchangés. **La marque ne confère jamais de droits.** | IDENTITY-001, Invariants |
| V-ID-11 | Critères d'acceptation futurs : inventaire exhaustif des surfaces visibles ; tests des valeurs de repli, titres, impressions et exports ; aucun changement de permission ou de données ; **installation IKOMA non-AILCT inchangée**. | IDENTITY-001, Critères |
| V-ID-12 | Pas de remplacement global des noms internes, routes, permissions ou espaces de noms. | Mission A02 §2 |

## 2. Principe d'architecture proposé : le « profil institutionnel »

**Proposition [H]** — plutôt que de dupliquer le produit ou de remplacer des chaînes de caractères, introduire un **profil institutionnel** : une définition déclarative, versionnée, qui décrit tout ce qui relève de l'identité visible d'une installation. IKOMA ACCESS reste le moteur ; AILCT est un profil.

### 2.1. Contenu d'un profil

| Rubrique | Exemples |
|---|---|
| Identité | nom public (`AILCT`), dénomination complète, nom court (PWA, onglets), slogan facultatif, langue |
| Visuels | logo complet, logo seul (icône carrée), favicon, icônes PWA, version monochrome pour impression |
| Palette | couleurs de marque (marine, or, blanc, gris) et couleurs d'état, fournies comme **variables** et non comme valeurs figées dans les composants |
| Textes | titre et description de la page d'accueil publique, texte de l'écran de connexion, pied de page, mentions légales, signature des exports |
| Documents | en-tête et pied des impressions (évacuation, étiquettes, badges), préfixe des noms de fichiers exportés |
| Mentions | mention éventuelle du fournisseur technique **[D-03]** |

### 2.2. Résolution et repli

1. Le profil actif est choisi **explicitement** par la configuration de l'installation (une valeur, par exemple `IKOMA_INSTITUTION=ailct`) **[H]**.
2. Un service unique de résolution lit le profil, le **valide** (champs obligatoires, formats de couleur, fichiers présents) et expose une structure typée aux trois consommateurs : vues Blade, pages Inertia, services d'export et d'impression.
3. **Repli** : si le profil est absent ou invalide, le profil neutre **IKOMA ACCESS** — strictement identique au rendu actuel — s'applique. Ainsi une installation non-AILCT reste **inchangée** (V-ID-11).
4. Aucune règle métier ne lit le profil : il ne décide ni d'une permission, ni d'un contrôle, ni d'un statut (V-ID-08, V-ID-10). Un test d'architecture peut l'interdire (voir section 9).

### 2.3. Ce qui relève de la construction, ce qui relève de l'exécution [C]

C'est le point d'architecture le plus délicat, car l'identité n'est pas qu'une affaire de texte affiché à l'exécution :

| Élément | Où il est fixé aujourd'hui | Conséquence |
|---|---|---|
| Manifeste PWA (nom, icônes, couleurs) | `vite.config.ts:27`, **à la compilation** | Le profil doit être connu au moment de la construction de l'image, ou le manifeste doit devenir une route servie par Laravel. |
| Pré-cache du service worker (page `/`, icônes, logos) | `vite.config.ts`, liste `additionalManifestEntries` | Les chemins des actifs de marque doivent y figurer ; une marque modifiée n'apparaît qu'après la mise à jour du service worker. |
| Image de production | `docker/prod/Dockerfile` : code et `public/build` figés dans l'image | Les actifs du profil doivent entrer dans l'image à la construction (ou être montés), et non être modifiés en place. |
| Page d'accueil `/` | Page Inertia, **précachée** par le service worker | Une tablette qui a déjà visité le site peut afficher l'ancienne marque jusqu'à resynchronisation du cache. |

**Recommandation [H] / [D-05]** : une image construite **par institution**, avec le profil choisi en argument de construction **et** relu par le serveur à l'exécution (le serveur refuse de démarrer si les deux divergent). Cela s'accorde avec V-ID-09 (« une identité par installation ») et évite toute apparence de multi-tenance.

### 2.4. Ce qui ne change jamais (V-ID-05, V-ID-12) [C]

La marque visible change ; les identifiants techniques restent. En particulier :

- le nom du dépôt `ikoma-core`, les routes (`/login`, `/administration/...`, `/poste/api/...`), les permissions (`badges.gerer`, etc.), les rôles, les tables, les migrations, les classes PHP, les espaces de noms ;
- les jetons CSS `--color-ikoma-*` et les classes utilitaires `*-ikoma-*` (82 emplois recensés dans `resources/js`) : **leurs noms restent**, seules leurs **valeurs** sont fournies par le profil ;
- le **préfixe du QR `IKOMA1:`** (`app/Services/QrSvg.php`, constante `PREFIX`) et sa reconnaissance par le contrôle (`SiteControl.php:59`, `Desk.php:192`, `resources/js/poste/local/apply.ts:50`, `site.ts:8`). Des badges imprimés le portent ; le changer exigerait un contrat de migration (BADGE-001, « Compatibilité »). Ce préfixe n'est **pas visible** pour l'utilisateur, mais il apparaîtrait dans un lecteur de QR tiers. **[D-19]** : le client doit être informé, et peut l'accepter ou demander un contrat de double préfixe.

## 3. Inventaire exhaustif des surfaces visibles

Légende des colonnes : **Actuel** = ce que l'utilisateur voit et où cela est fixé ; **Adaptation** = ce que le profil doit fournir ; **Cl.** = classement E/C/P.

### 3.1. Pages publiques

| # | Surface | Actuel [C] | Adaptation | Cl. |
|---|---|---|---|---|
| S01 | Page d'accueil `/` | `resources/js/pages/Home.vue` (185 lignes). Titre `IKOMA ACCESS — Contrôlez les accès. Maîtrisez les visites.` (l.24) ; logo et libellé (l.32-34, 76, 179) ; lien `mailto:ikomagroup2@gmail.com` (l.61, 172) ; « IKOMA ACCESS par IKOMA GROUP » (l.171) ; « Une solution IKOMA GROUP · Cocody Angré Soleil 3, Abidjan » (l.180) ; aperçu illustratif « des trois espaces IKOMA ACCESS » (l.72). **Page commerciale** : argumentaire, couverture fonctionnelle. | **Contenu à remplacer, pas seulement la marque** : une page d'accueil institutionnelle sobre, dont le texte est fourni par le client **[D-04]**. Aucune coordonnée commerciale IKOMA GROUP ne doit subsister. | P |
| S02 | Connexion `/login` | `Auth/Login.vue:17` : « Bienvenue sur IKOMA ACCESS » ; `AuthLayout.vue` : titre `… — IKOMA ACCESS` (l.7), `aria-label` (l.12), libellé (l.14), pied « IKOMA GROUP · Sécuriser, Innover et Servir » (l.35). | Texte d'accueil, logo, titre et pied fournis par le profil. Champs, routes (`POST /login`) et comportements inchangés. | P |
| S03 | Double authentification, confirmation de mot de passe, sécurité du compte | `Auth/Challenge.vue`, `ConfirmPassword.vue`, `Security.vue` (via `AuthLayout`/`BaseLayout`/`AdminLayout`/`AgentLayout`) | Hérité du gabarit : couvert dès que S02 l'est. Vérifier titres et libellés par test. | C |
| S04 | Page d'erreur | `pages/Error.vue` (gabarit de base) | Hérité ; message générique sans marque à vérifier. | C |
| S05 | Assistant d'installation | `pages/Installation.vue:13` : « Installation d'IKOMA Accueil » (ancien nom du produit) | Usage opérateur, une seule fois. Libellé à neutraliser ou à rattacher au profil. Visible avant que le profil soit choisi : à traiter avec soin. | P |
| S06 | Racine HTML : titre, description, manifeste, thème | `resources/views/app.blade.php:7-8` : `<title>IKOMA ACCESS</title>` et description commerciale ; lien du manifeste (l.10). **Toute page sans `<Head>` propre hérite de ce titre.** | Le gabarit lit le profil (titre par défaut, description, couleur de thème, lien du manifeste, favicon). | P |

### 3.2. Espaces authentifiés

| # | Surface | Actuel [C] | Adaptation | Cl. |
|---|---|---|---|---|
| S10 | **Espace Administrateur** | `layouts/AdminLayout.vue:37` : pastille + « **IKOMA** ACCESS » (classe `admin-brand`) ; 17 pages l'utilisent. Un test vérifie le texte (`tests/front/admin-events.test.ts:48`). | Composant de marque unique fourni par le profil ; le test doit être paramétré par profil, non supprimé. | P |
| S11 | **Espace Superviseur (pilotage)** | `layouts/PilotageLayout.vue:40` : « **IKOMA** ACCESS » (classe `pilotage-brand`) ; 7 pages. | Idem. | P |
| S12 | **Espace Agent d'accueil / contrôle** | `layouts/AgentLayout.vue:22` : « **IKOMA** ACCESS » (classe `agent-brand`) | Idem. | P |
| S13 | Espaces Hôte et Encadreur, « Mon espace » | Gabarit `BaseLayout.vue` : en-tête (l.3) et pied « **IKOMA GROUP** · Vérification rapide des visiteurs » (l.5) ; pages `Hote/*`, `Space.vue`, `MonGroupe.vue`. | Hors des trois espaces nommés par la mission, mais **visibles** : même composant de marque. | P |
| S14 | Écran de verrouillage et d'enrôlement de la tablette | `components/poste/PosteGate.vue:128, 148` : « IKOMA **Accueil** » (ancienne dénomination) | À aligner sur le profil. Écran vu par l'agent à chaque ouverture. | P |
| S15 | Navigation, menus, fils d'Ariane | Libellés fonctionnels (Badges, Postes, Journal…) : **sans marque** [C]. | Aucune adaptation de marque ; revue de vocabulaire éventuelle (ton institutionnel) à part. | E |
| S16 | Écrans réservés à la démonstration | `pages/Admin/DemoLab.vue:112, 135` (« Remettre IKOMA ACCESS à zéro », confirmation `REMETTRE A ZERO IKOMA`) ; bandeau `APP_ENV=demo` | Disponibles seulement en environnements `demo`, `local`, `testing` (`HandleInertiaRequests.php`). **Hors périmètre** d'une installation de production AILCT. Ne pas modifier la chaîne de confirmation (validée côté serveur, `DemoLabController.php:62`). | E |
| S17 | Écran « Modules » | `pages/Admin/Modules.vue:28` : « … chaque licence IKOMA ACCESS standard de 12 mois » | Texte **contractuel** décrivant la licence du produit, visible de l'administrateur. À arbitrer : conserver tel quel (information sur la licence) ou le reformuler **[D-03]**. | P |

### 3.3. Titres, métadonnées, icônes

| # | Surface | Actuel [C] | Adaptation | Cl. |
|---|---|---|---|---|
| S20 | Titre de l'onglet et de l'historique | `app.blade.php:7` (défaut), `Home.vue:24`, `AuthLayout.vue:7` (`title + ' — IKOMA ACCESS'`) ; les autres pages héritent du défaut. | Suffixe de titre unique tiré du profil. | P |
| S21 | Favicon | `public/favicon.ico` et `public/icon.svg` (icône « IKOMA ACCESS ») | Remplacer par l'icône du profil, à partir du logo original. | P |
| S22 | Manifeste PWA et icônes d'installation | `vite.config.ts:27` : `name` et `short_name` « IKOMA ACCESS », couleur de thème `#0B3C6B` | Voir 2.3. | P |
| S23 | Actifs de marque | `public/brand/ikoma-access-{icon,logo,logo-slogan}.svg` ; chemins écrits en dur dans `AdminLayout`, `PilotageLayout`, `AgentLayout`, `Home.vue`, `card.blade.php:3` et la liste de pré-cache. | Un seul répertoire d'actifs de profil ; chemins fournis par le profil. | P |
| S24 | Aperçu à l'installation sur tablette/mobile | Icône du manifeste ; couleurs `theme_color`/`background_color` | Tirés du profil. Vérifier l'icône « maskable » (marge de sécurité). | P |

### 3.4. Documents, impressions, exports

| # | Surface | Actuel [C] | Adaptation | Cl. |
|---|---|---|---|---|
| S30 | **Badges** (aperçu et impression) | `resources/views/badges/card.blade.php:3` (logo et `alt="IKOMA ACCESS"`) ; `artwork-print.blade.php:5` (`<title>Impression des badges — IKOMA ACCESS</title>`) ; `BadgeArtwork::defaults()` (`title = 'IKOMA ACCESS'`, l.22) ; `BadgeWorkshop.vue:11, 105, 149, 178` ; feuille de style `public/badges/cards.css`. | Logo et titre par défaut tirés du profil. Voir **A02-BADGES-INSTITUTIONNELS.md** pour la refonte des modèles. | P |
| S31 | Étiquettes de lot (A4) | `resources/views/badges/labels.blade.php` : aucun libellé de marque [C] | Aucune adaptation de marque ; en-tête institutionnel facultatif. | E |
| S32 | Liste d'évacuation imprimée | `pages/Pilotage/Evacuation.vue:79, 118` : `window.print()` ; **aucun en-tête d'institution** sur la feuille imprimée [C] | Ajouter l'identité et la date (et, selon la classification du client, une mention de confidentialité **[D-20]**) à l'impression. | P |
| S33 | Export du journal d'audit | `app/Services/JournalExport.php:36` : fichier `journal-ikoma.csv` | Préfixe de nom de fichier tiré du profil. En-têtes de colonnes à vérifier. | P |
| S34 | Modèle de fichier d'import | `ListImportsController.php:68` : en-têtes de colonnes ; nom de fichier de téléchargement | Vérifier le nom du fichier téléchargé ; aucune marque dans les en-têtes. | C |
| S35 | Notifications de l'hôte | `docs/NOTIFICATIONS.md` : messages **génériques**, sans marque ni donnée personnelle [C] ; notifications poussées du navigateur : **non implémentées** | Aucune adaptation de marque aujourd'hui. Si des notifications poussées sont ajoutées un jour, leur titre devra venir du profil. | E |
| S36 | Messages d'erreur et textes de validation | `lang/fr.json`, `lang/fr/*.php` : aucune occurrence de marque [C] | Aucune. | E |
| S37 | Courriels | Aucun envoi de courriel d'application détecté dans `app/` [C] | Aucune. À reconfirmer au lot d'implémentation. | E |

### 3.5. Surfaces hors de l'utilisateur final (listées pour exhaustivité)

Scripts d'exploitation (`deploy/prod/*.sh`, `scripts/*`), documentation technique, messages des journaux serveur, noms de conteneurs et de volumes Docker, variables `IKOMA_*`. Ces éléments sont **techniques** : ils ne sont pas « visibles » au sens de V-ID-05 et **ne doivent pas être renommés** (V-ID-12).

## 4. Éléments codés en dur — synthèse [C]

| Catégorie | Constat | Conséquence pour la personnalisation |
|---|---|---|
| Libellés | 14 fichiers de `resources/js` contiennent « IKOMA » ou « Ikoma » (dont 2 techniques, `poste/local/apply.ts` et `site.ts`, pour le préfixe du QR) ; 3 vues Blade (`app`, `card`, `artwork-print`) ; `vite.config.ts` ; `BadgeArtwork.php` ; `JournalExport.php`. Une partie des occurrences est **technique** (préfixe QR, chaîne de confirmation de la démonstration) et ne doit pas changer. | Un remplacement global serait **dangereux** : il faut classer chaque occurrence (voir section 3). |
| Couleurs | Jetons `--color-ikoma-{blue,navy,orange,success,danger,gold}` (`resources/css/app.css:6-11`) **et**, dans `resources/js`, 49 valeurs hexadécimales de marque écrites directement dans des composants (`#F75C03` ×21, `#0B3C6B` ×17, `#0066B3` ×11), sans compter une quinzaine de teintes secondaires (`#506D94`, `#617A97`, `#DCE9F8`…) ; en tout 82 occurrences de ces trois couleurs dans `resources`, `public/badges` et les vues. `public/badges/cards.css` en code aussi `#0066B3`, `#F75C03` et `#0B3C6B`. | Les jetons se prêtent à une surcharge par le profil (variables CSS) ; **les valeurs en dur contournent le profil** et doivent être converties en jetons. |
| Logo | Chemin `/brand/ikoma-access-*.svg` écrit dans 6 composants/vues et dans le pré-cache du service worker. | Un seul point de résolution. |
| Contact et adresse | `mailto:ikomagroup2@gmail.com` (2 occurrences), « Cocody Angré Soleil 3, Abidjan » (Home.vue). | À retirer de la page d'accueil institutionnelle. |
| Manifeste | `name`, `short_name`, couleurs : compilés. | Voir 2.3. |
| Préfixe du QR | `IKOMA1:` | **Ne pas modifier** [D-19]. |
| Noms de fichiers exportés | `journal-ikoma.csv` | Paramétrer. |

## 5. Composants réutilisables : existant et à créer

| Composant | État | Rôle proposé |
|---|---|---|
| `AuthLayout`, `AdminLayout`, `PilotageLayout`, `AgentLayout`, `BaseLayout` | **Existent**, chacun avec **son propre bloc de marque** dupliqué (`admin-brand`, `pilotage-brand`, `agent-brand`, en-tête de `BaseLayout`) | Point d'entrée naturel : remplacer les cinq blocs par **un seul composant de marque** alimenté par le profil. |
| `app.blade.php` | **Existe** | Lit le profil pour le titre, la description, la couleur de thème, le lien du manifeste, le favicon. |
| Composant `BrandMark` (logo + nom, variantes claire/sombre/compacte) | **À créer (P)** | Utilisé par les cinq gabarits, la page d'accueil, la page de connexion, l'écran de la tablette. |
| Composable `useInstitution()` | **À créer (P)** | Donne aux composants le nom, la couleur, les textes ; **n'expose aucune permission**. |
| Service PHP de résolution du profil | **À créer (P)** | Lecture, validation, repli, mise à disposition aux vues, à Inertia et aux exports. |
| Route du manifeste | **À créer (P)** si le choix « manifeste servi par Laravel » est retenu | Évite de reconstruire l'interface pour changer un nom ou une icône. |
| Réglages d'administration | `settings` (clé/valeur) et `config/ikoma.php` **existent** | Servent à **sélectionner** le profil, pas à le décrire : la description reste dans un fichier versionné, relu en revue Git (V-ID-08 : configuration explicite). |

## 6. Charte graphique de travail et accessibilité

La palette de V-ID-06 est **provisoire** [V]. Les rapports de contraste ci-dessous sont **calculés** (formule WCAG 2.1) ; ils ne dépendent d'aucune hypothèse de dessin.

| Couple (texte sur fond) | Contraste | Lecture |
|---|---:|---|
| Marine `#0C2D66` sur blanc | 13,27 : 1 | Excellent (texte normal : seuil AA 4,5). |
| Marine sur gris `#E9EDF3` | 11,29 : 1 | Excellent. |
| Marine sur or `#D6B46B` | 6,70 : 1 | Bon : **le texte marine sur fond or est autorisé**. |
| Or `#D6B46B` sur blanc | **1,98 : 1** | **Insuffisant** : l'or ne doit **jamais** servir de couleur de texte sur fond blanc. |
| Or sur gris `#E9EDF3` | **1,69 : 1** | Insuffisant, mêmes règles. |
| Blanc sur gris | 1,17 : 1 | Fonds seulement. |

Règles proposées **[H]** :

1. L'**or** est une couleur d'**accent graphique** (filets, bandeaux, médaillons) ; tout texte posé dessus est en marine. Jamais de texte or sur blanc ou gris.
2. Les **boutons principaux** et textes de lien utilisent le marine ; l'accent or sert au focus et aux séparateurs (le contour de focus du produit est actuellement bleu `#0F5AA6` : à remplacer par une teinte du profil de contraste ≥ 3 : 1 sur chaque fond).
3. **Zones tactiles** : tablette et mobile conservent les tailles minimales actuelles (`min-h-14`, soit 56 px ; ne pas les réduire).
4. **États** (succès, danger, avertissement) : conservés tels quels ; ils portent le sens opérationnel (un refus doit rester rouge) et ne sont pas des couleurs de marque.
5. **Impression** : la version monochrome du logo est nécessaire (évacuation en noir et blanc, étiquettes A4).
6. **Polices** : aujourd'hui des polices système (Arial/Georgia/`system-ui`). La charte AILCT ne précise pas de police : **[D-02]**. Ne pas embarquer de police sans licence vérifiée.
7. **Langue** : français. L'AILCT étant « internationale », une version anglaise est probable **[H]** ; elle n'est pas validée **[D-17]** ; la structure de profil réserve des champs de texte par langue sans les activer.

## 7. Contenu et ton de la page d'accueil et de la connexion

- La page d'accueil actuelle est un **argumentaire commercial** (fonctionnalités, couverture par modules, appel à contact). Elle ne convient pas à une institution : elle doit être remplacée par une page **sobre** (identité, objet de la plateforme, accès à la connexion) dont le **texte est fourni et validé par le client** **[D-04]**. Aucun texte ne doit être inventé.
- La page de connexion garde exactement ses champs, ses messages d'erreur et ses limites de sécurité (invariants de `docs/refonte-brand/README.md`) ; seuls l'identité et le texte d'accueil changent.
- **Mention du fournisseur technique** : V-ID-05 interdit tout libellé « IKOMA ACCESS » visible. Une mention discrète « technologie » ou une mention légale peut être exigée par la licence ou par la loi. Ce point n'est pas tranché : **[D-03]**.

## 8. Risques et dépendances

| # | Risque / dépendance | Gravité | Parade proposée |
|---|---|---|---|
| R1 | **Droits du logo** non vérifiés (V-ID-03) ; fichier source absent du dépôt. | Bloquant pour les visuels | Ne rien intégrer avant réception du fichier source vectoriel et de l'attestation de droits **[D-01]** ; développer le socle avec des actifs de test non officiels, clairement marqués. |
| R2 | **Fuite de marque** : un titre, un `alt`, un `aria-label`, un nom de fichier, le manifeste ou un PDF qui garde « IKOMA ». | Élevée (V-ID-05) | Test automatique d'**absence de marque** sur toutes les surfaces du tableau 3 (section 9) ; revue manuelle des captures. |
| R3 | **Cache du service worker** : l'ancienne page d'accueil reste affichée sur les tablettes déjà enrôlées. | Moyenne | Réviser l'empreinte de pré-cache ; documenter la procédure de mise à jour des tablettes ; test e2e « le build suivant remplace la page » (`tests/e2e/update.spec.ts` existe). |
| R4 | **Manifeste compilé** : un changement de nom exigerait une reconstruction de l'interface. | Moyenne | Décision [D-05] ; route de manifeste servie par le serveur. |
| R5 | **Couleurs en dur** (82 occurrences des trois couleurs de marque) : un composant oublié garde l'ancienne teinte, d'où un rendu bicolore. | Moyenne | Convertir en jetons avant d'appliquer le profil ; contrôle par recherche automatique des valeurs hexadécimales dans les composants. |
| R6 | **Tests existants** qui affirment la marque IKOMA (`tests/front/admin-events.test.ts:48`, `tests/e2e/brand-landing.spec.ts`). | Faible | Paramétrer par profil ; ne pas affaiblir. Le profil neutre garde les assertions actuelles. |
| R7 | **Régression de l'installation non-AILCT** (V-ID-11). | Élevée | Le profil neutre reproduit le rendu actuel ; comparaison de captures avant/après ; tests e2e actuels inchangés. |
| R8 | **Multi-tenance involontaire** (V-ID-09) : un sélecteur de profil modifiable à chaud par l'administrateur donnerait l'impression d'un produit multi-clients. | Moyenne | Profil choisi au déploiement, non modifiable depuis l'interface. |
| R9 | **Palette provisoire** : l'or échoue au contraste sur blanc ; une charte officielle différente pourrait invalider les choix. | Moyenne | Variables de couleur (pas de valeurs en dur) ; validation graphique avant publication **[D-02]**. |
| R10 | **Démonstration** : un profil AILCT sur le serveur de démonstration pourrait être pris pour une installation réelle. | Faible | Le bandeau `APP_ENV=demo` s'affiche déjà ; le profil de démonstration reste le profil neutre. |

## 9. Compatibilité avec les neuf modules fondateurs (ACCESS-FOUNDATION-001)

Aucun module n'est retiré, réduit ni renommé (V-ID-10). Impact **de l'identité seule** (l'impact des badges est dans l'autre document) :

| Module | Impact de l'identité | Risque | Contrôle |
|---|---|---|---|
| F01 Administration et configuration | Gabarit `AdminLayout` ; nouvelle configuration du profil (lecture seule dans l'interface) ; écran « Modules » (S17). | Faible | Aucune permission nouvelle ; l'écran « Modules » reste l'unique commutateur de F06. |
| F02 Personnes et listes | Aucun libellé de marque ; modèle d'import inchangé. | Nul | — |
| F03 Badges | Logo et titre des badges (S30). Le fond du sujet est dans le document des badges. | Moyen | Voir A02-BADGES-INSTITUTIONNELS.md. |
| F04 Poste d'accueil | Écran de verrouillage de la tablette (S14), icône d'installation, pré-cache. | Moyen (hors ligne) | Les tablettes hors ligne doivent continuer à ouvrir la coquille après changement de marque : test e2e hors ligne existant à rejouer. |
| F05 Contrôle des accès et sorties | Écran de contrôle (`AgentLayout`). **QR et préfixe inchangés.** | Faible | Test de contrôle d'un badge existant avant/après. |
| F06 Groupes et cars | Aucun libellé de marque. Reste activable/désactivable (V-ID-10). | Nul | Test F06 inactif/actif sous profil AILCT. |
| F07 Pilotage et supervision | `PilotageLayout`, impression d'évacuation (S32). | Faible | Test d'impression avec en-tête d'institution. |
| F08 Espace hôte | `BaseLayout` (S13). | Faible | Captures de l'espace hôte. |
| F09 Audit, conservation, traçabilité | Nom du fichier d'export (S33). Les **journaux existants ne sont pas réécrits** : ils contiennent des identifiants techniques, pas de marque visible. | Faible | Test de nom d'export ; vérifier qu'aucune migration ne touche aux journaux. |

## 10. Tests nécessaires (à implémenter aux lots suivants)

1. **Anti-fuite de marque** : sous le profil AILCT, produire chaque surface du tableau 3 (HTML rendu, titres, métadonnées, manifeste, `alt`/`aria-label`, CSV, en-têtes de réponse de téléchargement, PDF de recette) et vérifier l'**absence** des chaînes `IKOMA` et `Ikoma` hors liste d'exceptions techniques explicitement déclarée (préfixe du QR, constantes de démonstration).
2. **Repli** : profil absent, invalide, logo manquant, couleur mal formée → profil neutre, sans erreur 500.
3. **Non-régression non-AILCT** : suite complète actuelle (Pest, Vitest, e2e) inchangée sous le profil neutre ; captures identiques.
4. **Aucun effet métier** : test d'architecture interdisant que le service de profil soit référencé par les services métier, les politiques d'accès ou la synchronisation.
5. **Titres et métadonnées** : titre par défaut, titre de page, description, manifeste, favicon.
6. **Exports et impressions** : nom du fichier du journal, en-tête d'évacuation, badge (aperçu et impression).
7. **Accessibilité** : contrastes calculés (section 6) par test ; navigation au clavier ; cibles tactiles ≥ 56 px ; captures 1440 × 900, 1024 × 768, 768 × 1024, 390 × 844 (formats déjà utilisés par `brand-landing.spec.ts`).
8. **Hors ligne et PWA** : coquille de la tablette rouverte sans réseau avec le nouveau profil ; mise à jour du service worker.
9. **Sécurité** : aucun nouvel en-tête de sécurité affaibli (politique CSP `style-src` stricte : tout style dérivé du profil passe par fichiers ou jetons, **pas** par des styles en ligne) ; ZAP baseline inchangé.

## 11. Hypothèses et décisions à arbitrer (extrait pour ce document)

Registre complet : `A02-PLAN-IMPLEMENTATION.md`, section 8.

- **[D-01]** Source et droits du logo (fichier vectoriel original, attestation).
- **[D-02]** Palette et polices officielles ; couleur de focus ; traitement de l'or.
- **[D-03]** Mention du fournisseur technique et reformulation de l'écran « Modules ».
- **[D-04]** Texte et structure de la page d'accueil institutionnelle.
- **[D-05]** Sélection du profil : image par institution (recommandé) ou à l'exécution.
- **[D-17]** Langues (français seul ou bilingue).
- **[D-19]** Préfixe `IKOMA1:` du QR : le conserver et en informer le client.
- **[D-20]** Mention de confidentialité sur les documents imprimés.
