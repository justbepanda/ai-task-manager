FROM php:8.4-fpm-bookworm

# Note: php:8.5-fpm-bookworm was tried first; imap is no longer a bundled
# docker-php-ext (removed from php-src). Same for 8.4 — install imap via PECL.
# Base image kept at 8.4 for broader extension/package stability.

ARG UID=1000
ARG GID=1000

ENV TZ=Europe/Moscow \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libssl-dev \
        libc-client-dev \
        libkrb5-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && PHP_OPENSSL=yes pecl install imap \
    && docker-php-ext-enable imap \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --gid ${GID} developer \
    && useradd --uid ${UID} --gid developer --shell /bin/bash --create-home developer \
    && mkdir -p /var/www \
    && chown -R developer:developer /var/www

WORKDIR /var/www

USER developer

CMD ["php-fpm"]
