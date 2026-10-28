# Revue de sécurité — L11

Périmètre : application issue de L0–L10, durcissement L11. Ce document distingue les mécanismes vérifiés automatiquement des conditions d’exploitation et des risques résiduels. Les résultats de recette sont consignés dans RAPPORT_L11.md ; cette revue n’est ni un certificat de conformité, ni un test d’intrusion indépendant.

| Menace | Protection applicative | Limite et risque résiduel |
|---|---|---|
| Tablette volée | Enrôlement à usage unique, jeton haché côté serveur, coffre AES-GCM lié au PIN et à une clé non exportable, verrouillage, délais puis effacement après échecs, révocation | PIN court ; accès possible si appareil déjà déverrouillé. Hors réseau, révocation non instantanée. Mode kiosque, verrouillage OS, chiffrement du disque et gestion d’appareils du client indispensables |
| Compte volé | Mot de passe robuste, 2FA requis pour administrateur/superviseur, limitation par compte/IP et blocages progressifs, inactivité, invalidation des sessions après mutation de compte | Pas de protection totale contre hameçonnage, session active volée, malware ou utilisateur qui communique ses codes. Rôles agent/hôte/encadreur sans TOTP obligatoire conformément au contrat L1 |
| QR copié/photographié | Jeton imprévisible, statut et association uniques, contrôles en ligne prioritaires ; hachages seulement dans la copie chiffrée | Le QR est un titre au porteur copiable, pas une preuve biométrique. Un contrôle hors ligne peut utiliser une situation ancienne et ne connaît pas une annulation récente |
| Hôte malveillant | Contrôle d’objet à chaque action, catégorie Visiteur uniquement, aucune modification après entrée, décision non listée une seule fois, accueil accompagné | Un hôte autorisé peut inviter une personne indésirable ; la décision de l’hôte ne remplace jamais les contrôles physiques de l’agent |
| Agent malveillant | Moindre pouvoir, identités de compte et appareil, file idempotente, append-only SQL, audit, contrôle serveur au rejeu | Une photo et une case cochée ne prouvent pas qu’une pièce a réellement été vérifiée. Agent déverrouillé et malveillant hors ligne : accès à sa copie jusqu’à expiration/révocation connue |
| Administrateur compromis | Aucun héritage implicite de droits terrain, 2FA, réinitialisation 2FA en deux temps avec mot de passe, audit et fermeture des sessions | Le rôle gère des comptes et peut créer un autre administrateur : séparation organisationnelle, supervision externe et contrôle des interventions restent nécessaires. La confirmation UI n’est pas un second décideur humain |
| Fuite de base | Photos chiffrées hors base, jetons d’appareil hachés, droits SQL limités, purge et réduction des identités à échéance | Les identités actives sont lisibles dans la base ; les jetons QR imprimables y existent. Chiffrement disque/sauvegardes et séparation des secrets restent L12. Compromission de DB + APP_KEY permet de lire les photos |
| Altération du journal | Application SELECT/INSERT seulement ; chaînage, verrou de série, audit:verify, condensés signés et reçus de purge hors serveur | Un propriétaire de base peut réécrire toute la chaîne. Sans ancrage externe indépendant, cela n’est pas détectable. Vol de la clé privée de signature ou substitution de la clé publique de confiance compromettent cette preuve |
| Clone d’appareil | Verrou de poste et séquence haute ; renvoi identique idempotent, nouvelle opération de séquence ancienne : révocation et alerte critique | Détecte des séquences incompatibles, pas un clone coordonné qui ne crée aucune collision. Copie du jeton et compromission du navigateur actif restent critiques |
| Injection script/formule | CSP script-src self, pas d’eval ni script en ligne, échappement Vue, nettoyage L3, export CSV neutralisé, pas de contenu libre dans l’export du journal | Un script malveillant chargé depuis la même origine ou une chaîne de dépendances compromise reste possible. Audit automatique et revue humaine se complètent |
| Données périmées ou purge prématurée | Durées proposées non approuvées initialement, commande privilégiée confirmée, refus si présence restante, bilan chiffré, conservation des liens statistiques | UUID et historiques peuvent permettre une réidentification externe. Sauvegardes, impressions et exports déjà téléchargés restent à gérer. Aucun avis juridique n’est présumé |

## Politique de navigateur et photos

Les scripts sont de l’origine uniquement. Les workers sont locaux ; les données authentifiées ne vont pas dans le cache HTTP/PWA. Les coquilles publiques des quatre applications ne partagent aucun nom de compte, même si une session existe.

**Exception CSP précise** : `style-src-attr 'unsafe-inline'` est conservé pour les couleurs de catégories et progressions Vue dynamiques. `style-src 'self'` reste appliqué aux feuilles ; aucun `unsafe-inline` dans script-src, aucun unsafe-eval. L’exception augmente la surface d’injection de style mais n’autorise pas de JavaScript ; les valeurs métier de couleur sont déjà validées côté serveur. Supprimer cette exception demanderait de remplacer tous les styles dynamiques et de tester les mises en page de tous les lots.

