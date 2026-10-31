# IKOMA ACCESS — Catalogue des modules fondateurs F01 à F09

**Référence de décision : ACCESS-FOUNDATION-001**  
**Statut : RATIFIÉ par le dirigeant le 31 octobre 2026**  
**Nature : contrat d'architecture et de périmètre fonctionnel de la licence standard**  
**Version : 1.0 — nomenclature fondatrice**  

> Source de vérité sur le périmètre : le présent document, versionné dans Git.
> Les menus, permissions, tables, routes et écrans sont des implémentations de ce
> périmètre ; ils ne peuvent ni le réduire ni le modifier implicitement.

## 1. Décision irrévocable pour une licence en cours

Toute **licence standard IKOMA ACCESS valide pendant 12 mois** donne droit aux neuf
modules F01 à F09, **indissociables et non facturables séparément**. Leur présence
dans le contrat ne dépend pas de la fréquence d'utilisation, du type d'organisation,
du rôle des utilisateurs ou de l'existence d'un matériel particulier.

Aucun acteur ne peut retirer unilatéralement un module fondateur de la
licence d'un client pendant sa validité. Une évolution de cette nomenclature
exige une nouvelle décision explicite, un changement documenté dans Git et
le respect des droits déjà consentis aux clients.

**Exception d'exploitation ratifiée : F06 est inclus de plein droit mais
son utilisation est activable ou désactivable par l'administrateur du client.**
Cette désactivation **n'est ni une révocation du droit contractuel, ni une
suspension de licence, ni une option facturée**. L'administrateur peut demander
sa réactivation tant que la licence standard est valide.

## 2. Nomenclature contractuelle exacte

| ID | Dénomination | Capacités du socle garanti |
|---|---|---|
| **F01** | **Administration et configuration** | Événements, configuration des sites et zones, catégories, comptes, rôles, habilitations, postes et paramètres administratifs. |
| **F02** | **Gestion des personnes et des listes** | Enregistrement individuel, import de listes accepté par l'application, recherche, fiches d'identité, photos autorisées, contrôle de doublons et d'homonymes. |
| **F03** | **Gestion des badges** | Lots, identifiants et QR, réservations, préparation et impression de badges nominatifs ou anonymes, attribution, annulation, restitution et historique. |
| **F04** | **Poste d'accueil** | Enrôlement des postes, parcours opérationnel en sept étapes, identification, contrôle d'identité selon les règles autorisées, visiteurs non listés, remise de badge et admission. |
| **F05** | **Contrôle des accès et sorties** | Vérification des badges, vérification des zones autorisées, constat des entrées et sorties et comptage des personnes présentes. |
| **F06** | **Groupes et cars** | Constitution et suivi des groupes, encadrement, appels, embarquements, arrivées et écarts lors des mouvements collectifs. **Activation d'exploitation par l'administrateur du client.** |
| **F07** | **Pilotage et supervision** | Tableaux de bord, suivi des présences, alertes, anomalies, arbitrages autorisés et listes opérationnelles, notamment d'évacuation. |
| **F08** | **Espace hôte** | Gestion des personnes attendues par un hôte, notifications, validation des visiteurs non listés et suivi de son périmètre. |
| **F09** | **Audit, conservation et traçabilité** | Journal d'audit, consultation et export autorisés, règles de conservation, archivage et opérations encadrées de gestion du cycle de vie des données. |

Les capacités garanties restent soumises aux autorisations individuelles, à
la configuration applicable, aux précautions de confidentialité et aux
contraintes de sécurité. Leur inclusion contractuelle n'accorde **pas**
automatiquement tous les privilèges à chaque compte utilisateur.

Les fonctions communes de sécurité, d'intégrité des données, de permissions,
de synchronisation hors ligne lorsque le parcours la prend en charge, de
sauvegarde et de restauration ne sont **pas** des options commerciales
détachables du socle. Leur **niveau de service et leur responsabilité
d'exploitation** seront précisés dans la convention de licence et d'hébergement.

## 3. Contrat d'activation opérationnelle de F06

Deux états différents ne doivent **jamais être confondus** :

1. **Droit contractuel (entitlement)** : F06 est **toujours inclus** dans
   toute licence standard valide. Il n'existe pas de tarif additionnel,
   d'achat de F06 ou de retrait à la carte de son droit d'utilisation.
2. **État d'exploitation (activation)** : F06 peut être **actif** ou
   **inactif** sur l'installation cliente, suivant la décision d'un
   administrateur dûment autorisé. **L'état initial d'une installation neuve
   est INACTIF, selon la décision complémentaire du dirigeant du 31/10/2026.**
   Une mise à niveau d'une installation possédant déjà des groupes conserve
   F06 actif, afin d'éviter d'interrompre une exploitation en cours.

### 3.1. Contrat d'exploitation de l'activation F06

