# IKOMA ACCESS — Décision d'authentification : 2FA désactivée

**Décision explicite du propriétaire, 1er novembre 2026 : désactiver immédiatement la double authentification sur IKOMA ACCESS pour cette version.** Pas de réglage conditionnel ni de variable d'environnement.

## Contrat technique
- `config/fortify.php` ne déclare plus la fonctionnalité d'authentification à deux facteurs.
- La connexion passe par email et mot de passe, sans écran OTP, y compris pour les administrateurs et superviseurs et y compris si un ancien secret TOTP subsiste.
- Aucun rôle ne redirige vers une activation 2FA obligatoire.
- Les routes de configuration Fortify et de réinitialisation 2FA ne sont plus exposées. Les écrans concernés affichent simplement le statut désactivé.
- Les rôles/permissions, la vérification du mot de passe, la gestion des comptes désactivés, les limitations de tentatives et les journaux de sécurité restent inchangés.
- **Pas de suppression des secrets 2FA historiques** : cela évite une mutation destructrice des comptes sans rapport avec la connexion. Ces données ne sont plus utilisées lors de l'authentification.
- Aucun changement de base ou de volume PostgreSQL. L'adoption S0.7 reste inchangée.

## Risque accepté et retour ultérieur
Sans 2FA, la compromission du mot de passe suffit à permettre une tentative d'accès à un compte privilégié. Le client a demandé cette simplification pour le moment. Une éventuelle réactivation sera une nouvelle décision, un changement dans Git et une validation des parcours TOTP ; **aucun basculement caché en production**.

## Vérification
Le scénario de recette doit prouver : connexion administrateur et superviseur sans OTP, contrôle du mot de passe, refus des comptes inactifs, préservation des droits, absence des routes de challenge/activation et réussite des E2E.
