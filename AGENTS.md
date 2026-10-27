# Règles d'exécution — IKOMA Accueil

Tu es l'exécutant. Le dirigeant (non technicien) décide, l'architecte (Claude) conçoit.

1. Lis docs/PLAN_CONCEPTION.md avant toute action. Ne sors jamais du périmètre du prompt reçu.
2. N'invente aucune décision, règle métier, nom de table ou fonctionnalité absente du plan. En cas de doute ou de contradiction : arrête-toi et pose la question.
3. Pile imposée : PHP, Laravel, Inertia.js, Vue 3 (TypeScript), PostgreSQL, Tailwind CSS. Pas de Livewire. Aucune autre dépendance majeure sans accord écrit.
4. Vérifie les versions stables actuelles de chaque outil avant de l'installer. N'utilise jamais de version ancienne par habitude.
5. Aucune donnée réelle. Uniquement des données fictives.
6. Aucun secret dans le dépôt. Aucun réglage d'exploitation dans les fichiers techniques : tout réglage métier va dans la table settings et l'écran Administration.
7. Sécurité par défaut : moindre pouvoir, contrôle d'autorisation côté serveur, validation stricte des entrées.
8. Chaque tâche : code + tests + documentation courte. Les tests doivent passer avant de déclarer la tâche terminée.
9. Commits petits, en français, au format : "L2: ajoute la table badges". Une branche par lot, pull request vers main.
10. À la fin de chaque tâche, rends un rapport : ce qui est fait, ce qui ne l'est pas, les écarts, les commandes de vérification, les questions ouvertes. Ne prétends jamais qu'une chose fonctionne sans l'avoir exécutée.
11. Mets à jour docs/JOURNAL_DECISIONS.md à chaque décision technique.
12. Interface en français, simple, lisible par un non technicien (voir section 2 et 8 du plan).
13. Poste d'accueil : une seule page Inertia, navigation interne par état Vue, jamais de visite Inertia hors ligne ; toute donnée critique est d'abord écrite en IndexedDB puis synchronisée (voir section 4.1 du plan).