Caméra limitée aux pages de poste, contrôle et groupes (et leurs coquilles précachées) ; microphone, géolocalisation, paiement et USB interdits. Les politiques du système/navigateur restent nécessaires. HTTPS est obligatoire hors développement : HSTS n’est émis que sur HTTPS ; les cookies sont alors Secure. Aucun HSTS sur le HTTP de recette ne transforme ce HTTP en canal sécurisé.

Les photos sont bornées à 1 Mo et 640 px, validées par GD natif de PHP ; les JPEG porteurs de segments de métadonnées, de commentaires libres ou de données ajoutées sont réencodés avant chiffrement Laravel, sans EXIF/IPTC/contenu ajouté. Une signature technique GD de forme fixe peut subsister ; les JPEG déjà propres sont conservés identiques pour le contrat d’intégrité L5. Leur chemin UUID reste privé et n’a aucune route de consultation. La clé APP_KEY ne doit jamais être stockée avec une sauvegarde en clair. Un transfert SQL annulé après écriture peut laisser un fichier chiffré orphelin ; l’inventaire et le nettoyage contrôlé du stockage doivent être intégrés à l’exploitation L12.

## Analyses automatiques et portée

Composer/npm : dépendances verrouillées, seuil bloquant élevé/critique. Gitleaks : historique Git complet, sorties expurgées des valeurs. Trivy : images réellement démarrées PHP, Nginx et PostgreSQL, seuil élevé/critique sans ignorance générale des vulnérabilités non corrigées. ZAP baseline : exploration et analyse passive du serveur de recette ; **ne remplace pas un pentest**, et ne couvre pas les pages authentifiées par TOTP. Ces droits et parcours sont exercés par Pest et Playwright.

Les outils sont exclusivement exécutés en CI/local, sans dépendance applicative majeure ajoutée. Le réseau de la CI sert à récupérer outils et bases de vulnérabilités ; aucun appel externe nouveau de l’application. Les rapports portent sur des données fictives. La disponibilité de ces services et la fraîcheur de leurs bases peuvent bloquer la CI : aucun échec de téléchargement n’est assimilé à une analyse réussie.

### Exceptions observées

Les exceptions examinées sont listées ci-dessous. Toute alerte supplémentaire doit être examinée et documentée avec règle précise, contexte, justification et risque ; le contrôleur ZAP bloque toute alerte non justifiée. L’exception CSP de style décrite ci-dessus est une décision explicite, pas une désactivation globale de l’analyse.

## Conditions avant mise en service

Faire valider la politique de données et le cadre applicable par le client/juriste, finaliser hébergement dédié et HTTPS, restrictions proxy, secrets/clefs et sauvegardes avec restauration, matériel dédié, procédure d’incident, double contrôle des consoles, puis faire réaliser une revue/pentest indépendant et une répétition complète. Le serveur de développement ne constitue pas l’environnement de production durci.

### Examen de la baseline du 28 octobre 2026

Source : exécution CI 40678326327, rapport ZAP de l’artefact 12812565036, cible fictive HTTP localhost. Les en-têtes d’isolation d’origine manquants (90004-1/2/3) sont ajoutés à PHP et aux ressources statiques ; Permissions-Policy sur le JavaScript compilé (10063-1) est ajouté à Nginx. Ces constats sont corrigés, pas ignorés.

Exceptions vérifiées par règle, niveau **et** instances dans `scripts/security/zap-gate.py` :

| Règle | Contexte accepté | Justification / limite |
|---|---|---|
| 10010, faible | Cookie XSRF-TOKEN uniquement, évidence exacte Set-Cookie | Le client Inertia doit lire le jeton pour l’en-tête CSRF. Le cookie de session reste HttpOnly. L’exception ne couvre aucun autre cookie ; un XSS actif reste un risque traité par CSP et échappement |
| 10109, information | Page racine contenant le module JavaScript | Identifie une application moderne ; rappelle la limite de l’exploration passive sans authentification. Les parcours authentifiés sont testés par Playwright |
| 10112, information | Cookie laravel-session reconnu sur la page racine | Détection de mécanisme de session, pas exposition de sa valeur. Aucune exception sur la sécurité du cookie |
| 10049-1, information | Racine privée et sitemap.xml absent avec no-store | L’absence de cache est attendue pour les réponses de session/erreur ; aucun écran authentifié ne doit être mis en cache |
| 10049-3, information | Ressources publiques /build/ et robots.txt | Ressources sans identité destinées au navigateur/PWA ; exception non applicable aux routes de données |

Tout nouveau cas, autre cookie, autre niveau ou autre périmètre bloque. Les alertes ne disparaissent pas du rapport. La disponibilité des bases de vulnérabilités n’est jamais contournée. L’audit npm de cette exécution ne contient que des constats faibles dans la chaîne de build Workbox/deepmerge (dont GHSA-gcqx-w48m-w2h4) ; pas de failles élevées/critiques. Ils restent dans le rapport, sous le seuil explicite demandé, à suivre lors des mises à jour compatibles. Aucun rétrogradage majeur de vite-plugin-pwa n’est appliqué automatiquement.
