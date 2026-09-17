FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    icu-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev libzip-dev \
    oniguruma-dev zlib-dev postgresql-dev postgresql-client curl && \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype && \
    docker-php-ext-install pdo pdo_pgsql gd intl mbstring zip opcache && \
    rm -rf /var/cache/apk/*

WORKDIR /var/www/html
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type d -exec chmod 755 {} + && \
    find /var/www/html -type f -exec chmod 644 {} +

EXPOSE 9000
CMD ["php-fpm"]
