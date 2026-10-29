# Réserves de sécurité et validation de l’atelier

La PR reste en brouillon. Aucune fusion et aucun déploiement ne sont effectués.

## Analyse des images système

La CI [173](https://github.com/zumradeals/ikoma-core/actions/runs/40975200462), sur le commit c9872b85a1146b61133d8efb5bb0ac8d8399de8a, a conservé son contrôle Trivy bloquant. Son artefact `analyses-securite` contient :

| Image | Élevées | Critiques | Lignes avec version corrigée annoncée |
| --- | ---: | ---: | ---: |
| PHP | 123 | 2 | 0 |
| PostgreSQL | 86 | 2 | 26 |
| Nginx | 0 | 0 | 0 |

Ces chiffres comptent les occurrences paquet/vulnérabilité, pas des CVE distinctes. Ils décrivent le rapport à cette date, pas une garantie sur une autre construction. Les images PHP et PostgreSQL sont celles déjà utilisées par le dépôt ; le module de badges n’introduit aucune dépendance applicative. Exemple critique détecté : libxml2, CVE-2026-6653, sans version corrigée dans ce rapport. L’image PostgreSQL présente aussi des lignes pour lesquelles une version corrigée est annoncée.

Aucune exception nouvelle, aucun filtre ni désactivation de Trivy n’est ajouté pour obtenir une CI verte. La mise à jour et la revalidation des images restent un préalable à une livraison de production. Changer de distribution ou de moteur dans cette mission ne serait pas une correction locale du générateur.

La même exécution avait aussi échoué à reconstruire les données de test (fonction de migration déjà présente), ce qui avait causé des réponses 500 à ZAP. La déclaration idempotente a été corrigée ; la reconstruction et les deux passages des tests PHP de la CI suivante sont réussis. ZAP doit rester exécuté sur cette application reconstruite, et ses résultats effectifs doivent être examinés.

## Contrats de confidentialité

- Le mode standard ne lit ni fiche titulaire ni portrait.
- Le mode nominatif exige les permissions existantes Badges et Liste d’invités, un choix explicite de mode et l’autorisation de la politique du client.
- Le portrait volontaire est distinct des photographies d’accueil et des pièces, et n’est imprimé que si son autorisation propre est présente.
- Chaque accès au portrait est authentifié et audité ; aucune donnée image n’entre dans les snapshots tablette ou un cache public.
- La préparation nominative ne valide pas l’identité et ne crée pas d’association ni d’entrée. Elle réserve une destination immuable, contrôlée au serveur et localement.
- Les QR et statuts restent gouvernés par le moteur existant. Une carte perdue doit être annulée ; ses anciennes copies papier ou PDF ne peuvent pas être effacées à distance.
- Le retour destructif de la nouvelle migration est refusé après utilisation des portraits ou des destinations nominatives.

## Réserves matérielles

Le navigateur prépare des dimensions CR80 en millimètres, pas une certification de pilote ou d’imprimante. Le scan d’un véritable tirage, l’échelle à 100 %, le papier, le sens d’insertion et les marges doivent être vérifiés avec le matériel client avant un tirage de production. L’impression physique n’est pas confirmable par l’application. Le verso est un tirage séparé.
