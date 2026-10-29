# MISSION WORK — IKOMA ACCUEIL
## Refonte UX/UI de l'espace Agent d'accueil V2 — Ordinateur, tablette et téléphone

### 1. CONTEXTE

Work,

Nous poursuivons la modernisation d'IKOMA Accueil après la refonte des espaces Administrateur et Superviseur.

Je valide maintenant la refonte visuelle de l'espace **Agent d'accueil**, conformément aux maquettes responsive jointes à cette mission.

**Dépôt GitHub :** `zumradeals/ikoma-core`
**Branche de référence :** `main`
**Application :** Laravel, Inertia, Vue 3, Tailwind
**Espace concerné :** `/espace`, `/poste` et les écrans associés à l'agent.

L'interface Administrateur et l'interface Superviseur actuellement présentes sur `main` constituent les références graphiques officielles.

Le document `IKOMA-Accueil-guide-simple.pdf` constitue une référence fonctionnelle complémentaire, notamment pour le parcours d'accueil en sept étapes.

**Objectif :** moderniser intégralement l'expérience Agent d'accueil, sans reconstruire ni modifier le moteur métier existant.

---

### 2. PRINCIPES IMPÉRATIFS

Cette mission est une refonte UX/UI, pas une refonte fonctionnelle.

Tu dois :

- Partir de l'état réel de `main`.
- Examiner les composants, routes, services et tests existants.
- Réutiliser les composants et conventions graphiques déjà établis.
- Préserver les fonctionnalités opérationnelles.
- Respecter strictement les permissions existantes.
- Préserver les mécanismes d'enrôlement, de synchronisation et de sécurité.
- Ne créer aucune nouvelle règle métier sans validation.
- Ne modifier aucune migration ni aucun contrat métier sans nécessité démontrée et autorisation préalable.

Ne transforme pas les maquettes en prétexte pour réécrire des fonctionnalités qui fonctionnent déjà.

### 3. ARCHITECTURE VISUELLE RESPONSIVE

#### A. Ordinateur

Créer un espace professionnel cohérent avec les dashboards Administrateur et Superviseur.

- En-tête IKOMA Accueil.
- Menu latéral clair.
- Zone centrale exploitant la largeur disponible.
- Cartes fonctionnelles.
- Informations sur le poste et la synchronisation.
- Navigation cohérente entre les écrans.

Éviter les interfaces mobiles artificiellement centrées dans une grande page vide.

#### B. Tablette — Format prioritaire

La tablette constitue le support principal des opérations d'accueil.

- Grands boutons tactiles.
- Textes lisibles.
- Navigation rapide.
- Formulaires adaptés à la saisie tactile.
- Caméra et lecture QR facilement accessibles.
- Indicateurs de progression visibles.
- Affichage clair des erreurs et confirmations.

En orientation paysage, privilégier un menu compact ou repliable pour maximiser la surface opérationnelle.

#### C. Téléphone

Adapter les mêmes fonctionnalités à un écran étroit.

- Navigation compacte.
- Une étape principale à la fois.
- Boutons d'action facilement accessibles.
- Aucun débordement horizontal.
- Aucun élément fonctionnel supprimé uniquement pour des raisons de taille d'écran.

Ne pas créer trois logiques métier différentes : les trois formats doivent exploiter les mêmes fonctionnalités existantes.

---

### 4. PAGE « MON ESPACE »

Remplacer l'interface actuelle, composée principalement de boutons, par un véritable espace d'accueil professionnel.

Conserver quatre fonctions :

1. **Poste d'accueil** — Accueil individuel des visiteurs.
2. **Groupes et cars** — Accueil collectif et gestion des stagiaires.
3. **Contrôle sur site** — Vérification des badges QR.
4. **Sécurité** — Fonctions autorisées à l'agent.

Présenter également, lorsque ces informations sont disponibles :

- L'agent connecté.
- Le poste affecté.
- La zone d'affectation.
- L'état d'enrôlement de la tablette.
- L'état du réseau.
- L'état de synchronisation.

