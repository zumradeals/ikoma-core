# Lot B — Retirer le code PIN de l'espace Agent : audit du coffre et contrat de protection proposé

Statut : **proposition de l'architecte, aucun code modifié.** À valider par le propriétaire avant toute implémentation. Rien ici n'est décidé tant qu'il ne l'a pas déclaré validé.
Décision déjà prise par le propriétaire : le code PIN ne doit plus faire partie de l'expérience quotidienne de l'agent. La correction du scan (lot A) n'en dépend pas.

## 1. Ce qui existe aujourd'hui (audit)

Fichiers : `resources/js/poste/local/crypto.ts`, `vault.ts`, `runtime.ts`, `components/poste/PosteGate.vue`, `docs/AUTH_HORS_LIGNE.md`.

**Mécanisme.** Une clé de données (DEK, AES-256-GCM, 256 bits aléatoires) chiffre tout ce que la tablette garde (copie de la liste, file d'opérations, photos en attente, jeton d'appareil, profil de l'agent). La DEK n'est jamais stockée en clair : elle est enveloppée deux fois.
1. Clé dérivée du **PIN** (PBKDF2-SHA256, 600 000 itérations, sel de 16 octets) puis de l'**secret serveur** remis à l'enrôlement (HKDF) ;
2. **clé d'appareil** AES non exportable, conservée dans IndexedDB.
Ouvrir le coffre exige les deux enveloppes ; la DEK n'existe en mémoire que tablette déverrouillée.

**Ce qui dépend du PIN** (à remplacer, un par un) :
| Fonction | Où | Rôle actuel |
|---|---|---|
| Création du PIN à l'enrôlement | `PosteGate` (formulaire), `PosteVault.enrol` | dérive la clé qui enveloppe la DEK |
| Déverrouillage | `PosteVault.unlock`, écran « Tablette verrouillée » | ouvre la DEK ; sans lui, rien n'est lisible |
| Verrouillage à l'inactivité | `PosteGate.resetIdle`, réglage `poste_inactivite_minutes` | efface la DEK de la mémoire |
| Limite d'essais et effacement | `failures` chiffré par la clé d'appareil, réglage `poste_echecs_pin_max` | 5 essais puis délais, 15 échecs : effacement complet |
| Verrouillage hors ligne trop long | `runtime.syncRequiredHours` / `poste_hors_reseau_max_heures` | verrouille la tablette si trop longtemps sans réseau |
| Refus d'un autre agent | `unlock(pin, agentId)` | un autre compte ne rouvre pas le coffre |
| Synchronisation | `runtime.sync` | exige la DEK (le jeton d'appareil est dans le coffre) : **aucune synchronisation tablette verrouillée** |
| Tests et recettes | tests front du coffre, e2e (`PIN` dans les parcours), `docs/AUTH_HORS_LIGNE.md` | à réécrire |

**Constat utile.** Le PIN protège la DEK, pas les données : changer le mode d'enveloppe de la DEK ne demande **aucun rechiffrement** des données ni de la file d'opérations. C'est ce qui permet une migration sans perte.

## 2. Ce que le PIN protège, et ce qu'on perd en le retirant (à dire clairement)

Le PIN est le seul facteur « connaissance » du coffre. Le retirer **affaiblit** la protection contre une personne qui a la tablette en main, déverrouillée ou non, et qui sait ouvrir le navigateur. Il n'existe pas de remplacement qui garde le même niveau sans geste de l'agent. Le contrat ci-dessous remplace le PIN par un ensemble de protections qui réduisent ce risque ; il ne le supprime pas. Ce point est une **décision du propriétaire**, pas un détail technique.

## 3. Options étudiées

| Option | Principe | Hors ligne | Niveau de protection | Remarques |
|---|---|---|---|---|
| **A. Bail de poste (recommandée)** | Plus aucun geste. La DEK est enveloppée par la clé d'appareil ET par un **secret de bail** remis par le serveur à une session valide (compte de l'agent, jeton de l'appareil). Le bail a une durée courte, renouvelée en silence en ligne. | Oui, jusqu'à l'expiration du bail (reprend le réglage existant `poste_hors_reseau_max_heures`, 12 h par défaut). | Protège contre : copie du stockage sans le bail, tablette hors bail, tablette révoquée, autre compte. **Ne protège pas** contre une personne qui ouvre l'application sur la tablette pendant le bail. | Zéro saisie. Exige la protection de l'appareil (verrouillage d'écran du système, mode kiosque, gestion de flotte). |
| **B. Clé liée au système (WebAuthn + PRF)** | La clé est dérivée par le dispositif du système (empreinte, code d'écran de la tablette) à chaque déverrouillage. | Oui | La plus forte sans PIN propre à l'application. | Dépend du modèle de tablette et de la version du navigateur ; geste du système à chaque verrouillage ; à tester sur le matériel réel avant de s'engager. |
| **C. PIN une fois par service** | Le PIN reste, mais demandé une seule fois par prise de service (pas à chaque verrouillage). | Oui | Proche de l'actuel. | Ne répond pas à la demande de retirer le PIN ; solution de repli. |

**Recommandation : A**, avec B étudié en pilote sur la tablette du client comme renforcement optionnel. A satisfait la demande (aucune saisie) tout en gardant des protections réelles et vérifiables.

## 4. Contrat de protection du coffre proposé (option A)

### 4.1 Clés
- **DEK** : inchangée dans son principe (AES-256-GCM, 256 bits aléatoires, jamais en clair).
- **Clé d'appareil** : inchangée (non exportable, IndexedDB).
- **Secret de bail** `L` : 32 octets, **calculé par le serveur** pour (appareil, compte de l'agent, identifiant de bail) à partir d'une clé du serveur, remis seulement sur HTTPS à une requête portant la session de l'agent **et** le jeton d'appareil valides. Le serveur ne stocke pas `L` ; il garde l'identifiant, l'échéance et l'état du bail.
- **Enveloppe** : `DEK` ⟶ chiffrée par `HKDF(L)`, puis par la clé d'appareil. Le bail n'est **jamais** une valeur publique, codée en dur ou commune à plusieurs tablettes.
- Aucune clé en clair : `L` est conservé scellé par la clé d'appareil avec son échéance, en mémoire seulement une fois la DEK ouverte.

### 4.2 Cycle de vie
1. **Enrôlement** (en ligne, code à usage unique, inchangé) : l'agent est connecté ; le serveur émet le premier bail ; la DEK est créée et enveloppée. Aucun PIN n'est demandé.
2. **Ouverture** : au démarrage de l'application ou après une veille, si un bail valide est présent, la tablette ouvre le coffre **sans écran ni saisie**. Elle peut donc **synchroniser tout de suite**, même après un redémarrage (aujourd'hui, une tablette verrouillée ne synchronise pas).
3. **Renouvellement** : en ligne, le bail est renouvelé en silence avant son échéance. Le serveur peut refuser (appareil révoqué, compte désactivé, rôle retiré).
4. **Expiration** : à l'échéance sans renouvellement, le bail est supprimé de la tablette ; le coffre reste fermé jusqu'à une **connexion en ligne** (mot de passe de l'agent, comme aujourd'hui pour toute reprise après expiration de session). Les opérations en attente restent chiffrées et sont envoyées après reconnexion : **aucune n'est effacée** à l'expiration.
5. **Déconnexion volontaire** de l'agent : le bail est supprimé ; mêmes effets que l'expiration.
6. **Révocation** de l'appareil (administrateur) : refus immédiat du renouvellement et de toute synchronisation ; effacement local dès le premier échange avec le serveur (inchangé) ; journalisé.
7. **Changement d'agent** sur la tablette : refusé (le bail est lié au compte) ; l'ancien coffre est traité comme aujourd'hui (réenrôlement, avec avertissement si des opérations attendent).
8. **Fin d'activité** : effacement après 24 h, inchangé.

### 4.3 Ce qui remplace chaque fonction du PIN
| Fonction du PIN | Remplacement |
|---|---|
| Déverrouillage quotidien | Bail valide : ouverture automatique |
| Verrouillage à l'inactivité | **Supprimé** (sans PIN, un verrou sans secret n'a aucun sens). Protection : verrouillage d'écran du système et mode kiosque, exigence de déploiement |
| Limite d'essais puis effacement | Sans objet (plus de secret à deviner). Remplacée par l'effacement sur révocation, expiration du bail, fin d'activité et changement de compte |
| Verrouillage si trop longtemps hors ligne | **Conservé** : c'est l'échéance du bail (`poste_hors_reseau_max_heures`) |
| Un autre agent ne rouvre pas le coffre | Conservé (bail lié au compte) |
| Effacement manuel réservé au superviseur | Conservé |

### 4.4 Exigences de déploiement (à inscrire dans `docs/DURCISSEMENT_SERVEUR.md` et le guide d'exploitation)
- Verrouillage d'écran du système actif sur chaque tablette et délai court de mise en veille.
- Navigateur en mode kiosque / application épinglée ; pas d'accès aux outils de développement.
- Gestion de flotte avec effacement à distance (déjà prévu au plan, section 9).
- Durée du bail et durée hors ligne réglées dans l'écran Administration (aucun réglage dans les fichiers techniques).

## 5. Analyse des risques

| Menace | Avec le PIN | Avec le bail (A) | Mesures |
|---|---|---|---|
| Tablette perdue ou volée, **fermée**, système verrouillé | Données illisibles | Données illisibles (écran verrouillé du système) | Verrouillage d'écran obligatoire |
| Tablette volée, **appli ouverte ou écran déverrouillé** | Le PIN protège après l'inactivité | **Données accessibles pendant le bail** | Bail court ; révocation immédiate dès la déclaration de perte ; effacement à distance ; données minimales (hachages de QR, pas de jetons) |
| Copie du stockage du navigateur (profil exporté) | Illisible sans le PIN | Illisible sans le bail (le bail est remis par le serveur et lié à l'appareil) ; la clé d'appareil non exportable limite la copie | Surveillance des enrôlements, journalisation |
| Agent malveillant (autorisé) | Même accès | Même accès | Traçabilité nominative, périmètre de poste |
| Clonage du jeton d'appareil | Jeton dans le coffre | Idem, renouvellement refusable | Révocation |
| Horloge de la tablette modifiée | Délai local | Échéance du bail contrôlée aussi par le serveur à chaque synchronisation (écart d'horloge déjà mesuré) | Alerte d'écart d'horloge existante |
| Script injecté dans la page | Idem | Idem | CSP stricte déjà en place (lot L11) |
Risque résiduel principal : **accès aux données locales d'une tablette déverrouillée pendant la durée du bail.** Il est accepté par le propriétaire ou refusé ; s'il est refusé, retenir l'option B ou C.

## 6. Interdictions respectées
Aucun PIN codé en dur. Aucun déverrouillage par secret public (le bail est propre à un appareil et à un compte, émis par le serveur). Aucune clé de données en clair. Aucun affaiblissement silencieux : l'affaiblissement est décrit en section 2 et 5 et soumis à validation. Aucun contournement des sessions ni des permissions (le bail exige la session de l'agent). Aucun effacement de la file d'opérations sans traitement explicite (section 7).

## 7. Contrat de migration (aucune opération perdue)
1. **Version double** : la nouvelle version lit l'ancien coffre (PIN) et le nouveau. Rien n'est supprimé au déploiement.
2. **Migration à la prochaine ouverture par PIN** : l'agent saisit son PIN **une dernière fois** ; la DEK est alors en mémoire. En ligne, la tablette demande un bail, crée la nouvelle enveloppe, **vérifie qu'elle ouvre la DEK**, puis seulement remplace l'enregistrement. Les données et la file ne sont **jamais rechiffrées** (la DEK ne change pas).
3. **Hors ligne au moment de la mise à jour** : la tablette reste en mode PIN jusqu'au prochain accès en ligne ; rien n'est forcé.
4. **File non vide** : le nombre d'opérations en attente est affiché avant migration ; la migration ne les touche pas ; elles partent à la synchronisation suivante.
5. **Échec ou interruption** : l'enveloppe PIN reste valable tant que la nouvelle n'est pas vérifiée (écriture atomique, vérification avant remplacement).
6. **Retour arrière** : conserver l'enveloppe PIN pendant une période de grâce réglable (par exemple 14 jours) si le propriétaire veut pouvoir revenir ; sinon suppression après vérification.
7. **Nettoyage** : suppression du compteur d'essais, du réglage `poste_echecs_pin_max` et des champs PIN de l'enrôlement, après la période de grâce.

## 8. Travail prévu après validation (lot B, pas avant)
Serveur : émission, renouvellement, révocation du bail (table des baux : **migration de schéma, à valider avec ce contrat**), journal d'audit, limitation de débit. Client : nouveau coffre, ouverture automatique, retrait des écrans PIN, enrôlement sans PIN, migration. Réglages : durée du bail dans l'écran Administration. Tests : migration sans perte (file non vide), expiration, renouvellement, révocation, hors ligne, changement de compte, absence de secret en clair, anciens coffres. Documentation : `AUTH_HORS_LIGNE.md`, durcissement, exploitation.

## 9. Décisions demandées au propriétaire
1. Retenir l'**option A** (bail de poste) ? Accepter le risque résiduel de la section 5 ?
2. Durée du bail et durée maximale hors ligne (valeur de départ proposée : celle du réglage actuel, 12 heures).
3. Exiger le verrouillage d'écran du système et le mode kiosque comme condition de déploiement ?
4. Étudier l'option B (empreinte ou code d'écran du système) en pilote sur le matériel du client ?
5. Conserver l'enveloppe PIN pendant une période de grâce pour pouvoir revenir en arrière ?
