# SIGARB — imagem de runtime CANDIDATA (avaliação local; não é artefato aprovado para produção).
FROM composer:2 AS dependencias
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

FROM php:8.5-fpm
# PHP 8.5: OPcache é embutido no binário (não é mais extensão compilável).
RUN docker-php-ext-install pdo_mysql pcntl \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/prodlocal/php/sigarb.ini /usr/local/etc/php/conf.d/zz-sigarb.ini
COPY infra/prodlocal/php/www.conf /usr/local/etc/php-fpm.d/zz-sigarb.conf
WORKDIR /var/www/sigarb
COPY --from=dependencias /app/vendor ./vendor
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p storage/app/private storage/framework/{cache,sessions,views,testing} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && rm -rf tests prototipos /usr/bin/composer
USER www-data
