# Imagem de DESENVOLVIMENTO do SIGARB (não é imagem de produção)
FROM php:8.5-cli
RUN docker-php-ext-install pdo_mysql pcntl \
 && apt-get update && apt-get install -y --no-install-recommends unzip git \
 && rm -rf /var/lib/apt/lists/*
COPY sigarb.ini /usr/local/etc/php/conf.d/zz-sigarb.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} sigarb && useradd -m -u ${UID} -g ${GID} sigarb
USER sigarb
WORKDIR /app
