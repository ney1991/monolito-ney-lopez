#!/bin/sh
set -e

cd /var/www

# 1. Dependencias PHP (por si el volumen montado no trae vendor/)
if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Instalando dependencias con Composer..."
    composer install --no-interaction --prefer-dist
fi

# 2. Archivo de entorno y clave de aplicación
if [ ! -f .env ]; then
    echo "[entrypoint] Creando .env desde .env.example..."
    cp .env.example .env
fi
if ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then
    echo "[entrypoint] Generando APP_KEY..."
    php artisan key:generate --force
fi

# 3. Esperar a PostgreSQL
echo "[entrypoint] Esperando a PostgreSQL ($DB_HOST:$DB_PORT)..."
until php -r "exit(@fsockopen('$DB_HOST', ${DB_PORT:-5432}) ? 0 : 1);" 2>/dev/null; do
    sleep 2
done

# 4. Solo el contenedor 'app' aplica migraciones (evita carreras entre contenedores)
if [ "$CONTAINER_ROLE" = "app" ]; then
    echo "[entrypoint] Ejecutando migraciones..."
    php artisan migrate --force
fi

echo "[entrypoint] Arrancando: $@"
exec "$@"
