FROM php:8.3-cli

ENV COMPOSER_ALLOW_SUPERUSER=1

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
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts

COPY . .
COPY docker/symfony/entrypoint.sh /usr/local/bin/devspot-entrypoint

RUN chmod +x /usr/local/bin/devspot-entrypoint \
    && mkdir -p var/cache var/log public/uploads

EXPOSE 8000

ENTRYPOINT ["devspot-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
