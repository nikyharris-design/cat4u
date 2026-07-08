#!/bin/sh
set -e

APP_DIR=/var/www/html/cat4u
cd "$APP_DIR"

# Primo avvio: se manca vendor/, installa le dipendenze PHP.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ mancante — eseguo composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Cartelle scrivibili (ignora errori sui bind mount Windows).
mkdir -p logs uploads
chown -R www-data:www-data logs uploads 2>/dev/null || true

echo "[entrypoint] Avvio Apache..."
exec apache2-foreground