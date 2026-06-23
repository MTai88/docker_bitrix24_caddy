#!/bin/bash
# =============================================================
# Entrypoint для PHP-FPM контейнера Bitrix24
# =============================================================
# Создаёт каталоги под служебные кеши PHP, потому что они живут в
# именованном volume (php_runtime -> /var/lib/bitrix-runtime), который
# при первом docker compose up приходит пустым. Без mkdir -p PHP-FPM
# упадёт при первом запросе (No such file or directory для session.save_path
# и т.д.).
#
# Под root нужно запускать именно из-за chown: даём владельцу, под которым
# реально будет работать php-fpm (по умолчанию www-data, либо override
# через user: в compose + переменные APP_UID/APP_GID в env).
# =============================================================
set -e

# Кто реально будет писать в /var/lib/bitrix-runtime:
#   1) если в compose задан user: и в env проброшены APP_UID/APP_GID — берём их;
#   2) иначе — определяем по юзеру www-data, под которым FPM работает в
#      стандартном образе php:8.3-fpm.
APP_UID="${APP_UID:-$(getent passwd www-data | cut -d: -f3)}"
APP_GID="${APP_GID:-$(getent passwd www-data | cut -d: -f4)}"

mkdir -p \
    /var/lib/bitrix-runtime/sessions \
    /var/lib/bitrix-runtime/upload \
    /var/lib/bitrix-runtime/wsdl \
    /var/lib/bitrix-runtime/opcache

chown -R "${APP_UID}:${APP_GID}" /var/lib/bitrix-runtime

exec "$@"
