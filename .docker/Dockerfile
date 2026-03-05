ARG PHP_VERSION=8.4
ARG COMPOSER_VERSION=2

FROM composer:${COMPOSER_VERSION} AS composer
FROM php:${PHP_VERSION}-fpm

RUN apt-get update && \
    apt-get install -y gnupg \
    curl \
    memcached \
    redis-server \
    git \
    zip \
    wget \
    gpg \
    vim \
    libz-dev \
    libxml2-dev \
    libicu-dev \
    libssl-dev \
    libxslt1-dev \
    libmagickwand-dev \
    libmemcached-dev \
    libmemcached-tools \
    unixodbc-dev \
    freetds-dev \
    libgssapi-krb5-2 \
    ca-certificates \
    && update-ca-certificates

# 使用 docker-php-ext-enable 啟用這些擴充套件
RUN pecl install redis imagick memcached xdebug \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo pdo_mysql bcmath sockets soap intl xsl ftp \
    && docker-php-ext-enable redis imagick memcached

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]