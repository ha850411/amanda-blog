# syntax=docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89

# Pin the upstream manifests so rebuilding the same runtime version is
# reproducible. Update the digest together with RUNTIME_IMAGE_TAG.
ARG PHP_IMAGE=php:8.4-fpm-bookworm@sha256:c5fb7a0c02f4efe280691910c8b734995fa83598cdcf3115ef5dcb2e4617681c
ARG COMPOSER_IMAGE=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040

FROM ${COMPOSER_IMAGE} AS composer
FROM ${PHP_IMAGE}

ARG REDIS_EXTENSION_VERSION=6.3.0
ARG IMAGICK_EXTENSION_VERSION=3.8.1
ARG MEMCACHED_EXTENSION_VERSION=3.4.0
ARG XDEBUG_EXTENSION_VERSION=3.5.3

RUN rm -f /etc/apt/apt.conf.d/docker-clean

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends gnupg \
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
    libzip-dev \
    libxslt1-dev \
    libmagickwand-dev \
    libmemcached-dev \
    libmemcached-tools \
    fonts-noto-cjk \
    unixodbc-dev \
    freetds-dev \
    libgssapi-krb5-2 \
    ca-certificates \
    && update-ca-certificates

# 使用 cache mount 保留 PECL 下載檔，runtime layer 失效時不必全部重抓。
RUN --mount=type=cache,target=/tmp/pear/cache,sharing=locked \
    pecl install \
        redis-${REDIS_EXTENSION_VERSION} \
        imagick-${IMAGICK_EXTENSION_VERSION} \
        memcached-${MEMCACHED_EXTENSION_VERSION} \
        xdebug-${XDEBUG_EXTENSION_VERSION} \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo pdo_mysql bcmath sockets soap intl xsl ftp zip \
    && docker-php-ext-enable redis imagick memcached

# Composer 以系統 unzip 解壓可保留套件內的 UNIX 權限；獨立成 layer，
# 日後只調整工具不會迫使昂貴的 PHP/PECL extensions 全部重編。
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update \
    && apt-get install -y --no-install-recommends unzip

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
