FROM php:8.2-cli-alpine

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libxml2-dev \
        autoconf \
        g++ \
        make \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql mbstring intl dom zip opcache \
    && apk del autoconf g++ make

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

COPY composer.json composer.lock* ./

RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts || \
    composer install --no-dev --no-interaction --prefer-dist --no-scripts --ignore-platform-reqs

COPY . .

RUN composer dump-autoload -o

RUN mkdir -p storage/logs storage/exports storage/reports storage/metrics \
    && chmod -R 0770 storage

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
