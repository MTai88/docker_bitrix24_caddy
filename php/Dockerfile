# =============================================================
# Dockerfile для PHP 8.3-FPM с расширениями под Bitrix24
# =============================================================
FROM php:8.3-fpm

# Аргумент для timezone
ARG TZ=Europe/Moscow
ENV TZ=${TZ}

# Системные зависимости: библиотеки, нужные PHP-расширениям, плюс git/unzip
# Добавлен $PHPIZE_DEPS для сборки Xdebug через pecl.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libxml2-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        libgd-dev \
        libicu-dev \
        libssl-dev \
        libxslt-dev \
        libmemcached-dev \
        unzip \
        git \
        curl \
        tzdata \
        ca-certificates \
        $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

# Настройка таймзоны
RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime && echo ${TZ} > /etc/timezone

# PHP-расширения, необходимые Bitrix24
# Замечание: расширение `xml` уже включает simplexml, dom, xmlreader, xmlwriter.
RUN docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        gd \
        zip \
        xml \
        mbstring \
        curl \
        opcache \
        intl \
        xsl \
        bcmath \
        sockets \
        fileinfo \
        exif

# =============================================================
# Xdebug 3.x — устанавливается через pecl.
# Конфиг подключается из ./php/xdebug.ini (монтируется через compose).
# По умолчанию Xdebug ВЫКЛЮЧЕН — включается через env XDEBUG_ENABLE=1
# или через query-параметр XDEBUG_SESSION=1 (при trigger-режиме).
# =============================================================
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && pecl clear-cache \
    && rm -rf /tmp/pear

# opcache.recommended.ini от базового образа уже включён, но добавим
# собственные настройки через opcache-bitrix.ini (копируется в образ)
COPY opcache-bitrix.ini /usr/local/etc/php/conf.d/opcache-bitrix.ini

# Создаём директории под сайт, логи и отдаём их www-data
# (UID/GID 33 — стандартные для пользователя www-data в php:fpm)
RUN mkdir -p /var/www/html /var/www/html/bitrix /var/www/html/upload /var/www/html/cache \
             /var/log/php \
    && chown -R www-data:www-data /var/www/html /var/log/php

WORKDIR /var/www/html

# =============================================================
# Composer — ставим официально через getcomposer.org
# Использование: docker compose exec php composer require vendor/package
# =============================================================
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Healthcheck-скрипт (проверяет FPM через TCP-соединение к 9000)
COPY php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck
RUN chmod +x /usr/local/bin/php-fpm-healthcheck

# Healthcheck самого образа: проверяем, что FPM слушает порт 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php-fpm-healthcheck || exit 1

# По умолчанию запускаем php-fpm
CMD ["php-fpm"]
