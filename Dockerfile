FROM php:8.3.0-cli

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git \
        zip \
        unzip \
        libzip-dev && \
    pecl install xdebug && \
    docker-php-ext-install zip && \
    docker-php-ext-enable xdebug && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/root/.composer/cache composer install --no-interaction --no-progress --no-scripts --prefer-dist

COPY . .

RUN chown -R www-data:www-data /app

RUN mkdir -p /var/www/.composer && chown -R www-data:www-data /var/www/.composer

USER www-data

CMD ["composer", "test"]