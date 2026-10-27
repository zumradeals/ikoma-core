#!/bin/sh
set -eu
if [ ! -f .env ]; then
  docker compose --env-file /dev/null run --rm --no-deps php php -r '
    $template = file_get_contents(".env.example");
    $template = str_replace("APP_KEY=", "APP_KEY=base64:".base64_encode(random_bytes(32)), $template);
    $template = str_replace("DB_PASSWORD=", "DB_PASSWORD=".bin2hex(random_bytes(24)), $template);
    file_put_contents(".env", $template);
    chgrp(".env", 33);
    chmod(".env", 0640);
  '
fi
