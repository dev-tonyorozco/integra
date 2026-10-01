FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build
FROM php:8.4-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libsqlite3-dev libonig-dev libxml2-dev libzip-dev libicu-dev unzip git postgresql-client && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring zip intl && a2enmod rewrite headers && rm -r /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY --from=assets /app/public/build ./public/build
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/integra.ini
COPY deploy/entrypoint.sh /usr/local/bin/integra-entrypoint
RUN chmod +x /usr/local/bin/integra-entrypoint && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 80
ENTRYPOINT ["integra-entrypoint"]
CMD ["apache2-foreground"]
