FROM php:8.2-fpm-alpine

# Dependencias del sistema
RUN apk add --no-cache \
    bash \
    curl \
    mariadb-client \
    shadow \
    linux-headers \
    $PHPIZE_DEPS

# Extensiones PHP necesarias
RUN docker-php-ext-install pdo pdo_mysql sockets opcache zipbcmath bcmath \
    && docker-php-ext-enable pdo_mysql opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Instalar dependencias (se rebuild solo si vendor/ no existe)
COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || true

# Asegurar storage writable
RUN mkdir -p storage/logs storage/cache \
    && chown -R www-data:www-data storage

EXPOSE 9000

CMD ["php-fpm"]