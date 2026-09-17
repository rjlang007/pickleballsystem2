FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    icu-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev libzip-dev \
    oniguruma-dev zlib-dev postgresql-dev postgresql-client curl curl-dev nginx aws-cli bash && \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype && \
    docker-php-ext-install pdo pdo_pgsql gd intl mbstring zip curl opcache && \
    rm -rf /var/cache/apk/*

WORKDIR /app
COPY . /app
RUN chown -R www-data:www-data /app && \
    find /app -type d -exec chmod 755 {} + && \
    find /app -type f -exec chmod 644 {} + && \
    chmod +x /app/start.sh && \
    rm -f /app/create_admins.php /app/generate_hash.php

EXPOSE 8080
CMD ["/app/start.sh"]
