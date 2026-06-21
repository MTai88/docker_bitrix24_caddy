# Поднять рядом второй такой же стек (другой проект Bitrix)

Стек спроектирован так, чтобы можно было держать несколько экземпляров на одном
хосте без коллизий по контейнерам, сетям и томам. Префикс берётся из
переменной `COMPOSE_PROJECT_NAME` в `.env`.

## Шаг 1. Скопировать каталог проекта

```powershell
# Из родительской папки (например C:\WebServer)
Copy-Item -Path .\docker_bitrix24 -Destination .\docker_other_bitrix -Recurse
```

Внутри `docker_other_bitrix` появится полная копия: `docker-compose.yml`,
`php/`, `caddy/`, `.env`, `www/`, `logs/`, `Dockerfile` и т.д.

## Шаг 2. Создать новый `.env`

В новом каталоге:

```powershell
cd C:\WebServer\docker_other_bitrix
Copy-Item .env.example .env
```

## Шаг 3. Изменить ключевые переменные в новом `.env`

Обязательно поменять:

```env
# Префикс Compose — критично, иначе оба стека будут шарить данные.
COMPOSE_PROJECT_NAME=other_bitrix

# Домен (для второго проекта свой, прописать в hosts).
DOMAIN=other.bitrix.local

# Пароли — ОБЯЗАТЕЛЬНО другие, иначе mysql и push-сервер
# могут работать нестабильно при одновременном подъёме.
MYSQL_ROOT_PASSWORD=other_root_xxx
MYSQL_PASSWORD=other_bitrix_xxx
PUSH_SECURITY_KEY=$(openssl rand -hex 32)   # см. примечание ниже
```

Порты хоста (если оба стека поднимаются одновременно) тоже разнести:

```env
HTTP_PORT=8080
HTTPS_PORT=8443
MYSQL_PORT=3307
ADMINER_PORT=8081
MAILHOG_UI_PORT=8026
MAILHOG_SMTP_PORT=1026
POSTGRES_PORT=5433
```

Если второй стек поднимается **только когда первый остановлен** — порты
можно не менять.

> **Генерация PUSH_SECURITY_KEY без OpenSSL на Windows:**
> ```powershell
> -join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Max 256) })
> ```

## Шаг 4. Домен в hosts

Добавить в `C:\Windows\System32\drivers\etc\hosts` (от администратора):

```
127.0.0.1   bitrix.local
127.0.0.1   other.bitrix.local
```

## Шаг 5. Поднять

```powershell
cd C:\WebServer\docker_other_bitrix
docker compose up -d --build
```

Что получится:

| Объект | Первый стек (`bitrix`) | Второй стек (`other_bitrix`) |
|---|---|---|
| Контейнеры | `bitrix-mysql-1`, `bitrix-php-1`, … | `other_bitrix-mysql-1`, … |
| Сеть | `bitrix_bitrix-network` | `other_bitrix_bitrix-network` |
| Volume MySQL | `bitrix_mysql_data` | `other_bitrix_mysql_data` |
| Volume Redis | `bitrix_redis_data` | `other_bitrix_redis_data` |
| Volume Caddy | `bitrix_caddy_data`, `bitrix_caddy_config` | `other_bitrix_caddy_data`, `other_bitrix_caddy_config` |
| Порты | 80, 443, 3306, … | 8080, 8443, 3307, … (если разнесли) |

Все данные второго стека живут в собственных volume — удаление первого
никак не задевает второй.

## Как проверить, что стеки изолированы

```powershell
# Список volume'ов (должно быть по 5 на каждый стек).
docker volume ls | Select-String "bitrix|other_bitrix"

# Список сетей.
docker network ls | Select-String "bitrix-network"

# Заглянуть в MySQL второго стека.
docker compose -p other_bitrix exec mysql mysql -uroot -p -e "SHOW DATABASES;"
```

## Полностью снести один из стеков

```powershell
cd C:\WebServer\docker_other_bitrix
docker compose down --volumes --remove-orphans
```

`--volumes` удалит и его собственные volume. Без этого флага данные
сохранятся и поднимутся снова при следующем `up`.

## Частые ошибки

| Симптом | Причина |
|---|---|
| `bind for 0.0.0.0:80 failed: port is already allocated` | Оба стека пытаются занять 80/443. Разнесите `HTTP_PORT`/`HTTPS_PORT` в `.env`. |
| `network bitrix-network not found` | Запускаете `docker compose` из другой директории — он не видит compose-файл и подставляет имя проекта из текущего cwd. Запускайте строго из корня стека или передавайте `-p <имя>`. |
| MySQL второго стека падает с `InnoDB: tablespace … already in use` | Забыли поменять `COMPOSE_PROJECT_NAME` — оба стека используют один и тот же volume. |
| Push не работает между Bitrix и push-server'ом | Проверьте, что `SECURITY_KEY` у PHP и push-server одинаковый **внутри одного стека** (свой у каждого проекта). |
