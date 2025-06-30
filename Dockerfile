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
    # Limpa o cache do apt para reduzir o tamanho da imagem
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist

COPY . .

RUN chown -R www-data:www-data /app

USER www-data

CMD ["composer", "test"]