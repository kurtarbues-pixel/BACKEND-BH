FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring intl zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN mkdir -p database storage bootstrap/cache \
    && touch database/database.sqlite \
    && chmod -R 777 storage bootstrap/cache database

ENV PORT=8080
ENV APP_ENV=production
ENV APP_DEBUG=true
ENV APP_KEY=base64:OJumJtCzrK7uwVmu1PDqAUPYRwZAosCil968izh66C8=

EXPOSE 8080

CMD ["sh", "-c", "touch database/database.sqlite && php artisan config:clear && (php artisan migrate --force || true) && (php artisan db:seed --force || true) && exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
