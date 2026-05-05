FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY deploy/railway-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-nexus.ini
COPY deploy/railway-start.sh /usr/local/bin/railway-start
COPY . /var/www/html

RUN chmod +x /usr/local/bin/railway-start \
    && mkdir -p /var/www/html/uploads /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/data

EXPOSE 8080

CMD ["railway-start"]
