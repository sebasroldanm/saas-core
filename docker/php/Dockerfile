FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000

# Instalador de extensiones PHP (resuelve dependencias de sistema automáticamente)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache git unzip bash \
    && install-php-extensions \
        pdo_mysql \
        redis \
        bcmath \
        intl \
        zip \
        gd \
        pcntl \
        opcache \
        exif

# Usuario no root con el mismo UID/GID del host para evitar problemas de permisos en el volumen
RUN addgroup -g ${GID} core-saas \
    && adduser -D -u ${UID} -G core-saas -s /bin/bash core-saas \
    && sed -i 's/^user = www-data/user = core-saas/; s/^group = www-data/group = core-saas/' /usr/local/etc/php-fpm.d/www.conf

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-core-saas.ini

WORKDIR /var/www/html

USER core-saas

EXPOSE 9000

CMD ["php-fpm"]
