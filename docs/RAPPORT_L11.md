# Rapport L11 — durcissement et conformité

## Fait dans la branche de travail

CSP et en-têtes, politique caméra par page, cookies HTTPS et versions masquées ; refus d’accès dédoublonné, IP/empreinte hachée sur échecs, blocages progressifs et quotas sync/recherche/export ; réinitialisation TOTP par autre administrateur en deux temps et bris de glace CLI ; fermeture des sessions et jetons persistants ; contrôle de séquences clonées et révocation ; photos reconstruites sans métadonnées avant chiffrement ; export journal borné, daté, neutralisé, sans noms ni détails libres ; ancrage RSA externe et vérification par blocs ; politique de conservation proposée non approuvée, commandes privilégiées distinctes avec reçu signé pour le journal.

Migration additive, pas de table nouvelle ni de permission ajoutée. Aucun élargissement des droits SQL applicatifs. Documents : REVUE_SECURITE.md, CONSERVATION_DONNEES.md, EXPLOITATION.md, schéma et journal.

## Pas fait et écarts

Aucune conclusion juridique, aucun pentest indépendant ni déploiement production. Rétention des sauvegardes, exports sortis, impressions et gestion OS des appareils restent à finaliser. Un clone coordonné sans collision de séquence n’est pas détecté. Exception CSP limitée aux styles d’attributs, décrite dans la revue. La purge ne garantit pas une anonymisation irréversible du reliquat statistique. Les analyses CI et tests PostgreSQL/Playwright doivent encore être exécutés avant de déclarer le lot validable.

## Vérifications

Local : Pint, Larastan niveau 6, ESLint, vue-tsc, 91 tests Vue et build Vite ont passé. Aucun PostgreSQL ni Chromium local revendiqué. Recette CI à exécuter : make install, make up, make lint, make migrate-fresh-seed (<120 s), make test deux fois, make test-front, make e2e ; audits Composer/npm, Gitleaks, Trivy et ZAP baseline. Résultats et éventuelles exceptions seront ajoutés après exécution.

## Décisions à prendre par le client / risques résiduels

Politique de conservation, responsables/finalités/information/droits/formalités à vérifier par juriste ; conservation des comptes, liens statistiques et copies ; gestion d’incident et gel de preuve ; conservation des ancrages et garde de clé privée hors serveur web ; organisation du bris de glace et second contrôle humain. Voir tableau des menaces dans REVUE_SECURITE.md, sans promesse de risque nul.
