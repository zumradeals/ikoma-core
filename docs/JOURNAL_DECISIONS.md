# Journal des décisions

## Décisions validées

| Date | Décision | Raison | Validé par |
|---|---|---|---|
| 2026-10-27 | Pile technique : Laravel + Inertia.js + Vue 3 (TypeScript) + PostgreSQL + Tailwind CSS. Livewire écarté. Poste d'accueil en PWA hors ligne. | Le cahier des charges exige un poste d'accueil fonctionnel sans internet. | Koné Djakaridja (dirigeant) |
| 2026-10-27 | Matrice L1 validée ; ajout de `categories.gerer` au rôle `administrateur` uniquement. | Document de fonctionnement v1.0, section 10 : « gestion des badges, zones et catégories ». | Dirigeant — validation explicite |

## Décisions en attente (voir section 12 du plan)

2. Hébergement de production (serveur local chez le client ou hébergement sécurisé)
3. Règle de contrôle des stagiaires (pièce d'identité ou encadreur)
4. Règle de restitution des badges (réutilisables ou usage unique)
5. Traitement des VIP (niveau de discrétion)
6. Durée de conservation des données et responsable de la liste
7. Nom définitif du produit et propriété du code
8. Matériel (tablettes, onduleurs, imprimante)

## Modèle de ligne

`| date | décision | raison | qui a validé |`

## L1 — décisions techniques du 27 octobre 2026

- Référence L1 et L2 : `docs/reference/Mode_de_fonctionnement_verification_visiteurs.pdf`, v1.0, sections 2 et 10 pour les droits. PDF déplacé depuis docs/, contenu conservé. La matrice est dans `docs/MATRICE_DROITS.md`, validée par le dirigeant le 27 octobre 2026 avec l’ajout de `categories.gerer` pour l’administrateur uniquement.
- Fortify 1.41.0 et spatie/laravel-permission 8.3.2, versions stables résolues avant installation. Pas d’inscription publique, pas de contournement global administrateur.
- Sessions SQL ; inactivité dans settings.session_idle_minutes, valeur initiale 15 minutes, modifiable dans l’écran de sécurité des comptes par l’administrateur.
- Journal : empreinte SHA-256 canonique incluant aussi l’identifiant de ligne, verrou transactionnel PostgreSQL pour sérialiser les ajouts, acteur conservé par UUID, aucun mot de passe ou secret TOTP journalisé. Le compte applicatif ne possède pas les tables et reçoit SELECT/INSERT seulement sur audit_logs. Le compte de migration distinct reste privilégié. Le chaînage détecte une altération isolée ; un administrateur de base capable de réécrire toute la chaîne reste hors de cette garantie, sans ancrage externe.
- Correctif PWA : révision de / calculée automatiquement à partir des sources du build ; aucun contenu authentifié ni écran d’administration mis en cache. Un test reconstruit deux contenus et vérifie la mise à jour puis la réouverture hors ligne.


## L2 — modèle métier et administration de base, 27 octobre 2026

- Départ de main après fusion de L0/L1. Source : `docs/reference/Mode_de_fonctionnement_verification_visiteurs.pdf`, sections 4, 7 et 8 ; schéma documenté dans `docs/schema.md`.
- UUID pour toutes les nouvelles tables, clés composites pour empêcher les références entre événements différents. Tables users/sessions/settings/audit_logs, rôles et permissions, service Audit et privilèges L1 inchangés.
- Statuts badges exacts en enum PHP + CHECK SQL, Clos réservé. Index partiel sur les associations actives (sans restitution et sans annulation). Aucune machine à états ni transition automatique.
- Zones d’un visiteur dérivées uniquement de sa catégorie. Photos : référence seulement. Aucun module d’accueil, scan, import ou groupe opérationnel.
- Normalisation de recherche SQL IMMUTABLE avec dictionnaire unaccent explicitement qualifié ; index GIN trigramme. Dictionnaire considéré stable, REINDEX obligatoire après modification. Recherche limitée à un événement et dix résultats minimaux, sans API exposée.
- Jeu de démonstration transactionnel et idempotent : 300 visiteurs fictifs, 400 badges dans un lot, 60 réservés à un poste fictif ; un car de 40 stagiaires. Les comptes L1 ne sont pas réinitialisés.
- Zones et catégories : audit atomique avec identifiant/événement et liste des champs modifiés, sans copie des valeurs inutiles. Badges en lecture seule, QR jamais exposé.
- Le test historique FoundationTest L1 est conservé à l’identique dans un schéma L1 isolé ; la suite L2 vérifie le schéma complet et les privilèges réels. Le temps de migrate-fresh-seed est mesuré en CI, avec sortie des mots de passe fictifs gardée privée.
- Questions non arbitrées : permission d’administration des événements ; éventuelles exceptions de zones individuelles ; réutilisation/usage unique et Clos ; vocabulaire des statuts visiteurs/événements/imports ; contrôle des stagiaires. Aucun droit ni parcours supplémentaire créé.
