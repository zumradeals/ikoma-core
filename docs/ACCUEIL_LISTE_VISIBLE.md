# IKOMA ACCESS — Visibilité de la liste d'accueil (contrat V1)

## Objectif utilisateur

L'agent d'accueil n'a pas à deviner un nom pour commencer : les dix premières
personnes de **l'événement auquel son poste est enrôlé** sont visibles
immédiatement à l'étape 1 sur 7. À partir de deux caractères saisis, la recherche
existante parcourt l'ensemble des personnes de ce même événement.

Le nombre de personnes affiché sur le poste doit correspondre à sa dernière
copie synchronisée (ou au contexte courant en accès serveur). Le décompte
ne concerne pas l'ensemble des événements de l'installation.

## Règles invariantes

- **Sans saisie** : maximum 10 personnes, ordre alphabétique, sélection d'une
  fiche possible sans recherche préalable.
- **Un seul caractère** : conserve l'aperçu des 10 premiers ; ne lance
  aucune recherche nominale.
- **Deux caractères ou plus** : recherche tolérante aux accents et homonymes,
  maximum 10 résultats, sans changer les droits d'accès.
- **Effacement du champ** : l'aperçu initial revient immédiatement.
- **Réponses asynchrones** : une réponse tardive ne remplace pas une
  recherche plus récente et la liste précédente n'est pas sélectionnable
  pendant le chargement.
- **Hors ligne** : mêmes règles depuis la liste chiffrée de l'appareil.
- **Isolement** : filtrage obligatoire par `event_id` du poste, jamais par
  événement choisi arbitrairement depuis l'écran agent. Les fiches retirées
  ne font pas partie des résultats.
- **Synchronisation** : l'aperçu et le nombre de personnes sont rechargés
  après actualisation de la copie.
- **Administration** : sélectionner un événement puis consulter ses personnes
  par pages de 20 sans recherche préalable ; chaque sélection conduit à la
  fiche sans exposer d'autres événements.

## Parcours de démonstration

1. Générer un scénario 100 personnes dans le laboratoire.
2. Ouvrir `Administration > Liste d'invités`, sélectionner l'événement
   correspondant : compteur 100 et navigation sur cinq pages.
3. Ouvrir `Administration > Postes`, choisir le poste du scénario DÉMO,
   délivrer un code d'enrôlement, et enrôler une tablette de test distincte.
4. Déverrouiller le poste, vérifier les 10 premiers sans saisie ; saisir
   `pe` puis effacer et vérifier la restitution du premier aperçu.
5. Vérifier que l'événement précédent (`seminaire`) ne traverse jamais
   l'isolement des données.
6. Refaire le contrôle sans connexion réseau après synchronisation.

Cette évolution n'ajoute ni privilège d'administration aux agents,
ni transfert de données entre événements, ni suppression de données.
Elle ne fusionne pas IKOMA POSTE (RH) avec le poste d'accueil d'IKOMA ACCESS.
