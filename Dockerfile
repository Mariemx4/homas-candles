FROM php:8.3-apache-trixie

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libcurl4-openssl-dev libonig-dev libxml2-dev libsqlite3-dev \
        postgresql-client-17 \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pdo_sqlite curl mbstring dom \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# Old category URLs redirect to the single category route (see the file).
COPY docker/apache/legacy-category-routes.conf /etc/apache2/conf-available/legacy-category-routes.conf
RUN a2enconf legacy-category-routes

RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . /var/www/html
RUN composer dump-autoload --no-dev --optimize --no-interaction

RUN mkdir -p app/storage/logs app/storage/backups app/storage/test-sessions \
        app/storage/uploads/payments public/assets/products \
    && chown -R www-data:www-data app/storage public/assets/products \
    && chmod -R u=rwX,g=rwX,o= app/storage \
    && printf 'date.timezone=UTC\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/homas.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
