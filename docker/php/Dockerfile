FROM php:8.3-fpm-alpine

# Dependencias del sistema y extensiones PHP necesarias:
#  - pdo_pgsql: driver de PostgreSQL
#  - sockets/bcmath: requeridas por php-amqplib (cliente RabbitMQ)
RUN apk add --no-cache \
        postgresql-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo_pgsql \
        sockets \
        bcmath \
    && apk del $PHPIZE_DEPS

# Composer (copiado desde su imagen oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Instalamos dependencias primero (mejor caché de capas)
COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-interaction --prefer-dist || true

# Copiamos el resto del código
COPY . .

# Al arrancar: instala deps (si faltan), espera dependencias, migra y levanta php-fpm.
# El entrypoint mantiene idempotencia para `docker compose up` repetidos.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
