FROM dunglas/frankenphp:1-php8.4

# build marker: dashboard (fuerza rebuild)

# Extensiones que necesitan Laravel + SQLite + Filament
RUN install-php-extensions pcntl pdo_sqlite intl zip opcache

# unzip + git: descompresión nativa y fallback a source si un dist falla
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip git \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencias PHP (capa cacheable). Reintentos: codeload/GitHub da 400 transitorios.
COPY composer.json composer.lock ./
RUN for i in 1 2 3 4 5; do \
      composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist && exit 0; \
      echo "composer install falló (intento $i/5), reintento en 10s..."; sleep 10; \
    done; \
    echo "composer install falló tras 5 intentos"; exit 1

# Código de la app
COPY . .
RUN composer dump-autoload --optimize \
 && php artisan filament:assets

# Carpeta dedicada para el SQLite persistente (NO /app/database: ahí viven las
# migrations del código; un volumen montado encima las taparía). Aquí se monta el volumen.
RUN mkdir -p /app/persistent \
 && chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/persistent

# Por defecto la BD vive en el volumen persistente (Dokploy puede sobreescribirlo).
ENV SERVER_NAME=:8080 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/persistent/database.sqlite
EXPOSE 8080

# FrankenPHP sirve public/ por defecto. El servicio web crea/migra la BD en la ruta
# de DB_DATABASE (sea cual sea) y arranca; el worker (Dokploy) sobreescribe el
# comando con: php artisan schedule:work
CMD ["sh", "-c", "mkdir -p \"$(dirname \"$DB_DATABASE\")\" && touch \"$DB_DATABASE\" && php artisan migrate --force && frankenphp run --config /etc/caddy/Caddyfile"]
