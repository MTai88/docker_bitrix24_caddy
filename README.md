# Bitrix24 Docker Compose

Готовый стек для запуска Bitrix24 через Docker Desktop:

| Сервис | Назначение | Порт |
|--------|-----------|------|
| **Caddy** | reverse-proxy + авто HTTPS | 80, 443 |
| **PHP-FPM 8.3** | приложение Bitrix24 | (внутренний 9000) |
| **MySQL 8.0** | основная БД | 3306 |
| **Push-сервер** (ikarpovich/bitrix-push-server) | WebSocket / long-polling для чатов | 8010 |
| **Redis 7** | координация push-нод | (внутренний 6379) |
| **MailHog** | перехват почты (SMTP + UI) | 1025, 8025 |
| **Adminer** | GUI для БД | 8080 |
| **PostgreSQL** (опц.) | альтернативная БД | 5432 |

Push-сервер использует образ `ikarpovich/bitrix-push-server` — community-форк,
содержащий оригинальный push-server от Bitrix Inc. (Node.js). Внутри
использует Redis для координации между нодами.

## Структура проекта

```
docker_bitrix24/
├── docker-compose.yml       # Описание всех сервисов
├── .env.example / .env      # Переменные окружения
├── README.md                # Этот файл
├── php/
│   ├── Dockerfile           # PHP 8.3-FPM + расширения Bitrix
│   ├── php.ini              # 99-bitrix.ini
│   ├── opcache-bitrix.ini   # OPcache под нагрузкой
│   └── php-fpm-healthcheck  # healthcheck-скрипт (проверяет порт 9000)
└── caddy/
    └── Caddyfile            # reverse-proxy + auto-HTTPS
└── www/                     # Корень сайта (положите сюда Bitrix)
```

## Быстрый старт

### 1. Подготовка

```powershell
# Скопировать .env (если ещё не скопирован)
Copy-Item .env.example .env -Force

# (Опционально) Поменять значения в .env
notepad .env
```

### 2. Запуск стека

```powershell
# Обычный запуск
docker compose up -d --build

# С PostgreSQL дополнительно
docker compose --profile postgres up -d --build
```

### 3. Проверка

```powershell
# Статусы контейнеров
docker compose ps

# Логи
docker compose logs -f

# Лог конкретного сервиса
docker compose logs -f php
```

### 4. Доступ

- **Bitrix (HTTPS)**: https://bitrix.local или https://localhost
  - Caddy автоматически выпускает самоподписанный сертификат
    (внутренний CA от Caddy) для всех доменов сайта.
  - Браузер предупредит о самоподписанном сертификате — примите его
    (или добавьте корневой CA из volume `bitrix-caddy-data` в доверенные).
- **Bitrix (HTTP)**: http://localhost (Caddy сделает 308 редирект на HTTPS)
- **MailHog UI**: http://localhost:8025
- **Adminer**: http://localhost:8080 (сервер: `mysql`, логин/пароль из `.env`)
- **MySQL**: `localhost:3306` (или внутри сети — `mysql:3306`)

### 5. Локальный домен `bitrix.local`

Чтобы открыть сайт по имени `bitrix.local`, добавьте запись в
`C:\Windows\System32\drivers\etc\hosts` от имени администратора:

```
127.0.0.1 bitrix.local
```

В Блокноте (с правами админа) откройте этот файл и допишите строку.
После этого `https://bitrix.local` будет работать.

### 6. Установка Bitrix

1. Скачайте дистрибутив Bitrix24 (например, «Битрикс24: Корпоративный портал»).
2. Распакуйте содержимое архива в каталог `www/` (заменив `index.php`-заглушку).
3. Откройте https://bitrix.local — запустится мастер установки.
4. В мастере подключения БД укажите:
   - Сервер: `mysql`
   - База: `bitrix`
   - Пользователь: `bitrix`
   - Пароль: из `.env` (`MYSQL_PASSWORD`)
5. Push-сервер при установке указывайте как `http://caddy/bitrix/sub/` —
   Caddy проксирует на push-server:8010.

## Права на каталог `www/`

PHP-FPM работает от пользователя `www-data` (UID 33). Если при установке
Bitrix выдаст ошибку прав — внутри php-контейнера выполните:

