# PLAN DE CONCEPTION — Système de vérification rapide des visiteurs

**Nom de travail (à valider) :** IKOMA Accueil
**Dépôt suggéré :** `ikoma-accueil` (dépôt séparé, hors des dépôts GAMAD)
**Version du plan :** 0.3 — pile technique validée par le dirigeant le 27 octobre 2026 ; le reste demeure un projet de travail
**Date :** 27 octobre 2026
**Base de référence :** « Système de vérification rapide des visiteurs – Mode de fonctionnement détaillé », v1.0 du 27 octobre 2026
**Prototype de démonstration :** `docs/prototype/index.html` (maquette interactive, données fictives)

> Statut : ce plan est une proposition de l'architecte. Rien n'y est décidé tant que le dirigeant ne l'a pas déclaré validé. Les points à trancher sont listés en section 12.

---

## 1. Positionnement

- Projet d'un partenaire (IKOMA GROUP), distinct de GAMAD. Il s'appuie sur PHP, Laravel et PostgreSQL (socle de la pile GAMAD) ; la couche d'écrans (Vue + Inertia) est propre à ce projet, pour tenir le mode hors ligne. Ce n'est pas un produit GAMAD : le dépôt, le nom et la documentation restent séparés.
- Public final : un client institutionnel (site sensible). Priorités : **fiabilité, rapidité, traçabilité, sécurité**, puis esthétique.
- Utilisateurs de terrain peu techniques et souvent pressés. L'interface doit se comprendre sans formation (30 minutes maximum de prise en main).

## 2. Principes de conception (non négociables)

1. **Un écran, une action.** Jamais plus d'une décision principale par écran.
2. **Gros et lisible.** Cibles tactiles ≥ 56 px, texte ≥ 18 px sur tablette, contraste AA minimum.
3. **Couleur + icône + mot.** Jamais la couleur seule (vert/rouge doublés d'une icône et d'un texte).
4. **Le système guide.** Le bouton suivant est toujours évident ; les actions interdites sont désactivées avec la raison affichée.
5. **Zéro saisie inutile.** Recherche tolérante (accents, fautes), scan plutôt que frappe.
6. **Tout est tracé.** Chaque action est horodatée, attribuée, non modifiable.
7. **Le poste ne s'arrête jamais.** Mode hors ligne intégré dès la conception, pas ajouté après.
8. **Réglages par l'interface.** Aucune configuration dans les fichiers techniques pour l'exploitant : tout passe par l'espace Administration.

## 3. Périmètre par versions

### MVP (pilote présentable au client)
- Import d'une liste nominative (tableur), détection des doublons et homonymes
- Catégories, zones autorisées, événements
- Gestion des badges (lots, QR unique + code lisible, statuts)
- Poste d'accueil tablette : parcours en 7 étapes, photo, contrôle d'identité, validation/refus, association de badge
- Visiteur non listé avec validation de l'hôte
- Contrôle de badge sur site (scan), sortie, compteur de présents
- Application de pilotage mobile (superviseur) : tableau de bord, alertes, liste des présents, annulation de badge
- Comptes, rôles, authentification renforcée, journal d'audit
- Mode hors ligne du poste d'accueil avec synchronisation

