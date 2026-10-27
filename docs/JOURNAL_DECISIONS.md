# Journal des décisions

## Décisions validées

| Date | Décision | Raison | Validé par |
|---|---|---|---|
| 2026-10-27 | Pile technique : Laravel + Inertia.js + Vue 3 (TypeScript) + PostgreSQL + Tailwind CSS. Livewire écarté. Poste d'accueil en PWA hors ligne. | Le cahier des charges exige un poste d'accueil fonctionnel sans internet. | Koné Djakaridja (dirigeant) |

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

- Référence L1 et L2 : `docs/reference/Mode_de_fonctionnement_verification_visiteurs.pdf`, v1.0, sections 2 et 10 pour les droits. PDF déplacé depuis docs/, contenu conservé. La matrice est dans `docs/MATRICE_DROITS.md`, à valider par le dirigeant.
- Fortify 1.41.0 et spatie/laravel-permission 8.3.2, versions stables résolues avant installation. Pas d’inscription publique, pas de contournement global administrateur.
- Sessions SQL ; inactivité dans settings.session_idle_minutes, valeur initiale 15 minutes, modifiable dans l’écran de sécurité des comptes par l’administrateur.
- Journal : empreinte SHA-256 canonique, verrou transactionnel PostgreSQL pour sérialiser les ajouts, acteur conservé par UUID, aucun mot de passe ou secret TOTP journalisé. Le compte applicatif ne possède pas les tables et reçoit SELECT/INSERT seulement sur audit_logs. Le compte de migration distinct reste privilégié. Le chaînage détecte une altération isolée ; un administrateur de base capable de réécrire toute la chaîne reste hors de cette garantie, sans ancrage externe.
- Correctif PWA : révision de / calculée automatiquement à partir des sources du build ; aucun contenu authentifié ni écran d’administration mis en cache. Un test reconstruit deux contenus et vérifie la mise à jour puis la réouverture hors ligne.
