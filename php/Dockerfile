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
# Xdebug 3.x — собираем из исходников с xdebug.org (минуя pecl.php.net,
# который периодически отдаёт пустой список пакетов).
# Конфиг подключается из ./php/xdebug.ini (монтируется через compose).
# По умолчанию Xdebug ВЫКЛЮЧЕН — включается через env XDEBUG_ENABLE=1
# или через query-параметр XDEBUG_SESSION=1 (при trigger-режиме).
# Версию xdebug можно зафиксировать, переопределив ARG XDEBUG_VERSION.
# =============================================================
ARG XDEBUG_VERSION=3.4.0
RUN curl -fsSL "https://xdebug.org/files/xdebug-${XDEBUG_VERSION}.tgz" -o /tmp/xdebug.tgz \
    && tar -xzf /tmp/xdebug.tgz -C /tmp \
    && cd /tmp/xdebug-${XDEBUG_VERSION} \
    && phpize \
    && ./configure --enable-xdebug \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/xdebug.tgz /tmp/xdebug-${XDEBUG_VERSION}

# opcache.recommended.ini от базового образа уже включён, но добавим
# собственные настройки через opcache-bitrix.ini (копируется в образ)
COPY opcache-bitrix.ini /usr/local/etc/php/conf.d/opcache-bitrix.ini

# Создаём директории под сайт, логи и отдаём их нужному uid.
# PHP-FPM в compose запускается под uid 1000:1000 (как WSL-юзер dev),
# чтобы иметь право писать в bind-mount с хостовой ФС.
# Каталоги служебных кешей PHP (sessions, upload_tmp, wsdl, opcache)
# живут в именованном volume php_runtime (/var/lib/bitrix-runtime) —
# создаются в entrypoint.sh при старте контейнера, потому что том
# при первом mount приходит пустым, и Dockerfile их туда положить не может.
ARG APP_UID=1000
ARG APP_GID=1000
RUN mkdir -p /var/www/html \
             /var/www/html/bitrix \
             /var/www/html/upload \
             /var/lib/bitrix-runtime \
             /var/log/php \
    && chown -R ${APP_UID}:${APP_GID} /var/www/html /var/lib/bitrix-runtime /var/log/php

WORKDIR /var/www/html

# =============================================================
# Composer — ставим официально через getcomposer.org
# Использование: docker compose exec php composer require vendor/package
# =============================================================
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Healthcheck-скрипт (проверяет FPM через TCP-соединение к 9000)
COPY php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck
RUN chmod +x /usr/local/bin/php-fpm-healthcheck

# Entrypoint: создаёт каталоги под runtime-кеши PHP
# (volume php_mount приходит пустым — папки приходится делать на старте).
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Healthcheck самого образа: проверяем, что FPM слушает порт 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php-fpm-healthcheck || exit 1

# По умолчанию запускаем php-fpm под entrypoint
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
