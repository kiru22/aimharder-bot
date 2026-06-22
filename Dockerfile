FROM dunglas/frankenphp:1-php8.4

# Extensiones que necesitan Laravel + SQLite + Filament
RUN install-php-extensions pcntl pdo_sqlite intl zip opcache

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencias PHP (capa cacheable)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# Código de la app
COPY . .
RUN composer dump-autoload --optimize \
 && php artisan filament:assets

# Datos persistentes (SQLite) + permisos de escritura
RUN mkdir -p /app/database \
 && chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/database

ENV SERVER_NAME=:8080
EXPOSE 8080

# FrankenPHP sirve public/ por defecto. El servicio web migra al arrancar;
# el worker (Dokploy) sobreescribe el comando con: php artisan schedule:work
CMD ["sh", "-c", "mkdir -p /app/database && touch /app/database/database.sqlite && php artisan migrate --force && frankenphp run --config /etc/caddy/Caddyfile"]
