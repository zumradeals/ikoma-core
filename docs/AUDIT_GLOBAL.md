# Audit global d'IKOMA Accueil — aptitude à un usage par l'armée

Rédigé le 28 octobre 2026 à la demande du dirigeant. Projet de travail : rien ici n'est une décision tant que le dirigeant ne l'a pas validé.

## 1. Verdict en dix lignes

- **Pour une démonstration avec des données fictives : apte.** Le parcours d'accueil, le contrôle, le pilotage, les cars et l'espace hôte fonctionnent et sont testés.
- **Pour recevoir des données réelles d'une armée : pas apte aujourd'hui.** Quatre raisons, par ordre d'importance :
  1. **Aucune garantie d'exploitation** : pas de sauvegarde chiffrée ni de restauration testée, pas de serveur de production durci, pas de test d'intrusion indépendant (lots L12 et L13 non faits, L11 en cours et non fusionné).
  2. **Le contrôle des personnes est celui d'un événement, pas d'un site sensible** : l'identité est une case cochée et une photo, il n'existe ni liste d'interdits, ni habilitation, ni approbation des listes par une autorité.
  3. **Le badge est copiable** (QR statique) et **un badge volé ou annulé présenté à un contrôle ne déclenche aucune alerte** (constaté dans le code).
  4. **Les comptes terrain n'ont pas d'authentification renforcée**, et aucune action critique n'exige deux personnes.
- **Le plan actuel (L0 à L13) ne suffit pas** : le document de fonctionnement v1.0 décrit un système d'accueil d'événement. Un client militaire exigera davantage. Cet audit propose six lots supplémentaires (section 7) et une liste de décisions à obtenir du client (section 8).
- **Cet audit n'est pas un test d'intrusion** et je ne suis pas un auditeur certifié (section 2). Il prépare l'audit indépendant, il ne le remplace pas.

## 2. Méthode et limites

- **Lu** : docs/PLAN_CONCEPTION.md, le document de fonctionnement v1.0 (PDF, sections 4, 7, 9 à 13), docs/schema.md, docs/MATRICE_DROITS.md, les prompts L11 à L13, et, sur la branche `l11-durcissement` de ChatGPT Work (non fusionnée), docs/REVUE_SECURITE.md et docs/EXPLOITATION.md.
- **Vérifié dans le code de `main`** : contrôle sur site (`SiteControl`, `BadgeService::controler`), alertes (`Alerts`, tous les appels `raise`), rôles et 2FA (`User::requiresTwoFactor`), schéma d'identité, et recherche de fonctions absentes (liste d'interdits, véhicules, code de contrainte, habilitation, numéro de pièce : **aucune occurrence** dans `app/`, `resources/js/`, `database/migrations/`).
- **Non vérifié** : ce que contient réellement la branche L11 au-delà de ses documents ; la politique de mot de passe exacte ; le comportement sous charge réelle (L13) ; le réseau, le matériel et le serveur de production (n'existent pas encore).
- **Hors de ma compétence, à faire établir par le client** : les exigences propres à l'armée ivoirienne (instruction générale de sécurité des systèmes d'information, classification, habilitations du personnel). Je ne les connais pas et je n'en invente aucune. La première demande au client doit être sa **politique de sécurité des systèmes d'information (PSSI)**.
- Légende : **P0** = bloquant avant toute donnée réelle ; **P1** = à faire avant le déploiement à l'échelle ; **P2** = souhaitable. « Constaté » = vu dans le code ; « admis » = reconnu par la revue de sécurité de la branche L11.

## 3. Ce qui est solide (à ne pas casser)

| Acquis | Où |
|---|---|
| Journal d'audit chaîné par hachage, compte applicatif sans droit de modification ni de suppression sur `audit_logs`, `access_events`, `identity_checks` | L1, L2, L7 |
| Un badge = une personne, imposé en base (index uniques, transitions de statut gardées par déclencheur) | L4 |
| Enrôlement des tablettes à usage unique, jeton haché côté serveur, coffre local chiffré lié au PIN et à une clé non exportable, effacement après 15 PIN faux | L6 |
| Synchronisation idempotente : rejeu sans doublon, conflits refusés et signalés, rien n'est écrasé | L5, L6 |
| Contrôle d'autorisation côté serveur sur chaque route, moindre pouvoir, aucun héritage de droits entre rôles | L1, matrice des droits |
| Contrôle d'objet pour l'hôte et l'encadreur (chacun ne voit que ses invités et son car) | L9, L10 |
| Aucun nom de personne dans les alertes et le journal | L8 |
| Sur la branche L11 (non fusionnée) : CSP stricte, limitation de débit, réinitialisation du 2FA en deux temps, export CSV neutralisé, purge par compte privilégié, ancrage externe du journal, analyses de dépendances, de secrets et d'image | à valider quand L11 sera fusionné |

