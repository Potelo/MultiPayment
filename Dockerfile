FROM php:8.3-cli

RUN apt-get update && \
    apt-get install -y \
        git \
        zip \
        unzip \
        libzip-dev && \
    pecl install xdebug && \
    docker-php-ext-install zip && \
    docker-php-ext-enable xdebug

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

VOLUME /app

RUN composer install -q --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist