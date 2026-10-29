# Atelier de badges IKOMA ACCESS

L’atelier se trouve dans **Administration → Badges → Modèles et impression → Ouvrir l’atelier**. Il demande les mêmes droits de gestion des badges et la double authentification que l’administration existante.

## 1. Générer un lot

Dans **Badges → Générer un lot**, choisissez l’événement et la catégorie, puis le nombre de personnes attendues. Vérifiez la marge annoncée : 200 personnes avec 10 % de marge fabriquent 220 badges. Cette opération seule crée les numéros et les QR.

## 2. Choisir un modèle

Ouvrez l’atelier. Choisissez l’événement et le lot, puis **Visiteur**, **VIP** ou **Prestataire / Stagiaire**. La mention STAGIAIRE apparaît lorsque la catégorie du badge contient « stagiaire ». La catégorie réelle reste affichée ; le choix graphique ne modifie jamais les droits.

Le titre du site et les couleurs sont personnalisables. Seul le logo IKOMA ACCESS fourni avec le produit est utilisé dans cette livraison. Aucun logo client, insigne ou sceau d’autorité n’est inventé. **Enregistrer pour cet événement** conserve les options visuelles dans les réglages de cet événement ; cela ne modifie aucun badge.

## 3. Visualiser les badges

Saisissez le premier et le dernier numéro, puis **Actualiser l’aperçu**. Le premier badge réel de la sélection apparaît, avec le nombre de badges et de pages. Après chaque modification, actualisez l’aperçu avant de préparer l’impression. Pour un seul badge, indiquez le même numéro dans les deux champs.

L’aperçu et l’impression utilisent la même vue et la même feuille de style. Les dimensions physiques sont fixes ; le zoom de l’écran ne constitue pas une mesure du tirage.

## 4. Imprimer 200 badges

Choisissez **Tout le lot**, ou une plage de 200 numéros inclusifs (par exemple 401 à 600). Si le lot comporte une marge, ne confondez pas personnes attendues et badges fabriqués. Le récapitulatif indique le nombre réellement trouvé.

- **PVC CR80** : un badge par page, 85,60 × 53,98 mm, en paysage ou en portrait. Choisissez une imprimante PVC et un pilote adaptés. Le navigateur prépare le format mais ne contrôle pas le matériel.
- **Planche A4** : en paysage, 2 colonnes × 5 lignes donnent 10 badges par page, donc 20 pages pour 200 badges. En portrait, jusqu’à 3 × 3 badges par page. Réduisez les lignes ou les colonnes pour des planches partielles. Les combinaisons trop grandes sont refusées, sans réduction automatique.

Cliquez sur **Ouvrir la page d’impression**, puis sur **Imprimer les badges**. Réglez l’échelle sur **100 % / taille réelle**, sans en-têtes ni pieds de page, avec les arrière-plans activés. Mesurez un badge d’essai avant le lot entier. Les marges physiques du pilote peuvent nécessiter une calibration ; les éléments utiles restent à au moins 3 mm du bord.

Le verso est une impression séparée de consignes génériques. Faites un essai pour le sens de réinsertion et la concordance des feuilles ; aucune gestion automatique du duplex n’est promise.

Les étiquettes A4 historiques restent accessibles via **Lots et étiquettes**. Maximum de l’atelier : 1 000 badges par préparation ; fractionnez les lots plus grands.

## 5. Tester un QR

Scannez le badge d’essai depuis le contrôle IKOMA ACCESS sur un poste autorisé. Un badge disponible mais non associé doit être refusé : décoder un QR ne prouve pas qu’il ouvre un accès. Associez un badge de test selon le parcours d’accueil normal, puis vérifiez le résultat dans une zone permise et une zone interdite. Après restitution ou annulation, vérifiez le refus prévu par les règles existantes. Pour le hors ligne, synchronisez la tablette selon la procédure habituelle ; une révocation ne peut être connue par une tablette déconnectée qu’après actualisation de ses données.

La recette automatique compare les SVG anciens et nouveaux, décode leurs pixels à 300 dpi et vérifie le parcours de contrôle en PHP. Le scan d’un véritable tirage, la calibration du pilote PVC et la tenue du support restent à tester avec le matériel client.

## 6. Réimprimer sans changer l’identifiant

Sélectionnez le même événement, le même lot et le numéro souhaité, actualisez l’aperçu et imprimez à nouveau. Le QR, le numéro, le statut, les réservations et l’historique restent identiques. Une réimpression n’annule pas les copies précédentes : signalez une perte au superviseur pour appliquer l’annulation existante.

## Confidentialité et sécurité

Aucun nom, photo, période personnelle, service visité ou zone n’est chargé depuis une fiche titulaire par cet atelier. Le mode nominatif facultatif n’est pas implémenté ni activable dans cette livraison : il nécessite un contrat distinct de permissions, de confidentialité et de conservation. Les titres et catégories sont échappés côté serveur ; les couleurs acceptent uniquement des valeurs hexadécimales. Le QR conserve le jeton opaque existant, sans donnée personnelle ni page publique d’identification.

Les pages contenant les badges ne sont pas mises en cache. Les jetons sont présents dans les QR : conservez les impressions et éventuels PDF dans un endroit sûr. Les captures de recette utilisent uniquement des données fictives. Le journal trace l’enregistrement d’un modèle et la préparation d’une impression, sans jeton ; il ne prétend pas confirmer la sortie physique du papier.

## Vérification reproductible

Sur l’environnement de développement Docker existant : `make lint`, `make test`, `make test-front`, `make e2e`. Le scénario `tests/e2e/badge-workshop.spec.ts` produit dans `test-results/` les captures des trois modèles, de l’atelier ordinateur et tablette, ainsi que les PDF CR80 et A4 de 200 badges. Ces preuves sont incluses dans l’artefact CI `captures-l3` avec les autres recettes.
