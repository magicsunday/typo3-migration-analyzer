#######
# PHP #
#######
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-alpine AS php-build

RUN apk update && \
    apk upgrade --no-cache && \
    apk add --no-cache \
        bash \
        nodejs \
        npm \
        shadow \
        tzdata

# Match www-data UID/GID to host user for bind-mount compatibility
ARG LOCAL_USER_ID=999
ARG LOCAL_GROUP_ID=82
RUN usermod -u ${LOCAL_USER_ID} www-data && \
    groupmod -g ${LOCAL_GROUP_ID} www-data

# Add PHP extension installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install required PHP extensions
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
        intl \
        opcache \
        zip && \
    rm -f /usr/local/bin/install-php-extensions

LABEL org.opencontainers.image.title="TYPO3 Migration Analyzer" \
      org.opencontainers.image.description="Analyzes TYPO3 deprecation RST documents and generates Extension Scanner matcher configs." \
      org.opencontainers.image.authors="Rico Sonntag <mail@ricosonntag.de>" \
      org.opencontainers.image.source="https://github.com/magicsunday/typo3-migration-analyzer.git"

# Copy custom PHP and PHP-FPM configuration
COPY rootfs/usr/local/etc/php/conf.d/*.ini $PHP_INI_DIR/conf.d/
COPY rootfs/usr/local/etc/php-fpm.d/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www

# Copy application
COPY . .

# Install dependencies and run Symfony auto-scripts (cache:clear, assets:install, importmap:install)
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN APP_ENV=prod APP_SECRET=docker-build-secret DEFAULT_URI=http://localhost \
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts && \
    composer run-script auto-scripts --no-interaction || true && \
    php bin/console asset-map:compile --env=prod

# Fix permissions for Symfony var/ directory
RUN chown -R www-data:www-data var/
