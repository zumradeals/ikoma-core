# Lot B — coffre de l'espace agent sans PIN (contrat final)

Décision du propriétaire (site militaire, demande du client) : suppression totale du PIN de l'agent, « comme s'il n'avait jamais existé ». Le PIN d'évacuation du superviseur est conservé.

Conception, menaces, migration et risque résiduel : voir l'addendum « Suppression totale du PIN » de `docs/AUTH_HORS_LIGNE.md`.

## Contrat
- Aucune saisie de PIN, aucun verrou d'inactivité, aucun compteur d'essais pour l'agent ; réglages d'administration « inactivité » et « PIN » retirés de l'écran Postes.
- Secret de bail délivré par le serveur à une session authentifiée seulement ; jamais codé en dur, jamais public, clé de données jamais en clair.
- Pas de période de grâce : l'ancienne enveloppe PIN disparaît dès la migration vérifiée.
- Une file d'opérations en attente n'est jamais effacée en silence ; changement de compte avec opérations en attente = refus explicite.

## Exigences d'exploitation (hors code)
Verrouillage d'écran du système, mode kiosque ou gestion d'appareils, révocation immédiate des tablettes perdues, durée hors réseau courte.
