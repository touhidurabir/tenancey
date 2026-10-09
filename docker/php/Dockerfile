# Local-development image for tenancey: PHP-FPM plus what the app's packages need.
# One image runs four services (see compose.yaml): app (php-fpm), horizon, reverb, scheduler.
FROM php:8.4-fpm

# git and unzip let Composer download and unpack packages.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Bundled extensions: pdo_mysql (MySQL), pcntl (signals for Horizon and Reverb).
# posix is already compiled in.
RUN docker-php-ext-install pdo_mysql pcntl

# phpredis is not bundled with PHP, so it comes from PECL. Same version as the Mac.
RUN pecl install redis-6.3.0 \
    && docker-php-ext-enable redis

# Copy the composer binary out of the official Composer image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Our dev settings. "zz-" sorts last, so these override earlier .ini files.
COPY php.ini /usr/local/etc/php/conf.d/zz-tenancey.ini

WORKDIR /var/www/html
