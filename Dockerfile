FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql opcache

WORKDIR /var/www/html

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-nexus.ini
COPY deploy/railway-router.php /usr/local/bin/railway-router.php
COPY . /var/www/html

RUN mkdir -p /var/www/html/uploads /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/data

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html", "/usr/local/bin/railway-router.php"]