### V1 complète
- Groupes de stagiaires par car (appel nominal, lots de badges réservés, embarquement, contrôle collectif à l'arrivée)
- Espace hôte / organisateur (mise à jour de la liste, notifications d'arrivée)
- Liste d'évacuation imprimable
- Impression d'étiquettes pour les imprévus
- Export des journaux, purge automatique des données en fin de période

### Plus tard (non prévu, jamais sans accord préalable du client)
- Biométrie (explicitement exclue de cette version)
- Application native mobile, intégration contrôle d'accès physique (portiques)

## 4. Pile technique (validée : Laravel + Inertia + Vue 3 + PostgreSQL)

| Couche | Choix | Raison |
|---|---|---|
| Langage / framework | **PHP (≥ 8.3) + Laravel (dernière version stable, à vérifier)** | Pile maîtrisée, écosystème sécurité mature |
| Interface | **Vue 3 + Inertia.js** (TypeScript) + Tailwind CSS + Vite | Une seule technologie pour tous les écrans : accueil, superviseur, hôte, administration |
| Base de données | **PostgreSQL** (extensions `pg_trgm`, `unaccent`) | Recherche tolérante aux fautes et accents |
| Files / tâches | Laravel Queue (driver base de données au départ) | Notifications, imports, purges |
| Temps réel | Phase 1 : rafraîchissement automatique (Inertia, 5 s). Phase 2 : Laravel Reverb | Simple et robuste d'abord |
| Authentification | Laravel Fortify (mot de passe + 2FA TOTP), droits via spatie/laravel-permission | Authentification renforcée, droits par rôle |
| Hors ligne | **PWA** (vite-plugin-pwa) + IndexedDB (Dexie) + scan caméra (BarcodeDetector, repli ZXing) | Voir 4.1 |
| Tests | Pest (PHP), Vitest (Vue), Playwright (parcours complets, dont coupure réseau) | Recette automatisée |
| Qualité | Laravel Pint, Larastan, ESLint, vue-tsc, CI GitHub Actions | Discipline et reproductibilité |
| Déploiement | Docker Compose (Nginx, PHP-FPM, PostgreSQL) | Même paquet sur un serveur local du client ou un hébergement sécurisé |

### 4.1 Règle d'or du mode hors ligne

Inertia et Vue ne suffisent pas à eux seuls : une navigation Inertia demande une réponse du serveur. Pour que le poste d'accueil tienne sans internet :

- Le poste d'accueil est **une seule page Inertia** qui charge une application Vue autonome. Les 7 étapes se succèdent par état interne (aucune visite Inertia, aucun appel serveur bloquant).
- Au démarrage en ligne : la liste, les catégories, les zones et le lot de badges du poste sont copiés dans IndexedDB (Dexie). Le service worker met l'application en cache.
- Recherche, photo et scan fonctionnent localement (recherche tolérante aux accents et aux fautes).
- Chaque action crée une opération horodatée avec identifiant unique dans une file locale, envoyée à l'API Laravel dès le retour du réseau (traitement idempotent).
- Les écrans superviseur, hôte et administration ont besoin du réseau par nature : ce sont de simples pages Inertia, avec un message clair en cas de coupure (et, pour le superviseur, affichage de la dernière situation connue).
- Des tests Playwright de coupure réseau sont obligatoires (lot L6).

### 4.2 Règle anti-conflit hors ligne

Deux tablettes hors ligne pourraient attribuer le même badge. Parade : **chaque poste d'accueil reçoit son propre lot de badges réservé**. Si un conflit survient malgré tout, il est signalé au superviseur comme alerte à traiter, jamais écrasé silencieusement.

## 5. Modules applicatifs

1. **Administration** : événements, sites, zones, catégories, comptes, rôles, appareils, réglages, journaux
2. **Listes** : import tableur, contrôle qualité, fiches visiteurs
3. **Badges** : génération de lots, QR, codes lisibles, statuts, annulation
4. **Poste d'accueil** (PWA) : recherche, fiche, identité + photo, décision, scan, remise, entrée
5. **Contrôle & sortie** : scan sur site, sortie, compteur
6. **Groupes / cars** : appel nominal, progression, embarquement, arrivée
7. **Pilotage** : tableau de bord, alertes, présents, évacuation, journal
8. **Hôte** : validation des non listés, notifications
9. **Audit & conformité** : journal chaîné, purge, export

## 6. Modèle de données (tables principales)

| Table | Rôle | Champs clés |
|---|---|---|
| `users` | Comptes nominatifs | nom, rôle, 2FA, statut |
| `events` | Événement / cérémonie | nom, dates, site, statut |
| `zones` | Zones et salles | nom, événement |
| `categories` | VIP, Visiteur, Prestataire, Stagiaire | nom, couleur, circuit prioritaire |
| `category_zone` | Zones autorisées par catégorie | catégorie, zone |
| `visitors` | Fiches visiteurs | nom complet, organisation, hôte, catégorie, validité, groupe, source (listé / non listé), statut |
| `hosts` | Hôtes / services | nom, utilisateur lié, service |
| `badges` | Badges physiques | code lisible, QR (jeton unique), statut, lot, poste réservé |
| `badge_batches` | Lots imprimés | quantité, marge, date |
| `badge_assignments` | Historique des associations | badge, visiteur, agent, appareil, date, annulée |
| `identity_checks` | Contrôle d'identité | visiteur, agent, date (case cochée) |
| `photos` | Photos prises à l'accueil | visiteur, fichier chiffré, date |
| `access_events` | Entrées / sorties / contrôles | visiteur, badge, type, point de contrôle, date |
| `refusals` | Refus d'accès | visiteur, motif, agent, décision du superviseur |
| `groups` | Cars / groupes | numéro, encadreur, lot de badges |
| `group_members` | Membres d'un groupe | groupe, visiteur, embarqué, arrivé |
| `devices` | Tablettes et smartphones enrôlés | nom, jeton, poste, dernière synchro |
| `sync_operations` | File des opérations hors ligne | identifiant client, type, résultat, conflit |
| `audit_logs` | Journal non modifiable | acteur, action, objet, date, empreinte chaînée |
| `settings` | Réglages modifiables dans l'interface | clé, valeur |
| `import_batches` | Imports de listes | fichier, résultat, anomalies |

Règles de données :
- Identifiants UUID, générés côté client pour les opérations hors ligne.
- Un badge ne peut avoir qu'**une association active** (contrainte unique partielle PostgreSQL).
- `audit_logs` : insertion seule (le compte applicatif n'a pas le droit de modifier ni supprimer), chaque ligne contient l'empreinte de la précédente.
- Durée de conservation courte et paramétrable ; purge ou archivage sécurisé en fin d'événement.

## 7. Écrans (parcours simples)

### Poste d'accueil (tablette, mode dédié)
1. **Recherche** : un grand champ, résultats immédiats avec catégorie en couleur et icône
2. **Fiche** : nom, organisation, personne reçue, zones ; second critère en cas d'homonyme
3. **Identité + photo** : case et caméra, « Valider » grisé tant que ce n'est pas fait
4. **Décision** : « Valider » (vert) / « Refuser » (rouge, motif obligatoire)
5. **Scan du badge** : caméra, saisie manuelle du code en solution de secours
6. **Remise** : rappel des zones autorisées
7. **Entrée enregistrée** : confirmation, chrono, bouton « Visiteur suivant »
Bandeau permanent : état réseau, nombre d'opérations en attente, poste, agent.

### Superviseur (smartphone)
Tableau de bord (attendus, arrivés, présents, refusés), alertes triées par urgence, liste des présents, suivi des cars, annulation de badge, journal.

### Hôte
Notifications d'arrivée, validation « oui / non » des non listés, mise à jour de sa liste.

### Administration (ordinateur)
Assistant de mise en place guidé (événement → zones → catégories → import → badges → comptes → test), puis menus simples. Chaque réglage a une aide courte en français.

## 8. Design system

- **Identité :** bleu `#0F5AA6`, marine `#0B3C6B`, orange `#F26B21` (couleurs du logo IKOMA). Vert `#1E9E5A` = succès, rouge `#D64545` = refus/alerte, or `#C9962B` = VIP.
- **Typographie :** une police sans empattement lisible (système ou Inter), 3 tailles principales.
- **Composants :** composants Vue maison (boutons larges, cartes, étiquettes de catégorie, barre de progression, bandeaux d'état, tableaux lisibles sur mobile), documentés dans docs/design-system.md.
- **Retours :** animations sobres, vibration/son optionnels à la validation et au refus.
- **Accessibilité :** contraste, focus visible, libellés explicites, pas d'information portée par la couleur seule.
- **Langue :** français uniquement dans la V1 (structure prête pour d'autres langues).

## 9. Sécurité et conformité

- Comptes nominatifs, mot de passe robuste, **2FA obligatoire** pour superviseur et administrateur, déconnexion automatique après inactivité
- Enrôlement des appareils : une tablette non enrôlée ne peut ni se connecter ni synchroniser
- Tablette verrouillée sur l'application (mode dédié), effacement à distance via la gestion d'appareils du client
- HTTPS partout (obligatoire aussi pour la caméra du navigateur), chiffrement des échanges et des données sensibles (photos, pièces)
- Droits stricts par rôle (moindre pouvoir), contrôle serveur systématique
- Journal d'audit chaîné et non modifiable
- Protection : limitation de débit, validation stricte des entrées, en-têtes de sécurité, sauvegardes chiffrées et testées
- Données personnelles : collecte limitée, information des personnes, conservation courte, purge en fin d'événement ; conformité à la loi n° 2013-450 et à l'ARTCI — **à faire vérifier par un juriste local avant mise en service**
- Aucune biométrie dans cette version
- Test d'intrusion et revue de sécurité avant la mise en service

## 10. Environnements et déploiement

| Environnement | Usage | Données |
|---|---|---|
| Développement | Poste du développeur / exécutant | Fictives |
| Démonstration | Sous-domaine de test (VPS ou cPanel) | **Fictives uniquement** |
| Production | Serveur local du client ou hébergement sécurisé, selon sa décision | Réelles |

Règles :
- **Jamais de données réelles sur un serveur partagé avec d'autres services.** La production se fait sur un serveur dédié, durci, choisi avec le client.
- Livraison en paquet Docker reproductible, avec un **assistant d'installation web** : l'exploitant ne touche pas aux fichiers techniques. Seules les valeurs strictement vitales (clé de l'application, accès base de données) sont générées automatiquement par le script de déploiement ; tout le reste se règle dans l'administration.
- Sauvegarde quotidienne chiffrée, restauration testée avant le jour J.

## 11. Qualité, tests et feuille de route

### Objectifs de performance (à confirmer avec le client)
- Visiteur listé : < 60 s de la recherche à la remise du badge
- Stagiaire en groupe : < 20 s après l'appel nominal
- Contrôle de badge : < 5 s
- Poste d'accueil : disponible pendant tout l'événement, y compris hors ligne

### Lots de réalisation

| Lot | Contenu | Critère d'acceptation |
|---|---|---|
| L0 | Dépôt, règles d'exécution, CI, squelette Laravel/Inertia/Vue/PostgreSQL, base PWA, design system | Installation reproductible, tests verts, page d'accueil stylée |
| L1 | Comptes, rôles, 2FA, journal d'audit chaîné | Connexion par rôle, tentative non autorisée refusée et journalisée |
| L2 | Modèle de données, données fictives, événements, zones, catégories | Migrations propres, jeu de démo chargeable |
| L3 | Import de liste, doublons/homonymes | Import de 1 000 lignes avec rapport d'anomalies lisible |
| L4 | Badges : lots (y compris par catégorie), QR, codes, statuts, étiquettes imprimables | Contrainte « un badge = une personne » prouvée par test |
| L5 | Poste d'accueil en ligne (parcours 7 étapes, non listé, refus) + conception de l'authentification hors ligne | Parcours complet < 60 s au chronomètre en recette |
| L6 | Hors ligne et synchronisation | Coupure réseau pendant 30 entrées, resynchro sans perte ni doublon |
| L7 | Contrôle sur site, sortie, compteur de présents | Contrôle < 5 s, compteur exact |
| L8 | Pilotage superviseur, alertes, évacuation | Tableau de bord à jour en moins de 5 s |
| L9 | Groupes / cars | Car de 50 stagiaires traité avec < 20 s par personne |
| L10 | Espace hôte et notifications | Hôte notifié d'une arrivée, validation d'un non listé |
| L11 | Durcissement applicatif et conformité des données (CSP, 2FA, audit, purge, analyses CI) | Rapport de revue de sécurité sans faille critique |
| L12 | Production : déploiement durci, installation guidée, sauvegardes chiffrées | Restauration testée, installation reproductible |
| L13 | Répétition générale et pilote | Simulation complète d'un événement signée par le dirigeant |

### Règles de validation
Un lot n'est « terminé » que si : tests automatiques verts, critères d'acceptation vérifiés, démonstration filmée ou captures fournies, journal des décisions à jour, validation explicite du dirigeant.

## 12. Décisions à trancher (priorité décroissante)

1. ~~Architecture des écrans~~ **Tranché le 27 octobre 2026 : Laravel + Inertia + Vue 3 + PostgreSQL, poste d'accueil en PWA hors ligne.**
2. **Hébergement de production :** serveur local chez le client ou hébergement sécurisé ? (décision du client, à lui demander tôt)
3. **Règle de contrôle des stagiaires :** pièce d'identité individuelle ou confirmation de l'encadreur ?
4. **Règle de restitution des badges :** réutilisables ou usage unique ?
5. **Traitement des VIP :** niveau de discrétion souhaité par le client
6. **Durée de conservation des données** et responsable de la liste nominative
7. **Nom définitif du produit** et propriété du code (accord avec l'ami / IKOMA GROUP : qui possède, qui exploite)
8. **Matériel :** tablettes, onduleurs, imprimante (choix à faire après la pile logicielle)


---

## 13. Documents associés

- `AGENTS.md` (racine) : règles d'exécution imposées à l'exécutant
- `docs/JOURNAL_DECISIONS.md` : décisions validées et décisions en attente
- `docs/prompts/` : prompts à lancer dans l'ordre (voir `docs/prompts/README.md`)
- `docs/prototype/index.html` : prototype de démonstration (maquette interactive, données fictives)
