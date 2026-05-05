FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo_mysql opcache

WORKDIR /var/www/html

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-nexus.ini
COPY . /var/www/html

RUN mkdir -p /var/www/html/uploads /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/data
