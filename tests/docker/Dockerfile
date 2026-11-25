FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip procps libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath gd intl mbstring pdo_mysql zip dom \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
