<?php

return [
    // Fichier contenant le jeton à usage unique de l'assistant d'initialisation. Créé par scripts/install.sh sur un serveur de production ;
    // absent partout ailleurs (développement, démonstration) : l'assistant n'existe alors pas.
    'install_token_file' => env('IKOMA_INSTALL_TOKEN_FILE'),
];
