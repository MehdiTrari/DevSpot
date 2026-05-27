FROM php:8.3-apache

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APACHE_DOCUMENT_ROOT=/app/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-install \
        dom \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
        zip \
    && a2enmod rewrite headers expires \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '%s\n' '<Directory /app/public>' '    AllowOverride None' '    Require all granted' '    FallbackResource /index.php' '</Directory>' > /etc/apache2/conf-available/devspot.conf \
    && a2enconf devspot \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts

COPY . .
COPY docker/symfony/entrypoint.sh /usr/local/bin/devspot-entrypoint

RUN chmod +x /usr/local/bin/devspot-entrypoint \
    && mkdir -p var/cache var/log public/uploads

EXPOSE 80

ENTRYPOINT ["devspot-entrypoint"]
CMD ["apache2-foreground"]