## 4. Écarts constatés

### A. Identité, interdits et habilitation

| Réf | Constat | Risque pour un site militaire | Correctif proposé | Prio |
|---|---|---|---|---|
| A1 | Le contrôle d'identité est **une case cochée et une photo**. Aucune nature ni numéro de pièce n'est enregistré (constaté ; la revue L11 l'admet). | Impossible de prouver après coup quelle pièce a été présentée, ni de la rapprocher d'une personne recherchée. | Enregistrer le type de pièce et son numéro (chiffré ou haché selon la décision du client), rapprochement avec la fiche de la liste, second critère obligatoire pour les homonymes. | P0 |
| A2 | **Traité par L17 (28 octobre 2026), à valider.** **Aucune liste d'interdits ou de surveillance.** Un refus est lié à un événement ; la même personne peut se représenter le lendemain. | Une personne connue des services peut entrer. | Liste d'interdits persistante entre événements, alerte à l'import, à la recherche et au contrôle. Droit réservé à une autorité de sécurité, avec double validation. | P0 |
| A3 | **Traité par L16 (28 octobre 2026), à valider ; niveaux d’habilitation par zone non faits.** **Aucun niveau d'habilitation ni approbation des listes.** Les listes sont importées par l'administrateur seul ; les hôtes ajoutent des invités (catégorie « Visiteur ») sans visa d'une autorité. | Une liste falsifiée ou un hôte compromis introduit quelqu'un sans que personne de responsable ne l'ait vu. | Statut « en attente d'habilitation », approbation par un officier de sécurité (deuxième personne), niveaux d'habilitation liés aux zones. | P0 |
| A4 | L'accueil « accompagné » d'un visiteur non listé n'est qu'un texte à l'écran : **aucun accompagnateur désigné, aucun retour confirmé** (constaté). | Un non listé circule seul en zone d'accueil et au-delà. | Accompagnateur nommé, confirmation de prise en charge et de retour, alerte si le non listé franchit un point hors zone d'accueil. | P1 |
| A5 | Le système est conçu **par événement**. Le personnel permanent, les prestataires récurrents et leur réaccréditation sont « plus tard » dans le plan. | Un site militaire a des accès permanents ; l'outil ne les couvre pas. | Décision du client : outil d'événement seulement, ou module d'accréditation permanente (lot distinct). | P2 |

### B. Badge et contrôle d'accès physique

| Réf | Constat | Risque | Correctif proposé | Prio |
|---|---|---|---|---|
| B1 | Le QR est un **jeton statique** : une photo du badge suffit à le copier (admis). | Badge cloné, usurpation d'identité. | Badge portant la photo du porteur et affichage de cette photo à l'agent de contrôle ; jeton signé à durée courte rafraîchi à la sortie du poste. **Conflit à trancher** : la décision L3b interdit toute consultation des photos, ce qui empêche ce rapprochement visuel (section 8). | P0 |
| B2 | **Traité par L14a (28 octobre 2026), à valider.** Un badge **annulé, restitué, hors période ou non autorisé dans la zone** présenté à un contrôle est journalisé (`access_events`, audit) mais **ne déclenche aucune alerte** : `SiteControl` n'appelle pas `Alerts` (constaté). | Un badge volé est testé à plusieurs portes sans que le superviseur le sache. | Alerte critique immédiate « badge annulé présenté à [point] », alerte sur zone interdite, seuil sur les refus répétés d'un même poste. | P0 |
| B3 | **Traité par L14a (28 octobre 2026), à valider.** Aucune cohérence entre contrôles : un même badge peut être validé à deux points éloignés à quelques secondes d'écart ; pas d'anti-retour aux points de contrôle (constaté ; seul le poste d'accueil refuse une seconde entrée). | Clonage non détecté. | Règle de vraisemblance (délai minimal entre points), alerte sur double présentation. | P0 |
| B4 | Aucune alerte de **dépassement** : personne encore présente après la fin de validité ou de l'événement, badge non restitué. | Présence résiduelle sur le site après fermeture. | Alertes « présent hors période », « badge non restitué », clôture d'événement avec liste des présents restants à lever par le superviseur. | P1 |
| B5 | Le suivi par zone est approximatif : la zone d'une personne est le **dernier point franchi**. Pas de capacité maximale ni de comptage par zone. | Surcharge d'une salle, localisation incertaine en évacuation. | Occupation par zone, seuils réglables, alertes. | P1 |
| B6 | Aucune **gestion des objets et véhicules** (plaque, équipements déclarés, dépôt à l'entrée, fouille enregistrée) : aucune occurrence dans le code. | Téléphones, appareils photo, armes ou véhicules non tracés. | Module « objets et véhicules » dépendant des règles du client. | P1 |
| B7 | Les droits par zone ne dépendent que de la **catégorie** ; pas de plages horaires par zone. | Accès hors horaires non empêché. | Plages horaires par catégorie et par zone. | P2 |

### C. Alerte, urgence et incidents

| Réf | Constat | Risque | Correctif proposé | Prio |
|---|---|---|---|---|
| C1 | **Traité par L14b et L14c (28 octobre 2026), à valider** : message « prévenez le superviseur » à l'agent hors réseau, alerte sonore et vibrante dans l'application, escalade à l'administrateur après N minutes. Reste : canal externe (SMS, radio) selon le client. Les alertes ne sont **visibles que dans l'application ouverte** (interrogation toutes les 3 s). La décision L10 exclut e-mail, SMS et push. | Un superviseur dont le téléphone est en poche ne voit pas une alerte critique. | Alerte sonore et vibration dans l'application, notification du navigateur, **escalade** vers un second responsable si l'alerte n'est pas prise en compte sous N minutes ; canal radio ou téléphonique selon le client. | P0 |
| C2 | **Traité par L14d (28 octobre 2026), à valider** pour les tablettes (code de contrainte à l'enrôlement) ; reste : connexion web du superviseur et de l'administrateur, et tablettes déjà enrôlées. **Aucun code de contrainte** (un PIN qui paraît normal mais prévient en silence) ni bouton d'alarme du poste. | Un agent menacé ne peut rien signaler. | Code de contrainte pour agents et superviseurs, alerte silencieuse critique, journalisée. | P0 |
| C3 | **Aucune main courante** : seuls existent les alertes automatiques et les décisions de litige. | Pas de registre des incidents de sécurité décidés par les humains. | Registre d'incidents (type, lieu, personnes, actions, clôture, validation). | P1 |
| C4 | L'évacuation donne la liste des présents, mais **pas de pointage au point de rassemblement**. | On sait qui était là, pas qui est sorti vivant du bâtiment. | Appel au point de rassemblement : présents moins pointés égale manquants, en temps réel pour le superviseur. | P1 |
| C5 | **Aucun gel d'urgence** : le superviseur ne peut pas suspendre en un geste un poste, une zone, une catégorie ou un car (seule l'annulation badge par badge existe). | Réaction trop lente à une menace. | Gel réversible, à deux confirmations, journalisé. | P1 |

### D. Comptes, droits et gouvernance

| Réf | Constat | Risque | Correctif proposé | Prio |
|---|---|---|---|---|
| D1 | **Traité par L15a (28 octobre 2026) : 2FA pour tous les rôles dans le profil renforcé, à valider.** Le 2FA n'est exigé que pour l'**administrateur et le superviseur** (`User::requiresTwoFactor`, constaté). Agent d'accueil, hôte et encadreur n'ont que mot de passe et PIN de tablette. Un hôte peut introduire un invité et valider un non listé. | Un hôte ou un agent dont le mot de passe est volé agit à sa place. | 2FA pour tous les rôles ; clé physique (WebAuthn) pour administrateur et superviseur, avec accord écrit pour toute dépendance. | P0 |
| D2 | **Traité par L15b (28 octobre 2026), à valider, pour les comptes privilégiés et le retour au profil standard ; reste à brancher : export du journal, purge et réinitialisation 2FA de la branche L11.** **Aucune action critique n'exige deux personnes**, hormis la réinitialisation du 2FA (branche L11) : création d'un administrateur, changement de rôle, import de liste, purge, révocation de poste, export. | Un administrateur compromis ou malveillant peut tout faire seul. | Double validation par un second administrateur pour les actions critiques. | P0 |
| D3 | Comptes sans date d'expiration ni revue périodique de droits (un seul rôle par compte). | Comptes d'événement oubliés après la fin. | Date de fin des comptes temporaires, revue des droits avant chaque événement, désactivation automatique. | P1 |
| D4 | Les photos ne sont consultables **par aucun rôle** (décision L3b). | Aucune exploitation possible en enquête après un incident. | Accès exceptionnel, à deux personnes, motivé et journalisé (à arbitrer avec B1). | P1 |
| D5 | Politique de mot de passe et de verrouillage : **non vérifiée sur `main`** (le verrouillage progressif est annoncé sur la branche L11). | Mots de passe faibles. | Vérifier après fusion de L11 ; longueur minimale, liste de mots interdits. | P1 |
| D6 | Le propriétaire de la base de données peut réécrire tout le journal (admis). | Falsification par un exploitant malveillant. | Ancrage externe obligatoire et double contrôle des consoles, documentés pour l'exploitant. | P0 |

### E. Données et conformité

| Réf | Constat | Risque | Correctif proposé | Prio |
|---|---|---|---|---|
| E1 | Les **photos** sont chiffrées, mais les **noms, organisations et identités sont en clair** dans PostgreSQL. Le chiffrement du disque et des sauvegardes est prévu en L12, non fait. La compromission de la base et de `APP_KEY` suffit à lire les photos (admis). | Fuite de la base = fuite de toute la liste. | Chiffrement du disque obligatoire, gestion des clés hors du serveur, chiffrement des colonnes sensibles si le client l'exige. | P0 |
| E2 | Aucune **classification des données**, analyse d'impact, registre des traitements ni information affichée aux personnes. Le plan renvoie la conformité (loi n° 2013-450, ARTCI) à un juriste local « avant mise en service ». | Mise en service illégale ou contestable. | Documents de conformité et mention d'information à faire valider par le juriste ; classification fixée par le client. | P0 |
| E3 | Durées de conservation **proposées, non approuvées** ; aucune procédure d'accès, de rectification ou d'effacement demandée par une personne. | Conservation excessive, droits des personnes sans réponse. | Valider les durées avec le client ; procédure de traitement des demandes. | P1 |
| E4 | **Hébergement non décidé** (plan, décision 2). Le serveur de démonstration est un VPS partagé (Contabo, d'après le prompt L12), uniquement pour des données fictives. | Données militaires hors du pays ou sur un serveur partagé. | Production sur site ou chez un hébergeur de confiance dédié ; décision du client. | P0 |
| E5 | Les impressions (évacuation) et exports contiennent des noms : **ni filigrane, ni mention de classification, ni traçabilité de l'impression**. | Document diffusé sans trace. | Filigrane (agent, heure), mention de classification, journalisation de l'impression. | P1 |

### F. Résilience et exploitation

| Réf | Constat | Risque | Correctif proposé | Prio |
|---|---|---|---|---|
| F1 | **Sauvegarde chiffrée et restauration testée : non faites** (L12). Serveur unique, sans réplication, sans objectif de reprise défini. | Perte des données ou arrêt prolongé. | L12, plus RPO/RTO écrits et une répétition de restauration signée. | P0 |
| F2 | **Traité en partie par L14b (28 octobre 2026), à valider** : blocage après N heures sans synchronisation, réglable. Reste : gestion d'appareils et mode kiosque (matériel du client). La **révocation d'une tablette volée ne s'applique qu'à sa prochaine synchronisation** (admis). L'effacement à distance dépend d'une gestion d'appareils du client, non fournie. La tablette hors réseau n'a pas de durée maximale de validité. | Tablette volée utilisable hors ligne. | Gestion d'appareils et mode kiosque obligatoires ; verrouillage automatique après N heures sans synchronisation ; PIN plus long ou biométrie de l'appareil. | P0 |
| F3 | Aucune **supervision ni détection d'anomalie**, aucun export vers un système de journalisation du client. | Une attaque en cours n'est vue par personne. | Journal applicatif en syslog standard, règles de détection (refus répétés, scans invalides, connexions hors horaires, nouvelle adresse pour un administrateur). | P1 |
| F4 | Plan de continuité et de reprise : registre papier prévu en L13, **non écrit**. | Panne = arrêt de l'accueil. | L13, plus répétition. | P1 |
| F5 | Charge réelle non mesurée (objectifs de 60 s, 20 s, 5 s vérifiés en recette, pas à 1 000 visiteurs et 4 postes). | Dégradation le jour J. | L13. | P1 |

### G. Assurance et chaîne d'approvisionnement

| Réf | Constat | Risque | Correctif proposé | Prio |
|---|---|---|---|---|
| G1 | **Aucun test d'intrusion indépendant ni revue de code externe.** Le document de fonctionnement (section 11) l'exige avant la mise en service. L'analyse automatique ZAP ne couvre pas les pages authentifiées (admis). | Failles non vues par ceux qui ont écrit le code. | Pentest et revue de code par un tiers de confiance, avant tout usage réel. | P0 |
| G2 | Le code est produit par des **agents d'IA supervisés par un seul dirigeant**. Pas de nomenclature logicielle (SBOM), pas d'images signées, pas de build reproductible vérifié, pas de relecteur humain indépendant. | Code ou dépendance malveillante non détectée (chaîne d'approvisionnement). | Relecture humaine indépendante des parties sensibles, SBOM, signature des images, dépôt privé aux accès restreints, versions de dépendances figées. | P0 |
| G3 | **Propriété du code, licence, maintenance, support et séquestre non décidés** (plan, décision 7). Aucun contrôle des personnes qui administrent ou hébergent. | Dépendance à un tiers sans garantie ; fuite par un administrateur. | Contrat (propriété, SLA, séquestre du code), habilitation des personnes ayant accès à la production. | P0 |
| G4 | Pas de **procédure de réponse aux incidents de sécurité** ni de gestion des vulnérabilités après la mise en service. | Réaction improvisée. | Ajouter au dossier d'exploitation (L13) : qui, quand, comment prévenir le client. | P1 |

### H. Écarts avec le document de fonctionnement du client

| Réf | Constat | Prio |
|---|---|---|
| H1 | **Agent mobile** (section 10) : la permission `visiteurs.enregistrer_mobile` existe, mais **aucune route ni écran ne l'utilise** (constaté). Fonction décrite, non livrée. | P1 |
| H2 | **Traitement discret des VIP** : décision du client toujours ouverte (plan, décision 5). | P1 |
| H3 | **Règle de restitution des badges** (réutilisable ou usage unique) : réglage présent, décision du client non prise. | P1 |
| H4 | **Rapport de fin d'événement** pour les autorités (statistiques, présents, incidents, signature) : absent. | P1 |
| H5 | **Registre papier et ressaisie** (section 12) : prévu en L13, non écrit. | P1 |

## 5. Ce que l'armée demandera sans doute et que ce rapport ne tranche pas

- Hébergement **sur site** ou en centre national, sans lien vers l'extérieur ou avec filtrage strict.
- **Classification** des données et des documents imprimés.
- **Habilitation** de toute personne ayant accès au code, au serveur ou aux sauvegardes.
- Certification, homologation ou audit selon la réglementation ivoirienne en vigueur : **à demander au client**, je ne la connais pas.
- Matériel dédié : tablettes durcies, gestion d'appareils, onduleurs, réseau cloisonné.

## 6. Actions sans code, à lancer tout de suite

1. Demander au client sa **PSSI** et ses exigences de classification et d'homologation.
2. Faire trancher l'**hébergement** et le **juriste** (loi n° 2013-450, ARTCI, et textes propres à l'armée).
3. Clarifier **propriété du code, maintenance et séquestre** avec IKOMA GROUP.
4. Identifier le **prestataire de pentest** et l'**auteur de la revue de code** indépendants.
5. Désigner côté client l'**autorité de sécurité** qui approuvera les listes (A3) et gérera la liste d'interdits (A2).

## 7. Feuille de route proposée

Les lots L11 à L13 existent déjà ; je propose d'insérer six lots entre L12 et L13, car la répétition générale doit porter sur le système complet.

| Lot | Contenu | Écarts traités | Dépend de |
|---|---|---|---|
| L11 (en cours) | Durcissement applicatif (branche de ChatGPT Work) | D5, D6 en partie, F3 en partie | — |
| L12 | Production, sauvegardes, installation guidée | F1, E1 en partie, E4 | décision d'hébergement |
| **L14** | **Contrôle d'accès renforcé** : alertes de contrôle, détection de double présentation et de clonage, alertes de dépassement, comptage par zone, gel d'urgence | B2, B3, B4, B5, C5 | — |
| **L15** | **Vérification des personnes** : pièce d'identité, liste d'interdits, approbation des listes par une autorité, accompagnateur nommé | A1 à A4 | décisions A et B1 du client |
| **L16** | **Alerte, urgence, incidents** : alerte sonore et escalade, code de contrainte, main courante, pointage au rassemblement | C1 à C4 | choix des canaux du client |
| **L17** | **Gouvernance des accès** : 2FA pour tous, clé physique, double validation, expiration des comptes, accès exceptionnel aux photos, verrouillage de tablette après N heures hors réseau | D1 à D4, F2 | — |
| **L18** | **Protection des données** : chiffrement des colonnes sensibles, filigrane et traçabilité des impressions, procédure de demande des personnes, rapport de fin d'événement, agent mobile | E1, E3, E5, H1, H4 | classification et juriste |
| **L19** | **Observabilité et assurance** : journal en syslog, règles de détection, SBOM, images signées, dossier remis à l'auditeur | F3, G2 | — |
| Audit externe | Pentest et revue de code indépendants, puis correction des constats | G1 | L12 à L19 |
| L13 | Répétition générale, exploitation, pilote réduit | F4, F5, G4, H5 | tous les précédents |

**Critère d'entrée de toute donnée réelle** : L11 à L19 fusionnés, audit externe sans faille critique ni élevée ouverte, décisions de la section 8 prises, répétition L13 signée par le dirigeant et le client.

## 8. Décisions à prendre (par le dirigeant, puis le client)

> **28 octobre 2026 : le dirigeant a adopté les recommandations de l'architecte pour ces onze décisions** (voir docs/JOURNAL_DECISIONS.md, rubrique « Décisions du dirigeant du 28 octobre 2026 »). Elles ne sont plus bloquantes ; les réserves (juriste, client) demeurent.

1. **Photo au contrôle (B1 contre D4)** : l'agent de contrôle voit-il la photo enregistrée en scannant ? Je recommande oui, en l'affichant 5 secondes sans la stocker sur l'écran, et en autorisant un accès exceptionnel à deux personnes pour les enquêtes.
2. **Numéro de pièce (A1)** : le conserver chiffré, le conserver haché (comparaison seulement), ou ne rien conserver ? Je recommande chiffré avec durée courte, sous réserve du juriste.
3. **Liste d'interdits (A2)** : qui la tient, qui peut ajouter ou retirer, quelle durée de conservation ?
4. **Habilitation (A3)** : quels niveaux, et qui approuve (officier de sécurité) ?
5. **Alertes (C1)** : quels canaux le client accepte (sonnerie dans l'application, notification, SMS, radio), et qui reçoit l'escalade ?
6. **2FA pour tous (D1)** : oui pour tous les rôles ? Une clé physique pour administrateur et superviseur ?
7. **Objets et véhicules (B6)** : le site les contrôle-t-il ? Quelles règles ?
8. **Hébergement et classification** (E4, E2) : sur site ? Quel niveau de classification ?
9. **Outil d'événement seulement, ou accréditation permanente (A5)** ?
10. Les décisions déjà ouvertes au plan : VIP (H2), restitution des badges (H3), durées de conservation (E3), propriété et maintenance (G3).
11. **Seuils proposés lors du lot « Accueil de l'agent »** : délai sans réseau avant d'appeler le superviseur (15 minutes proposées) et seuil « il reste peu de badges » (10 % ou 5 badges). Tous deux deviendront des réglages d'Administration.

## 9. Risques de ce rapport

- Il repose sur la lecture du code et des documents, pas sur une attaque réelle : **un défaut non listé ici peut exister**.
- Plusieurs priorités P0 dépendent d'exigences du client que je ne connais pas : elles peuvent monter ou descendre selon sa PSSI.
- Les lots proposés ajoutent beaucoup de fonctions sensibles : chacun doit passer par la même discipline que les précédents (tests, journal des décisions, validation du dirigeant), et l'audit externe doit les couvrir.
