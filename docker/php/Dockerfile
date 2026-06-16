FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    postgresql-dev \
    icu-dev \
    linux-headers \
    fcgi \
    nodejs \
    npm

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    zip \
    exif \
    pcntl \
    bcmath \
    gd \
    intl \
    opcache

# Install Redis extension
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# Install Xdebug
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install xdebug-3.4.0 \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create PHP-FPM healthcheck script
RUN echo '#!/bin/sh' > /usr/local/bin/php-fpm-healthcheck \
    && echo 'SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000' >> /usr/local/bin/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

# Set working directory
WORKDIR /var/www/html

# Copy PHP configuration
COPY ./php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy Xdebug configuration
COPY ./xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Copy PHP-FPM pool configuration
COPY ./www.conf /usr/local/etc/php-fpm.d/www.conf

# Create www-data user with proper permissions
ARG UID=1000
ARG GID=1000

RUN deluser www-data || true

RUN addgroup -g ${GID} -S laravel \
    && adduser -u ${UID} -D -S -G laravel laravel

# Expose port 9000 for PHP-FPM
EXPOSE 9000

USER laravel

CMD ["php-fpm"]