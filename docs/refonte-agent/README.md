# Agent d’accueil V2

Mission et maquette validées par le dirigeant le 29 octobre 2026. Base `main` au commit `1e803d8` (PR #27 fusionnée). Périmètre : espace agent, poste, groupes/cars, contrôle, sécurité du compte. Aucune fusion ni aucun déploiement sans nouvelle validation.

## Séparation de la présentation et du moteur

`AgentLayout` apporte le cadre commun ; `AgentHome` remplace Mon espace uniquement pour les comptes ayant `visiteurs.rechercher`. Les autres rôles conservent leur page existante. `AdminIcon` est réutilisé, sans nouvelle bibliothèque.

`PosteApp`, `ControleApp` et `GroupeApp` utilisent le cadre commun et gardent leurs scripts, dépôts, synchronisation et événements. `StatusBar` conserve tous ses états et actions. `StepTracker`, `StepSearch`, `StepIdentity` et `StepBadge` changent de présentation. Les écrans d’enrôlement et de PIN gardent leurs contrôles et ne montrent aucune liste avant déverrouillage. Les pages Sécurité et Confirmation du mot de passe gardent leurs actions et utilisent le cadre Agent uniquement pour ce rôle.

Aucun changement de route, migration, machine à états, API, service, stockage local, contrat métier, attribution de permission ou durée de verrouillage. Le test de comparaison des scripts confirme que les composants opérationnels conservent leur logique, avec uniquement l’import du cadre dans les trois applications.

## Écarts nécessaires entre illustration et données réelles

La règle métier prime sur l’illustration, conformément à la mission :

- Les étapes réelles sont recherche, fiche, identité/photo, décision, scan/association du badge (5), remise et autorisation d’accès (6), entrée enregistrée (7). Le scan n’est pas déplacé de 5 à 6 ; aucune sélection fictive de type ou lot de badge n’est créée.
- Les portraits des résultats ne sont pas disponibles dans le contrat et les photos sont protégées : initiales à la place des portraits illustratifs. Les catégories et la priorité VIP sont conservées. Aucun compteur ou filtre de catégorie fictif n’est ajouté.
- Aucun bouton « Imprimer un reçu » n’est créé : cette action n’existe pas dans le parcours livré sur main.
- Mon espace lit uniquement les métadonnées locales déjà publiques : existence du coffre, nom du poste sur l’appareil et dernière synchronisation connue. Ni déchiffrement, ni lecture du profil ou de clés. Le libellé ne prétend pas affecter ce poste à l’agent connecté ; l’affectation est vérifiée au déverrouillage existant. Aucune zone d’affectation n’est déduite.

## Photographie de la pièce

Le réglage `identite_piece_active` reste celui de `PostesController`, `Desk` et `ListSnapshot`. Aucun remplacement de cette fonction.

Désactivé : case de contrôle et photo du visiteur, aucun bloc de saisie ou photographie de document. Activé : type et photo de pièce obligatoires, numéro facultatif. Le formulaire garde sa condition d’activation et son événement `done` existants. Le stockage et les protections ne sont pas modifiés.

## Responsive et fonctionnement hors ligne

À partir de 1200 px, menu latéral de 240 px et espace de travail large. Sous 1200 px, menu replié (tablette paysage incluse). Les cartes Mon espace et identité/scan se disposent en deux colonnes quand la largeur le permet ; sur téléphone, pile de cartes et une étape active. Cibles ≥ 56 px ; champs 18 px, contraste, icône + texte pour les états et focus visible.

Navigation entre espaces par liens de document standards, jamais par visite Inertia au milieu du parcours. Hors ligne, aucun changement d’espace n’est déclenché par ces liens : le parcours actif reste utilisable et ses opérations restent enregistrées par le moteur existant. Les coquilles publiques et le coffre sont inchangés. Le réseau ne constitue pas une autorisation : les liens des modules suivent les permissions reçues ; en l’absence de session serveur, aucun droit supplémentaire n’est supposé.

Les noms de contexte sont explicites : Poste pour l’accueil, Groupe pour un car, Point de contrôle pour le contrôle. Aucun groupe n’est présenté comme une affectation de poste.

## Vérification et captures

- `npm run lint`, `npm run typecheck`, `npm test`, `npm run build`.
- Tests existants de l’automate, identité activée/désactivée, exceptions, caméra, QR, groupes, PIN, chiffrement, coupure/reprise et dédoublonnage conservés.
- `tests/front/agent-v2.test.ts` : menu, droits, navigation hors ligne et informations locales sans ouverture du coffre.
- `make e2e` : recette existante inchangée.
- `make e2e-agent` : crée un jeu séparé avec la commande de fixture existante (réservée à APP_ENV=testing), après les autres scénarios, pour ne pas détourner leurs personnes, badges ou codes. Parcours réel et réglage de document modifié par l’interface administrateur, puis restauré dans finally. Aucun réglage serveur métier de production n’est touché.
- `playwright.agent.config.ts` réutilise la configuration Chromium existante. Captures réelles dans `test-results/agent-v2-captures/` aux tailles 1440 × 900, 1024 × 768, 768 × 1024 et 390 × 844 : Mon espace ; étapes 1, 3 (deux états), 5, 6, 7 ; groupes ; contrôle ; sécurité ; verrouillage. Le passage chronométré reste vérifié par la recette habituelle, la série de captures ajoutant volontairement des pauses.

Références immuables : `references/agent-v2.png`, `references/guide-simple.pdf`, `mission.md`. Les captures de recette sont des preuves du rendu ; la maquette reste une référence visuelle.