- Le contrôle est administrable par une interface explicite « Modules »,
  sans accès technique ou modification de configuration serveur.
- Les huit autres modules fondateurs sont visibles comme **« Inclus et
  permanents »** ; on ne leur présente pas de commutateur de désactivation.
- F06 affiche deux informations distinctes : **« Inclus dans la licence »**
  et **« Actif / Inactif pour cette installation »**.
- L'activation et la désactivation de F06 exigent le droit d'administration
  approprié, une confirmation et une trace d'audit (acteur, horodatage,
  ancien état, nouvel état, installation).
- Les vérifications se font **dans le domaine métier côté serveur**, pas
  seulement en cachant le menu ; les parcours hors ligne doivent connaître
  les conséquences d'un changement d'état et se resynchroniser.
- La désactivation **ne détruit ni les groupes, ni les cars, ni leurs
  historiques, ni les badges, ni les autorisations, ni les journaux**.
  Une réactivation doit permettre de retrouver les données conservées,
  sous réserve de la politique de conservation applicable.
- La désactivation doit être **refusée ou différée** si des opérations
  collectives en cours, des admissions dépendantes de F06 ou des files
  locales non synchronisées risquent d'être invalidées. Une simple bascule
  visuelle ne peut pas rendre un mouvement opérationnel incohérent.
- F01–F05 et F07–F09 doivent rester utilisables, même quand F06 est
  inactif. Les alertes liées à d'anciens événements de F06 ne sont pas
  effacées rétroactivement.
- Une réactivation de F06 est **gratuite et réversible**, tant que la
  licence standard reste valide et que les conditions de sécurité le
  permettent.

**Implémentation dans la présente PR :** un réglage persistant côté serveur
`module_f06_active` (installation neuve : `0`), un écran
`Administration → Modules`, une confirmation textuelle, un audit,
les protections des parcours HTTP et métier et une version de
snapshot tablette intégrant l'état de F06. Si des opérations collectives
ont déjà commencé et ne sont pas clôturées, la désactivation est
refusée. Une tablette ayant une ancienne copie non synchronisée
peut produire un conflit : le serveur le conserve et le journalise
au lieu d'accepter une opération pourtant désactivée.

L'activation n'est **pas** un mécanisme de licence payante ou
de permission individuelle. Le passage en production requiert
la vérification CI et une recette avec des appareils réels.

### 3.2. Critères d'acceptation

- Une installation valide comporte F01–F09 comme droits contractuels,
  quel que soit l'état d'activation de F06.
- Un administrateur autorisé peut désactiver/réactiver F06 sans appel au
  fournisseur ni achat complémentaire.
- Un utilisateur non administrateur ne peut pas modifier cet état, y compris
  en appelant directement l'interface métier.
- Lors de la désactivation, les écrans et opérations spécifiques aux groupes
  sont rendus indisponibles de façon contrôlée, sans désactiver l'accueil
  individuel, les badges ou la supervision générale.
- Les données F06 antérieures sont conservées suivant la politique
  applicable et récupérables après réactivation.
- Les événements actifs, les contrôles en cours et les opérations hors
  ligne ne sont jamais abandonnés ni supprimés silencieusement.
- L'audit enregistre chaque changement autorisé et chaque refus critique.

## 4. Ce qui n'est pas un module fondateur

Le laboratoire de démonstration, la génération de données fictives,
la remise à zéro expérimentale et l'assistant d'installation sont des
**outils d'installation, d'essai ou d'exploitation**. Ils ne sont pas
de nouveaux modules métier et ne créent pas de droits commerciaux
supplémentaires.

Aucun module additionnel, gratuit ou payant, n'est identifié, annoncé,
classé ou tarifé aujourd'hui. **Aucun exemple évoqué dans les échanges
ne vaut décision de conception ni promesse commerciale.**

## 5. Règle de changement

- Une fonction relevant déjà d'un module F01–F09 ne devient pas
  automatiquement vendable séparément par changement de menu ou de nom.
- Une évolution de frontière doit préciser : ses responsabilités,
  ses dépendances, son effet sur les contrats actifs et son traitement
  des données.
- Les révisions sont décidées explicitement puis inscrites dans Git.
- Aucun code, changement de configuration, bouton UI ou tarification
  ne peut prendre le pas sur le présent contrat ratifié.

## 6. Articulation avec le contrat commercial

Le [projet de convention de licence standard](LICENCE_STANDARD_12_MOIS_PROJET.md)
décrit comment les neuf droits sont concédés au client pour une période
de 12 mois. Les détails encore indéterminés sont marqués
**« À DÉCIDER »** : ils ne sont pas ajoutés arbitrairement à la
nomenclature ratifiée.

**La licence du code source et la titularité des droits patrimoniaux ne
sont pas déduites de ce catalogue : elles nécessitent une vérification
juridique et documentaire distincte.**
