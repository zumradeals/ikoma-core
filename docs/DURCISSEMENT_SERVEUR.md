# Durcissement du serveur de production (lot L12)

> Guide à appliquer **avant** `install.sh`, sur le serveur de production (jamais sur la démonstration). Ce lot ne réalise aucune action sur un serveur réel : il fournit ce guide et un script de **vérification en lecture seule** : `sudo bash deploy/prod/verifier-serveur.sh`. Les commandes ci-dessous sont des exemples pour Debian/Ubuntu ; adaptez-les à votre système et à la politique de sécurité du client (PSSI).

## 1. Principe
Le serveur n'a qu'un rôle : faire tourner IKOMA Accueil. Tout ce qui n'est pas indispensable est supprimé ou fermé ; tout accès administrateur est nominatif, par clé, journalisé.

## 2. Pare-feu : n'ouvrir que le nécessaire
Autoriser : **443** (HTTPS) et **80** (redirection et renouvellement du certificat) depuis le réseau du site ; **SSH** seulement depuis les adresses d'administration. Tout le reste est fermé. La base de données (5432) et PHP (9000) **ne sont pas** publiés par la configuration de production ; ne les ouvrez jamais.
```sh
sudo ufw default deny incoming && sudo ufw default allow outgoing
sudo ufw allow from <adresse-administration> to any port 22 proto tcp
sudo ufw allow 80/tcp && sudo ufw allow 443/tcp
sudo ufw enable
```
> Docker contourne parfois ufw pour les ports qu'il publie. La configuration IKOMA ne publie que 80 et 443 ; ne publiez aucun autre port. Réseau fermé : limitez 80/443 à l'étendue du réseau du site.

## 3. SSH : clés seulement, nominatif
- Un compte par administrateur (pas de compte partagé) ; connexion par **clé**, jamais par mot de passe ; pas de connexion directe en root.
- `/etc/ssh/sshd_config.d/ikoma.conf` :
  ```
  PasswordAuthentication no
  PermitRootLogin no
  KbdInteractiveAuthentication no
  MaxAuthTries 3
  AllowUsers administrateur1 administrateur2
  ```
  puis `sudo systemctl reload ssh`. **Gardez une session ouverte** pendant que vous testez la nouvelle règle.
- Protection contre les essais répétés : `fail2ban` (ou équivalent).

## 4. Mises à jour automatiques de sécurité
```sh
sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades
```
Prévoir un créneau de redémarrage pour les mises à jour du noyau. Les **images Docker** ne se mettent pas à jour seules : à chaque mise à jour d'IKOMA (`update.sh`), elles sont reconstruites avec les derniers correctifs système. Planifiez une mise à jour **au moins mensuelle** (et à chaque alerte de sécurité).

## 5. Utilisateur dédié, services inutiles
- Les secrets d'IKOMA appartiennent à l'utilisateur 10001 (conteneurs) ; le dossier d'installation (`/opt/ikoma`) n'est lisible que des administrateurs.
- Appartenir au groupe `docker` équivaut à être root : n'y mettez que les administrateurs.
- Désactivez ce qui n'est pas nécessaire : `sudo systemctl list-unit-files --state=enabled`, puis `disable --now` pour les services inutiles (serveur de messagerie, partage de fichiers, impression, découverte réseau…). Ne gardez qu'SSH, Docker, la synchronisation de l'heure, le pare-feu, cron.

## 6. Chiffrement du disque
Un serveur volé ou un disque jeté ne doit rien livrer. Deux niveaux, **à combiner** :
1. Les **photos, pièces d'identité et sauvegardes** sont déjà chiffrées par l'application (photos et pièces) ou par GPG (sauvegardes).
2. Le **disque entier** doit être chiffré (LUKS) à l'installation du système. Les noms, les organisations et le journal d'audit sont dans la base de données : sans chiffrement du disque, ils sont lisibles sur un disque dérobé.
   Au démarrage, la phrase de passe est saisie par un administrateur (ou un dispositif de déverrouillage réseau dont le client maîtrise la sécurité). Notez la procédure de redémarrage dans le manuel d'exploitation.

## 7. Synchronisation de l'heure
Le journal d'audit (heures horodatées) et la détection des tablettes dont l'horloge dérive en dépendent.
```sh
sudo apt install chrony && sudo systemctl enable --now chrony && timedatectl status   # « System clock synchronized: yes »
```
Réseau fermé : pointez chrony vers un serveur de temps interne du client.

## 8. Journaux, surveillance, accès physique
- Les journaux d'IKOMA (nginx, PHP) ne contiennent ni nom de visiteur ni paramètre de requête ; ils tournent (5 fichiers de 10 Mo par service). Transférez-les vers le collecteur du client si celui-ci en a un (audit global F3, non traité dans ce lot).
- Surveillez `/sante` ([DEPLOIEMENT.md](DEPLOIEMENT.md), section 7).
- Salle fermée à clé ; accès nominatif et journalisé ; onduleur ; capot du serveur verrouillé ou, à défaut, boîtier scellé.

## 9. Vérifier
```sh
sudo bash deploy/prod/verifier-serveur.sh
```
Le script contrôle (sans rien modifier) : SSH, pare-feu et ports ouverts, mises à jour automatiques, heure, chiffrement du disque, comptes sans mot de passe, droits du dossier des secrets, présence d'une sauvegarde récente. Il retourne un code non nul si un point ✗ subsiste. Gardez son résultat daté dans le dossier d'homologation.

## 10. Ce que ce guide ne fait pas
Il ne remplace ni la politique de sécurité (PSSI) du client, ni un test d'intrusion indépendant, ni la formation des exploitants. Il ne couvre pas la sécurité des tablettes (voir [AUTH_HORS_LIGNE.md](AUTH_HORS_LIGNE.md) : coffre chiffré, code PIN, effacement à la révocation) ni la gestion des appareils (verrouillage, mises à jour du système des tablettes) qui reste à définir avec le client.
