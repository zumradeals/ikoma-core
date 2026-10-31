# IKOMA ACCESS — Projet de contrat de licence standard de 12 mois

**Référence : ACCESS-LICENCE-STANDARD-001**  
**Version : 0.1 — PROJET À VALIDER ; NON SIGNABLE EN L'ÉTAT**  
**Nature : cadre contractuel de conception (aucune activation logicielle mise en œuvre ici)**  
**Partie déjà ratifiée : nomenclature F01–F09 et durée de 12 mois.**  
**Autres clauses : propositions à arbitrer, non encore approuvées.**

> Ce projet n'établit pas à lui seul une licence opposable ou applicable à un
> client. Il exige la validation du titulaire des droits, les décisions
> commerciales manquantes et la revue d'un juriste compétent avant signature.
> Les règles de sécurité et les obligations légales prévalent sur les exemples
> de rédaction contenus ici.

## A. Identification et périmètre des parties — À COMPLÉTER

**Concédant pressenti :** IKOMA GROUP SARL — **qualité de titulaire des droits
sur IKOMA ACCESS à confirmer**.  
Adresse, immatriculation, représentant habilité : **À COMPLÉTER**.  
**Client licencié :** personne morale ou organisation identifiée au bon de
licence — **À COMPLÉTER**.  
Désignation de l'installation, établissement(s), site(s), personnes habilitées
et catégories d'utilisateurs : **À DÉCIDER ET À COMPLÉTER**.

**Réserve juridique prioritaire :** le dépôt déclare actuellement
`"license": "MIT"` dans `composer.json`. Il faut auditer les droits des
contributeurs, les accords de cession ou de contribution, les mentions
de licence et les composants tiers. La présente proposition de licence
commerciale ne doit pas être présentée comme une révocation de droits
déjà accordés sous une licence ouverte, ni comme preuve de propriété
exclusive. Ne pas changer `composer.json` ou publier une licence
propriétaire sans résolution expresse de ce point.

## Article 1 — Objet

Le contrat a pour objet de concéder au Client, pour la durée définie à
l'article 3, un **droit d'utilisation du logiciel IKOMA ACCESS —
Édition Standard**, comprenant les neuf modules fondateurs
**F01 à F09** du document normatif
[Catalogue des modules fondateurs](MODULES_FONDATEURS.md).

Il s'agit d'un droit d'utilisation ; l'installation, l'hébergement,
le matériel, la configuration, la migration de données, la maintenance
et l'assistance ne sont pas présumés inclus financièrement par la seule
liste des modules. Leurs modalités doivent figurer dans une annexe
contractuelle identifiée et acceptée.

## Article 2 — Intégrité de l'Édition Standard — RATIFIÉ SUR LE PRINCIPE

La licence standard **donne droit à l'ensemble indivisible de F01–F09**.
Le Concédant ne peut ni désactiver individuellement un module fondateur
pour des raisons commerciales, ni imposer un paiement complémentaire
pour disposer de F01 à F09 pendant une licence valide.

L'accès effectif d'un compte dépend toutefois de ses habilitations,
du contexte événementiel, des procédures de sécurité et des
configurations d'exploitation. Cela n'affecte pas le droit du
Client au socle complet.

**Cas spécifique F06 — Groupes et cars :** le Client dispose de F06
sans supplément mais peut, par son administrateur dûment autorisé,
en activer ou désactiver **l'utilisation opérationnelle**. La
désactivation volontaire n'enlève pas F06 de la licence, ne modifie
pas sa durée, ne crée pas de facturation et ne détruit pas ses données.

Le mode initial de toute **nouvelle installation** est « F06 inactif »,
par décision du dirigeant du 31 octobre 2026. L'administrateur peut
l'activer sans souscription supplémentaire. Une installation existante
comportant déjà des groupes conserve le mode actif lors de sa
migration de compatibilité ; une nouvelle installation démarre
invariablement avec F06 inactif. L'interface et les contrôles
métier sont développés dans la PR documentaire devenue PR
de réalisation ; la disponibilité commerciale reste conditionnée
à la recette de déploiement.

## Article 3 — Durée de la licence — PRINCIPLE RATIFIÉ / DÉTAILS À DÉCIDER

**Durée convenue : douze (12) mois consécutifs.**

**Point de départ : À DÉCIDER** parmi des événements vérifiables
(date de signature, date de mise en service/réception, date d'activation
ou autre événement contractuellement défini). Une date technique
non prévue au contrat ne doit pas décaler silencieusement l'échéance.

Chaque bon de licence doit indiquer :
- l'identité du Client et l'identifiant de licence/installation ;
- la date de prise d'effet, la date de fin et la durée de 12 mois ;
- le périmètre autorisé (sites, installations, utilisateurs,
  éventuellement plafond de postes/appareils ou événements) **après décision** ;
- les services annexes effectivement souscrits, leurs responsabilités
  et leurs dates propres.

