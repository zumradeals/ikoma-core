# Hébergement de la production — recommandation (lot L12)

> **Décision du dirigeant et du client requise.** Ce document propose ; il ne tranche pas. Aucun chiffre de prix précis n'est donné : les coûts varient selon le pays, le fournisseur et le moment, et doivent être demandés par devis.
> Principe : le serveur de **démonstration** (partagé, données fictives) ne reçoit **jamais** de données réelles. La production est un serveur **distinct**.

## Ce qu'il faut protéger
Des noms et des photos de personnes, des pièces d'identité (option), les mouvements des visiteurs d'un site, et un journal d'audit. Pour un client militaire, ces informations sont sensibles : leur fuite révèle qui fréquente un site et quand.

## Les trois options

| | **A. Sur site** (serveur chez le client) | **B. Serveur dédié chez un hébergeur de confiance** | **C. Serveur privé virtuel (VPS) dédié** |
|---|---|---|---|
| Contrôle des données | Total : elles ne quittent jamais le site | Fort, mais un tiers a un accès physique | Moyen : un tiers gère la machine hôte |
| Localisation légale | Celle du client, sans ambiguïté | À choisir : pays et lois applicables du centre de données | À vérifier : souvent plusieurs pays possibles |
| Fonctionnement sans Internet | **Oui** (réseau local du site) | Non : dépend de la liaison du site | Non |
| Accès depuis plusieurs sites | Difficile (VPN à prévoir) | Facile | Facile |
| Exploitation | À la charge du client : électricité, climatisation, onduleur, sauvegardes, remplacement de matériel | Matériel et réseau gérés ; système et sauvegardes à notre charge ou à celle du client | Matériel géré ; système, sécurité et sauvegardes à notre charge |
| Risques principaux | Panne ou vol du matériel, coupure d'électricité, compétence locale | Dépendance du fournisseur, juridiction, accès par le fournisseur | Isolement moindre (machine partagée), juridiction, disponibilité |
| Coût | Matériel + exploitation internes ; pas d'abonnement | Abonnement mensuel supérieur au VPS | Le plus bas en abonnement |
| Délai de mise en service | Long (achat, installation) | Court à moyen | Très court |

## Recommandation
**Pour l'armée de Côte d'Ivoire : option A (sur site) comme cible, avec l'option B en secours pour la copie de sauvegarde.**

Raisons : (1) les données restent sous la juridiction et le contrôle physique du client ; (2) l'application est conçue pour fonctionner avec ou sans réseau : un serveur local garde le site opérationnel même si la liaison Internet tombe ; (3) cela supprime la question délicate de l'accès d'un tiers étranger à ces données.

Conditions pour que ce choix soit sûr : une salle fermée à accès contrôlé ; un onduleur et une alimentation de secours ; **un deuxième emplacement pour les sauvegardes chiffrées** (autre bâtiment ou site, ou un hébergeur de confiance, car elles sont illisibles sans la clé privée) ; au moins deux personnes formées à l'exploitation et à la restauration ; un matériel de rechange ou un contrat de remplacement rapide.

**Si le client ne peut pas exploiter un serveur sur site** : option B (serveur dédié) dans le pays du client ou dans un pays dont le droit lui convient, avec chiffrement du disque et clé conservée par le client. L'option C n'est acceptable que pour une phase pilote, jamais pour un usage réel durable.

La **démonstration** peut rester sur son serveur partagé actuel (données fictives).

## Questions à poser au client avant de décider
1. **Où les données doivent-elles se trouver légalement ?** Y a-t-il une obligation de les garder sur le territoire national, ou dans une installation de l'armée ? Quelle autorité doit donner son accord ?
2. **Quel est le réseau du site ?** Réseau local fermé ou raccordé à Internet ? Quelle disponibilité de la liaison ? Y a-t-il un accès filaire dans les zones d'accueil et un Wi-Fi maîtrisé pour les tablettes ?
3. **Électricité** : coupures fréquentes ? onduleur, groupe électrogène ? combien de minutes d'autonomie ?
4. **Remplacement du matériel** : qui remplace une machine en panne, en combien de temps ? un deuxième serveur de secours est-il souhaité ?
5. **Qui exploite ?** Personnel du client formé, ou prestataire ? Qui détient la clé privée des sauvegardes, et qui est le suppléant ?
6. **Sauvegardes** : où se trouve le second emplacement ? Qui y a accès ? Quelle durée de conservation ?
7. **Volume et durée** : combien de visiteurs par événement, combien d'événements par an, combien de sites ? (Pour dimensionner le serveur.)
8. **Audit et contrôle** : le client souhaite-t-il un test d'intrusion indépendant, une homologation de sécurité ? Quelle procédure d'incident (qui prévenir, en combien de temps) ?
9. **Fin de vie** : comment détruire les disques ? Qui certifie la destruction ?

## Dimensionnement indicatif (à confirmer avec les réponses ci-dessus)
Un serveur modeste suffit à plusieurs milliers de visiteurs par jour : 4 à 8 Go de mémoire, 2 à 4 processeurs, 100 Go de disque rapide (SSD), plus un espace de sauvegarde au moins égal à 14 fois la taille quotidienne des données. Les photos sont la partie la plus volumineuse.