```powershell
docker compose exec php chown -R www-data:www-data /var/www/html
docker compose exec php chmod -R 755 /var/www/html
```

## Продакшн-окей

Перед развёртыванием на сервере:

1. В `.env`:
   - Смените ВСЕ `change_me_*` пароли, включая `PUSH_SECURITY_KEY`
     (сгенерируйте через `openssl rand -hex 32`).
2. DNS домена (`$DOMAIN`) направьте на IP сервера.
3. Откройте порты 80 и 443.
4. Запустите `docker compose up -d --build`.
5. Caddy автоматически получит настоящий сертификат Let's Encrypt.

## Управление

```powershell
# Остановить
docker compose down

# Остановить и удалить volumes (полная очистка)
docker compose down -v

# Пересобрать после изменений в Dockerfile
docker compose build --no-cache
docker compose up -d

# Зайти внутрь контейнера
docker compose exec php bash
docker compose exec mysql mysql -ubitrix -p
```

## Известные особенности

- В Windows Docker Desktop работает в WSL2. Скорость ввода-вывода в `www/`
  можно ускорить, исключив каталог из Real-Time Protection антивируса.
- Bitrix активно пишет в `bitrix/cache`, `upload`, `cache` — они смонтированы
  как `bind mount`, поэтому в Windows скорость будет ниже, чем на Linux.
- Для высоких нагрузок на продакшне лучше перенести `www/` в named-volume.
- Push-сервер требует Redis — он уже включён в стек.
- Caddyfile использует плейсхолдер `{$DOMAIN}` — значение подставляется
  из `.env` (через `environment`).
- Самоподписанный сертификат от Caddy создаётся при первом запуске
  автоматически. Корневой CA лежит в volume `bitrix-caddy-data` (`/data/caddy`).
- Для добавления корневого CA в доверенные на Windows скопируйте
  сертификат из контейнера:
  ```
  docker cp bitrix-caddy:/data/caddy/pki/authorities/local/root.crt .
  ```
  и установите его через `certmgr.msc` в "Доверенные корневые центры".

## Полезные команды

```powershell
# Проверить health сервисов
docker compose ps

# Все логи разом
docker compose logs --tail=50

# Тест PHP-FPM напрямую
docker compose exec php php -m | head -30

# Тест MySQL
docker compose exec mysql mysqladmin ping -h localhost -uroot -p

# Redis CLI
docker compose exec redis redis-cli ping

# Очистить весь стек (включая данные!)
docker compose down -v --remove-orphans
```

## Инструменты разработчика

### Composer

```powershell
# Внутри PHP-контейнера (зависимости ставятся в ./www/)
docker compose exec php composer require vendor/package
docker compose exec php composer install
```

### Xdebug (отладка в IDE)

По умолчанию Xdebug **выключен** (`XDEBUG_MODE=off`), чтобы не тормозить обычные запросы.

**Включить через `.env`:**
```env
XDEBUG_MODE=develop          # подсветка + ошибки в IDE
# XDEBUG_MODE=debug          # полноценные брейкпоинты (медленнее)
XDEBUG_START_WITH_REQUEST=trigger  # включение через cookie/GET
```

После изменения — `docker compose up -d php` (пересборка НЕ нужна).

**Подключение к IDE:**
- **PHPStorm**: Settings → PHP → Debug → Xdebug → Debug port: 9003, ✓ Accept external connections
- **VSCode**: расширение "PHP Debug", в `.vscode/launch.json`:
  ```json
  {"type":"php","request":"launch","name":"Listen for Xdebug","port":9003}
  ```

**Запуск отладки в trigger-режиме**: добавь в URL `?XDEBUG_SESSION=1` или поставь расширение "Xdebug helper" в браузере.

### Логи PHP

`error_log` и `display_errors` сконфигурированы так:
- Все ошибки пишутся в файл `./logs/php/error.log` на хосте
- В браузер не выводятся (`display_errors = Off`)

Смотреть в реальном времени:
```powershell
Get-Content C:\WebServer\docker_bitrix24\logs\php\error.log -Wait
```

Также в `./logs/php/xdebug.log` пишется диагностика Xdebug (если включён).
