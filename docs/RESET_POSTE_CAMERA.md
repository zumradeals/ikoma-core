# IKOMA ACCESS — Réenrôlement après remise à zéro et sélection de caméra

## Incident réel

Après une remise à zéro de la base métier sur `ikoma.dgafrique.com`, le navigateur
avait conservé l'ancien coffre chiffré `ikoma-poste`, le profil « agent »,
le poste « Accueil fictif », un PIN et un jeton de tablette. La recréation d'un
compte utilisant `agent@ikoma.com` ne restaure pas l'ancien UUID, ni le jeton du
poste supprimé. Un affichage « En ligne » ne signifie que la disponibilité du
réseau de l'appareil, pas l'authentification de son jeton.

## Contrat de sécurité du poste

- L'enrôlement est attaché au **jeton de poste** et à **l'identifiant stable du compte**,
  jamais à un email ou au libellé d'un poste.
- Si le serveur répond `appareil_non_enrole`, le navigateur doit retirer l'ancien
  coffre, la copie des visiteurs, le profil et la file locale ; afficher un écran
  d'enrôlement compréhensible, sans simuler un poste actif.
- Si l'agent authentifié côté serveur possède un autre UUID que celui du coffre,
  le PIN ne doit pas ouvrir l'ancienne copie : le navigateur doit demander le
  nouvel enrôlement. Un même email après remise à zéro n'est pas une identité.
- Si l'agent n'a pas de session HTTP disponible (usage hors ligne), le coffre
  conserve le fonctionnement local normal : ne pas déduire une révocation
  d'une simple absence de réseau ou de session.
- Un bouton de **réinitialisation locale** sur le poste verrouillé ou ouvert
  informe explicitement des opérations en attente et demande une confirmation.
  Il n'efface jamais la base de données du serveur.
- Lorsque l'administrateur supprime délibérément un ancien événement et ses
  postes, les opérations non synchronisées de ces postes sont irrécupérables.
  Cette perte doit être expliquée avant un reset métier complet.

## Prise de photo

- Étape identité : préférence pour la **caméra arrière** (`environment`).
  L'agent peut choisir la caméra avant (`user`) avec un bouton « Changer de caméra ».
- Changer de caméra ferme le flux précédent ; seules les caméras disponibles
  sur l'appareil peuvent être utilisées et le navigateur peut faire un choix
  différent si la préférence demandée n'est pas prise en charge.
- La caméra arrière est la préférence aussi pour photographier une pièce ;
  la caméra avant sert aux selfies ou aux appareils ayant une seule caméra.
- La capture QR conserve son fonctionnement actuel.

## Recette

1. Enrôler un poste dans Chrome, puis simuler un reset de base : réponse 401
   `appareil_non_enrole`. Confirmer la disparition de l'ancien poste et du profil,
   puis l'affichage de l'enrôlement.
2. Se connecter au même navigateur avec un compte recréé dont l'email est
   identique mais l'UUID différent ; confirmer le refus d'ouvrir l'ancien coffre.
3. Depuis un poste verrouillé, utiliser « Réinitialiser ce navigateur » ;
   confirmer qu'aucune donnée serveur n'est supprimée.
4. Tester la capture de photo avec la caméra arrière, basculer sur la caméra
   avant, recommencer, puis vérifier le nettoyage des flux média.
5. Vérifier le parcours de visiteur complet et la reprise de synchronisation.

**Ne déployer ni fusionner avant la validation CI.** Aucun reset du VPS n'est
réalisé par cette PR.