Le renouvellement (manuel, proposition, préavis, tarifs, période de grâce)
est **À DÉCIDER**. Aucun renouvellement automatique ni facturation
automatique n'est implicite dans ce projet.

## Article 4 — Limites d'utilisation — À DÉCIDER

La licence est destinée à l'utilisation interne de l'organisation
identifiée, sur le périmètre consenti. La question du caractère
non exclusif, non transférable, de la sous-licence, de l'usage multi-site,
du nombre d'installations et d'une éventuelle exploitation pour le
compte de tiers doit être expressément tranchée.

Aucune limite chiffrée de visiteurs, d'événements, de comptes,
de tablettes, de stockage ou de sites **n'a été approuvée** :
ne pas en inventer par défaut dans le code ou les offres.

Les rôles, les niveaux d'accès et les procédures de vérification
continuent à s'appliquer à chaque utilisateur autorisé.

## Article 5 — Remise, installation et réception — À DÉCIDER

Le bon de commande et le procès-verbal de réception, s'il est retenu,
préciseront la version livrée, les environnements de recette et
de production, les éléments matériels, les conditions de mise en service,
les sauvegardes initiales, les personnes formées, ainsi que les
critères d'acceptation.

Les trois scénarios du laboratoire de démonstration sont des **outils
de test** et ne définissent pas le périmètre d'une installation
cliente. Une démonstration n'est pas une réception de production.

## Article 6 — Modules fondateurs et évolution du produit — RATIFIÉ / À CADRER

Le catalogue F01–F09 constitue un **minimum contractuel de droits
fonctionnels** durant une licence valide.

Les correctifs, mises à jour de sécurité et évolutions compatibles
doivent respecter les frontières définies dans ce catalogue.
Une modification de l'interface, de la structure du code ou de la
navigation ne doit pas retirer une capacité fondatrice par
simple changement de nom.

Aucun autre module, qu'il soit gratuit ou payant, **n'est identifié,
prévu ni promis à ce stade**. Toute future extension fera l'objet
d'une décision de périmètre autonome, avec une évaluation de
compatibilité et de ses effets sur les engagements existants.

Le rythme des versions, les obligations de maintenance corrective,
les fenêtres d'intervention et les exclusions sont **À DÉCIDER** ;
ils ne sont pas automatiquement couverts par la seule durée de licence.

## Article 7 — Sécurité et continuité — À DÉCIDER

L'authentification, les habilitations, les validations serveur,
la traçabilité et l'intégrité des données font partie des
propriétés fondamentales du logiciel.

Pour les postes déconnectés, l'état local peut différer temporairement
de celui du serveur. Les responsabilités de synchronisation,
de remise physique des badges et de gestion des opérations en attente
doivent être décrites dans les procédures d'exploitation.

Un changement d'état opérationnel de F06 ne doit jamais entraîner
l'effacement silencieux d'une file locale, d'un mouvement en cours,
d'un badge ou du journal d'audit.

Les engagements éventuels de disponibilité, de délai de rétablissement,
de support, de niveau de sécurité et d'assistance aux incidents
sont **À DÉCIDER**, selon l'hébergement et les services effectivement
souscrits. Le présent projet n'annonce aucune disponibilité garantie
ou SLA chiffré sans fondement.

## Article 8 — Données du Client et confidentialité — À DÉCIDER

Les données métiers, listes de personnes, photographies, journaux,
exports et sauvegardes doivent avoir des responsabilités de
traitement et de conservation déterminées contractuellement.

À documenter avec le Client et son conseil : finalités,
responsable du traitement, sous-traitant éventuel, base de licéité,
information des personnes, catégories de données sensibles,
autorisations, stockage, conservation, droits des personnes,
transferts transfrontaliers, notification des incidents et
modalités de suppression.

**La fin de licence et la désactivation de F06 ne déclenchent
aucune destruction automatique arbitraire de données.**
Les durées légales, la politique de conservation approuvée et
les procédures sécurisées demeurent applicables.

Les dispositions détaillées seront validées au regard du droit applicable
à l'organisation et au territoire d'exploitation ; aucune conformité
juridique n'est présumée acquise par ce projet.

## Article 9 — Hébergement, sauvegardes et restitution — À DÉCIDER

Indiquer séparément qui fournit et administre :
l'infrastructure, les noms de domaine, les certificats,
les équipements, les sauvegardes chiffrées, les clés de
restauration, l'assistance et le plan de continuité.

Définir dans une annexe les obligations de sauvegarde et de
restauration, la fréquence, les vérifications, les coûts,
les limites de garantie, et les conditions d'export/restauration.

À l'expiration ou à la résiliation, établir une procédure de
**récupération ou restitution des données**, son format, le délai
raisonnable et les modalités éventuelles d'accès restreint.
L'absence de renouvellement ne doit pas entraîner la perte
immédiate et irréversible de données ou de preuves d'audit.

## Article 10 — Expiration, suspension et résiliation — À DÉCIDER