Ne pas inventer de données ou d'indicateurs non disponibles dans les contrats existants.

---

### 5. PARCOURS D'ACCUEIL — SEPT ÉTAPES

Le parcours officiel doit être conservé dans son ordre fonctionnel.

**Étape 1 — Chercher le nom**

Recherche rapide du visiteur dans la liste existante.

Préserver les catégories, priorités VIP, correspondances et cas particuliers déjà gérés.

**Étape 2 — Est-ce bien cette personne ?**

Afficher les informations nécessaires pour confirmer l'identité du visiteur attendu.

**Étape 3 — Pièce d'identité + photo**

Respecter impérativement le réglage administratif existant :

`identite_piece_active`

Ce réglage est désactivé par défaut.

Lorsque l'option est désactivée :

- Afficher la case « Pièce d'identité contrôlée ».
- Permettre la photographie du visiteur.
- Ne pas exiger la photographie du document d'identité.
- Ne pas collecter ni conserver de photographie de la pièce.

Lorsque l'administrateur active l'option :

- Demander le type de pièce d'identité.
- Demander sa photographie.
- Conserver le numéro de pièce facultatif.
- Respecter les validations et protections existantes.

Cette règle est déjà présente dans le code, notamment dans `PostesController.php`, `Desk.php` et `ListSnapshot.php`.

**Ne pas recréer cette fonctionnalité. La conserver et l'intégrer fidèlement dans la nouvelle interface.**

**Étape 4 — Valider ou refuser**

Conserver les décisions, motifs et autorisations existants.

Ne pas permettre à une amélioration visuelle de contourner une validation obligatoire.

**Étape 5 — Choisir un badge**

Afficher les badges disponibles et les informations utiles.

Respecter les lots réservés, les associations existantes et les contrôles d'attribution.

**Étape 6 — Scanner le QR du badge**

Créer une interface de scan claire, avec retours visuels explicites.

Préserver les contrôles de validité et les mécanismes de prévention des doublons.

**Étape 7 — Autoriser l'entrée**

Présenter une confirmation claire de l'opération.

Afficher les informations de synthèse utiles et permettre de passer rapidement au visiteur suivant.

### Objectif ergonomique

Le guide fixe un objectif de traitement inférieur à une minute par visiteur.

La refonte doit réduire les manipulations inutiles sans supprimer les vérifications obligatoires.

---

### 6. PARCOURS EXCEPTIONNELS

Préserver les comportements existants pour :

- Visiteur VIP.
- Visiteur absent de la liste.
- Visiteur arrivant hors de ses dates autorisées.
- Refus d'accueil.
- Attente d'une décision de l'hôte.
- Escalade vers le superviseur.
- Badge indisponible, annulé ou déjà attribué.
- Erreur de lecture QR.
- Interruption du réseau.

Les exceptions doivent être présentées clairement dans l'interface, sans transformer les règles de décision.

---

### 7. GROUPES ET CARS

Moderniser l'interface existante en préservant :

- La liste des groupes.
- L'appel nominal.
- La confirmation de l'encadreur.
- La remise et le scan des badges.
- Le suivi de progression.
- La clôture du car.
- Le signalement des absents.
- Le contrôle à l'arrivée.

Préserver notamment le réglage existant `controle_stagiaires`.

---

### 8. CONTRÔLE SUR SITE

Créer une interface de contrôle QR adaptée à l'utilisation sur tablette et téléphone.

Le résultat doit être immédiatement compréhensible :

- **Vert :** badge valide et accès autorisé.
- **Rouge :** badge non valide ou accès refusé.

Ne jamais utiliser uniquement la couleur : afficher aussi un texte et un symbole explicites.

Conserver les règles de zones, de validité et de contrôle existantes.

---

### 9. SÉCURITÉ ET ENRÔLEMENT

Chaque agent dispose de sa propre tablette affectée.

Plusieurs agents peuvent travailler simultanément dans différentes zones.

Conserver :

