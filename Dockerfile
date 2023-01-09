FROM php:8.1-cli

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

VOLUME /app

WORKDIR /app

CMD ["composer", "install"]