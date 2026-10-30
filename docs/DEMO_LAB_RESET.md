# IKOMA ACCESS — Laboratoire de démonstration et remise à zéro

## Périmètre de cette évolution

L'interface de démonstration est visible uniquement sur une installation `APP_ENV=demo`,
`local` ou `testing`, pour un **administrateur actif avec 2FA confirmée**. L'environnement
`production` refuse ses routes, et les migrations **ne donnent pas au compte web
de production** le droit d'exécuter les fonctions privilégiées de remise à zéro.

Deux opérations distinctes :

1. **Générer un scénario fictif** : 20, 100, 300 ou 1 000 personnes, zones,
   catégories, hôte fictif, tablette non enrôlée, badges et éventuellement groupe.
   Un marqueur de provenance `events.is_demo` distingue ces événements des autres.
2. **Nettoyer un scénario DÉMO** : suppression des données de ce scénario, même s'il
   a été utilisé pour des contrôles fictifs. Le serveur refuse toute tentative
   de cibler un événement sans marqueur `is_demo`.

Une troisième opération, volontairement plus destructive, est proposée :

3. **Remettre toutes les données métier à zéro** : événements, visiteurs,
   badges, lots, groupes, zones, postes/tablettes, contrôles, notifications,
   alertes, synchronisations et identifiants de badges retirés sont effacés.
   Cela inclut les événements ordinaires de l'installation de test : il faut
   lire les compteurs, cocher l'engagement explicite, recopier
   `REMETTRE A ZERO IKOMA` et saisir le mot de passe courant.

**Ce n'est PAS une destruction physique de la base PostgreSQL.**
Les comptes et rôles, mots de passe, configuration technique, migrations,
sessions et **journal d'audit de sécurité** sont conservés pour ne pas
perdre le contrôle de l'installation. Pour reconstruire une installation
complètement neuve, il faut un processus d'exploitation séparé et une
nouvelle identité administrateur. Aucun `migrate:fresh` n'est exposé au web.

## Mesures contre l'effacement non voulu

- Aucune fonction de génération ou de reset exposée en production.
- Pas de permission générique de suppression des journaux d'accès :
  les fonctions SQL à périmètre fixe exécutent seulement des tables métier
  connues et refusent d'utiliser `CASCADE` pour le reset complet.
- Suppression ciblée : l'événement doit être marqué `is_demo` dans la base.
- Reset complet : rôle administrateur et 2FA, mot de passe, phrase exacte et
  case d'acceptation ; limitation des requêtes.
- Pas d'exécution automatique au déploiement, ni de terminal requis pour
  l'administrateur.
- Les références de photos privées sont inscrites dans la file d'effacement.
  La file peut nécessiter une reprise si un fichier est momentanément
  inaccessible. Les pièces déjà exportées ou imprimées ne peuvent être rappelées.
- Une tablette précédemment utilisée peut conserver des données hors ligne :
  elle doit être vidée/réenrôlée avant d'être remise au service.
- La réinitialisation laisse une trace d'audit minimale, même si l'historique
  métier est effacé.

## Revue avant fusion/déploiement

- Vérifier la CI (tests métier, frontend, permissions, migration de schéma).
- Vérifier que la session administrateur demeure fonctionnelle après reset.
- Essayer un scénario, créer au moins un contrôle, puis le supprimer.
- Vérifier que la suppression ciblée n'affecte pas `seminaire`.
- Dans un autre test isolé, remettre à zéro : tous les compteurs métier à 0,
  comptes et réglages toujours présents.
- Tester le refus des opérations sur un environnement `production`.
- Contrôler le traitement des fichiers et appareils hors ligne.
- Ne pas faire d'essai destructeur sur une base contenant des données à garder.

Cette PR n'autorise aucune purge immédiate de `ikoma.dgafrique.com`
et n'installe pas de déploiement automatique.