- L'enrôlement individuel.
- Le code à usage unique.
- Le PIN à six chiffres.
- Le verrouillage automatique.
- Les données locales chiffrées.
- Les contrôles d'autorisation.
- Les journaux d'audit.
- Le fonctionnement hors ligne.
- La synchronisation au retour du réseau.
- La prévention des opérations comptabilisées plusieurs fois.

Ne pas introduire de contournement des protections pour faciliter la démonstration.

Si un mode démonstration isolé existe déjà, l'utiliser conformément à ses règles actuelles.

---

### 10. COHÉRENCE GRAPHIQUE

Reprendre les conventions graphiques validées dans les espaces Administrateur et Superviseur :

- Bleu institutionnel IKOMA.
- Orange pour les éléments d'accentuation.
- Fond clair.
- Cartes blanches.
- Bordures discrètes.
- Typographie lisible.
- Icônes homogènes.
- États de succès, d'attente, d'alerte et de refus clairement différenciés.

Les maquettes jointes constituent la référence visuelle de l'espace Agent.

Les captures des espaces Administrateur et Superviseur sur `main` constituent la référence de cohérence globale.

En cas de différence entre une maquette et une règle métier existante, **la règle métier prévaut**.

---

### 11. QUALITÉ ET TESTS

Avant toute livraison :

1. Vérifier que les sept étapes restent opérationnelles.
2. Vérifier les deux états du réglage de photographie de la pièce d'identité.
3. Vérifier les permissions des agents.
4. Vérifier les parcours exceptionnels.
5. Vérifier le fonctionnement des groupes et cars.
6. Vérifier le contrôle des badges.
7. Vérifier le comportement hors ligne.
8. Vérifier l'enrôlement et le verrouillage.
9. Vérifier les trois formats responsive.
10. Exécuter les tests existants et les contrôles de qualité pertinents.

Ne supprimer ni n'affaiblir aucun test pour obtenir une CI verte.

Ne pas confondre réussite des tests automatisés et validation visuelle des écrans.

---

### 12. LIVRAISON GIT

Créer une branche dédiée à cette refonte à partir de `main`.

Préparer une Pull Request unique, clairement limitée à l'espace Agent V2.

La PR doit préciser :

- Les écrans modifiés.
- Les composants réutilisés.
- Les éventuels composants créés.
- Les règles métier préservées.
- Les tests exécutés.
- Les résultats de CI.
- Les éventuelles limites connues.

Fournir des captures réelles de l'application pour les formats :

- Ordinateur.
- Tablette paysage et portrait.
- Téléphone.

Inclure au minimum des captures du tableau de bord Agent, des étapes 1, 3, 5, 6 et 7, ainsi que des écrans Groupes et cars, Contrôle sur site et Sécurité.

### 13. LIMITES D'AUTORISATION

**GO pour l'analyse, la conception et l'implémentation de la refonte Agent V2 dans une branche dédiée.**

Tu es autorisé à créer et mettre à jour la Pull Request.

En revanche :

- Ne fusionne pas automatiquement dans `main`.
- Ne déploie pas automatiquement.
- Ne modifie pas les données opérationnelles.
- Ne lance pas une refonte d'un autre espace.
- Ne modifie pas les règles métier sans validation explicite.

Attends mon accord après présentation des captures et des résultats.

### 14. CRITÈRES D'ACCEPTATION

La mission sera considérée comme prête à validation lorsque :

- L'espace Agent présente la même qualité graphique que les espaces Administrateur et Superviseur.
- Les trois formats responsive sont cohérents.
- Le parcours en sept étapes est préservé.
- Le réglage administratif de la pièce d'identité fonctionne dans ses deux états.
- L'enrôlement et la sécurité ne sont pas affaiblis.
- Les parcours exceptionnels restent fonctionnels.
- Les tests pertinents réussissent.
- Les captures réelles correspondent aux maquettes approuvées.

**Principe final : nous modernisons l'expérience de l'agent, pas le fonctionnement fondamental d'IKOMA Accueil.**

GO — Implémente Agent V2 et présente-moi la PR pour validation avant fusion.