**Différencier impérativement** :
- la **fin de la période contractuelle** de 12 mois ;
- la **désactivation opérationnelle de F06** par le Client ;
- les éventuelles restrictions temporaires justifiées par la
  sécurité, la loi ou un manquement contractuel clairement défini.

La politique à l'échéance (grâce, accès en lecture seule,
export autonome, maintien des opérations d'urgence,
régularisation, suspension/arrêt progressif) est
**À DÉCIDER** avant création d'un mécanisme de verrouillage
de licence.

Toute mesure applicable doit être traçable, proportionnée et
ne doit jamais masquer une destruction de données.
Aucun mécanisme d'extinction distante, d'effacement des tablettes
ou de blocage de secours n'est autorisé par ce projet.

## Article 11 — Propriété intellectuelle — RÉSERVE À LEVER

Les droits patrimoniaux sur le code et les éventuels accords
entre fondateurs, collaborateurs, prestataires et partenaires
doivent être vérifiés avant de désigner le Concédant
comme propriétaire exclusif.

Les bibliothèques tierces et composants open source restent
soumis à leurs licences respectives. Les licences déjà accordées
et les obligations de mention ou de redistribution doivent
être respectées. Le traitement du marqueur MIT du dépôt constitue
**un préalable bloquant à toute affirmation juridique non vérifiée**.

L'usage du nom, des marques et des éléments graphiques IKOMA ACCESS
doit faire l'objet de clauses spécifiques après vérification des droits.

## Article 12 — Prix et paiement — À DÉCIDER

Le prix de la licence standard, la monnaie de facturation,
les échéances, taxes éventuelles, modalités de paiement,
frais d'installation, de maintenance, d'hébergement,
de formation et de support sont **À DÉCIDER**.

Les modules F01 à F09 ne peuvent être ventilés en options
payantes distinctes pour les clients ayant souscrit
l'Édition Standard.

## Article 13 — Responsabilité, garanties et assistance — À DÉCIDER

Préciser les obligations de chacun, le traitement des
défaillances, les garanties applicables, les exclusions
légitimes, les plafonds de responsabilité s'ils sont permis
par le droit applicable, les délais de support et
les mécanismes de signalement et de correction.

Aucune clause de limitation n'est tenue pour validée ou
opposable avant examen juridique.

## Article 14 — Droit applicable et résolution des différends — À DÉCIDER

Le droit applicable, les mécanismes amiables, la juridiction
ou le mode de règlement des litiges seront établis selon
les parties, le territoire et les exigences légales.
Aucune compétence ou clause d'arbitrage n'est choisie
automatiquement ici.

## Article 15 — Hiérarchie documentaire et modification

Proposition de hiérarchie à approuver :

1. Acte/bon de licence signé et annexes identifiées ;
2. Conditions particulières de déploiement/exploitation
   et de traitement des données ;
3. Présent contrat de licence standard, après validation ;
4. Catalogue ratifié F01–F09 et spécifications d'acceptation ;
5. Documentation d'utilisation non contractuelle.

Une annexe commerciale ne peut pas retirer un module
fondateur durant une licence valide sans nouvel accord
explicite compatible avec les engagements existants.

Un changement ne devient pas accepté par sa simple
introduction dans le code ou sa présence dans une interface.

---

## Annexes envisagées (aucune n'est approuvée ici)

- A — Catalogue ratifié [F01–F09](MODULES_FONDATEURS.md).
- B — Bon de licence : client, installation, dates, périmètre, montant.
- C — Hébergement, maintenance, sauvegarde et assistance.
- D — Protection des données et réversibilité.

## Registre des décisions à prendre avant signature

| Sujet | État |
|---|---|
| Neuf modules fondateurs F01–F09 | **RATIFIÉ** |
| Durée de licence standard : 12 mois | **RATIFIÉ** |
| F06 inclus, activable/désactivable par le Client sans supplément | **RATIFIÉ**, interface et contrôles en cours de validation CI |
| Périmètre commercial (sites, comptes, tablettes, événements) | À décider |
| Début du délai de 12 mois et règles de renouvellement | À décider |
| Expiration, grâce, lecture seule, export et réversibilité | À décider |
| Prix, paiement, services inclus et assistance | À décider |
| Hébergement et responsabilités des sauvegardes | À décider |
| Droit sur le logiciel et marqueur MIT du code | **À AUDITER EN PRIORITÉ** |
| Traitement de données personnelles et obligations légales | À valider juridiquement |
| Droit applicable, responsabilité et litiges | À valider juridiquement |
| État initial de F06 pour les nouvelles installations | **RATIFIÉ : désactivé par défaut** |
| Catalogue de futures extensions | **Aucune décision — hors périmètre** |

**Interdiction de traitement comme licence définitive :** tant que ces
sujets restent ouverts, ce document est une **base de négociation et
de conception**, pas des conditions générales publiées ou un moteur
de licence à implémenter.
