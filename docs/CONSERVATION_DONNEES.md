# Conservation des données — proposition L11

Source : plan sections 6/9, fonctionnement v1.0 section 11. **Aucune conclusion juridique** : le responsable du traitement et un juriste local doivent valider finalités, base applicable, information des personnes, droits, durées, éventuelles obligations de gel et sous-traitance avant toute donnée réelle.

## Politique proposée, non approuvée par défaut

| Donnée | Proposition initiale | Point de départ et traitement |
|---|---:|---|
| Identités visiteurs, organisation, hôte, motifs libres | 30 jours | Fin de l’événement ; remplacement des noms par des identifiants techniques, retrait des organisations, liens hôte et mentions libres ; révocation des postes |
| Détails événement, groupes, postes ; mouvements et contrôles d’identité | 90 jours | Fin de l’événement ; noms de configuration retirés, mouvements/contrôles supprimés par la console privilégiée après conservation d’un bilan chiffré |
| Photos | 7 jours | Fin de l’événement ; suppression du fichier privé chiffré et de sa référence |
| Réponses de synchronisation et conflits | 30 jours | Fin de l’événement, une fois les identités retirées et les postes révoqués ; suppression des réponses rejouables qui peuvent contenir des noms |
| Alertes réglées | 30 jours | Fin de l’événement ; les alertes ouvertes restent à traiter. Les alertes de conflits disparaissent avec les conflits purgés |
| Notifications | 7 jours | Fin de l’événement ; suppression, sans copie nominative conservée dans le journal |
| Journal d’audit, dont IP des échecs | 365 jours | Date de chaque ligne ; suppression d’un préfixe contigu seulement, avec reçu et point de reprise signés |

Ces valeurs ne constituent pas une politique légale validée. Administration > Conservation, permission existante `comptes.gerer`, affiche les propositions et une approbation **décochée**. Sans approbation explicite, les commandes destructives refusent de s’exécuter. Les photos, notifications et réponses de synchronisation ne peuvent être conservées plus longtemps que les identités ; le délai des détails événement doit couvrir celui des identités.

Un événement encore dans sa période ou contenant au moins une personne présente selon les mouvements n’est pas purgé. Il faut régulariser sa clôture par les procédures autorisées ; la purge ne fabrique pas une sortie. Aucun réglage de durée n’est placé dans `.env`.

## Exécution et limites

`donnees:purger` affiche les durées sans suppression. `donnees:purger --confirmer` exige le compte privilégié distinct du compte applicatif, vérifie l’approbation et exécute la maintenance sous verrous. Arrêter les admissions et synchroniser les files avant la maintenance ; les verrous suspendent les écritures concurrentes. En cas de concurrence incompatible, PostgreSQL peut annuler une transaction : la commande signale l’échec, l’exploitant recommence après arrêt des écritures. Aucun privilège des comptes applicatifs n’est élargi.

Les fichiers sont effacés avant leurs références. Si une panne survient après l’effacement physique et avant la validation SQL, une référence peut subsister sans fichier : relancer la purge la supprime. Il n’existe pas de restauration automatique d’une photo effacée. Les fichiers illégitimes hors du répertoire `photos/UUID.enc` ne sont jamais supprimés.

`audit:purger` est une opération distincte, avec clé privée RSA hors du processus web, clé publique de confiance et nouveau reçu privé. Il vérifie d’abord toute la chaîne, écrit et synchronise le reçu sur disque, ajoute l’action `journal.purge`, mémorise le point signé dans settings, puis supprime uniquement le préfixe échu. Aucun contenu ancien n’est conservé dans le reçu (numéro, empreinte, heures, nombre). Voir EXPLOITATION.md. Un reçu produit avant un échec SQL reste un justificatif de tentative, pas une preuve que la suppression a été validée.

Les lignes restantes continuent à partir de l’empreinte signée. Sans la clé publique attendue, un point de reprise n’est pas accepté. Une copie indépendante des reçus et des condensés permet de détecter une réécriture ou un retour arrière ; une clé privée compromise rend les signatures non probantes.

## Ce qui reste conservé

Les UUID, catégories, statuts, relations statistiques et bilan numérique de l’événement restent présents pour ne pas détruire les contraintes et les statistiques. Les comptes d’agents et leurs identifiants dans les journaux restent nominatifs. Cette réduction des identités **ne garantit pas une anonymisation irréversible** face à des sources externes : traiter le reliquat comme potentiellement personnel jusqu’à l’avis du juriste. L’interface n’autorise plus d’admission sur les fiches retirées et les anciens postes sont révoqués.

Les copies IndexedDB relèvent des règles L6/L8 : PIN, chiffrement, révocation au prochain contact, échéance locale. Une tablette hors réseau ne reçoit pas instantanément un ordre d’effacement. L’export chiffré avant effacement de fin d’événement reste L12. Les sauvegardes, impressions, téléchargements CSV, copies hors serveur et supports du client ne sont pas effacés par cette commande : leur inventaire, durée et destruction contrôlée doivent être définis en L12 avec le client.

## Décisions attendues du client

Responsable de traitement et source des listes ; finalité et proportionnalité des photos ; information et exercice des droits ; durées par type ; conservation des comptes, statistiques et liens techniques ; traitement d’un incident ou gel de preuve avant purge ; durées des sauvegardes/exports/papier ; hébergement et transferts éventuels ; autorisations et formalités à vérifier avec le juriste local. Aucune de ces décisions n’est présumée acquise par la livraison de L11.
