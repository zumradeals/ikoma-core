# Socle visuel L0

Référence : section 8 du plan. Composants Vue maison, sans bibliothèque d'interface.

| Variable Tailwind | Valeur | Usage |
|---|---|---|
| ikoma-blue | #0F5AA6 | Action principale, texte sur fond clair |
| ikoma-navy | #0B3C6B | Texte, en-tête |
| ikoma-orange | #F26B21 | Accent et bordure, pas de petit texte blanc |
| ikoma-success | #1E9E5A | Indicateur de succès, accompagné d'un mot et d'une icône |
| ikoma-danger | #D64545 | Indicateur d'alerte, accompagné d'un mot et d'une icône |
| ikoma-gold | #C9962B | Bordure VIP, texte marine |

Police système, disponible sans réseau. Trois tailles : body (18 px), heading (24 px), display (32 px). Cibles des boutons : 56 px minimum. Focus visible. Les couleurs sémantiques claires servent de bordures, le texte reste marine pour le contraste.

- BaseLayout : en-tête, contenu et pied de page.
- BaseButton : bouton principal ; désactivation native et raison affichée.
- BaseCard : regroupement visuel avec contenu fourni par un slot.
- CategoryLabel : symbole obligatoire + libellé, tons standard et VIP ; aucune catégorie métier créée.
- StatusBanner : message annoncé par role=status, accompagné du symbole information.

La page d'accueil publique (`/`) ne contient aucune donnée ni aucune action métier : elle présente l'application et renvoie vers la connexion et les espaces par rôle (chaque lien exige une session). Le cache PWA ne concerne que cette page publique, les coquilles publiques du poste, du contrôle et de l'évacuation, et les ressources compilées. Ne jamais l'étendre à des réponses authentifiées.

## Formulaires L1

Champs de 56 px minimum et labels explicites. FormButton conserve la soumission native des formulaires. Les identifiants de rôles et d’actions restent dans le contrat ; leurs libellés sont présentés en français dans l’interface. Dates du journal formatées en français. Aucun secret TOTP ni code de secours n’est inclus dans les captures automatisées.

## Poste d'accueil L5

Boutons d'action du poste : 64 px de haut, texte de 20 px en gras. Le vert d'action est `green-700` (#15803d) et le rouge `red-700` (#b91c1c), plus sombres que les couleurs de marque pour tenir le contraste AA avec du texte blanc ; ils s'accompagnent toujours d'un symbole (✓ ✕) et d'un mot. Une action désactivée est grise et sa raison est écrite sous le bouton (`aria-describedby`). Le bandeau permanent affiche le réseau (symbole + « En ligne » ou « Hors ligne »), les opérations en attente, le poste et l'agent. La progression est annoncée en texte (« Étape 3 sur 7 ») en plus de la barre. Composants : `resources/js/components/poste/`.
