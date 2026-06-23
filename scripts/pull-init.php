<?php
/**
 * Init-скрипт модуля pull для проекта Bitrix24 на Bitrix Push Server 2.0 (Node.js).
 *
 * Запускается один раз при первом старте docker-compose. Настраивает модуль pull
 * в БД Битрикса так, чтобы он работал с нашим push-server'ом (а не с nginx-push-stream
 * или Bitrix24 Cloud).
 *
 * Скрипт идемпотентный: при повторных запусках ничего не делает, если настройки
 * уже совпадают с ожидаемыми.
 *
 * Использование в docker-compose.yml:
 *   pull-init:
 *     image: php:8.3-cli-alpine
 *     depends_on: { php: { condition: service_started } }
 *     volumes: [./scripts/pull-init.php:/init.php:ro]
 *     environment:
 *       DB_HOST: mysql
 *       DB_USER: bitrix
 *       DB_PASSWORD: ${MYSQL_PASSWORD}
 *       DB_NAME: bitrix
 *       PUSH_SECURITY_KEY: ${PUSH_SECURITY_KEY}
 *       PULL_HTTP_PATH: http://caddy/bitrix/pub/
 *       PULL_WS_PATH: ws://##DOMAIN##/bitrix/subws/
 *     networks: [bitrix-network]
 *     restart: "no"
 */

$required = [
    'DB_HOST'     => getenv('DB_HOST')     ?: 'mysql',
    'DB_USER'     => getenv('DB_USER')     ?: 'bitrix',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '',
    'DB_NAME'     => getenv('DB_NAME')     ?: 'bitrix',
];

$expected = [
    // Включает Bitrix Push Server 2.0 (не nginx-push-stream-module).
    'nginx'           => 'Y',
    // Используем ProtobufTransport (для v=4) вместо устаревшего текстового.
    'nginx_version'   => '4',
    'enable_protobuf' => 'Y',
    // URL, по которому PHP публикует push-события. Внутри docker-сети PHP
    // шлёт на hostname `caddy` по HTTP (без TLS), чтобы не возиться с
    // самоподписанным сертификатом.
    'path_to_publish' => getenv('PULL_HTTP_PATH') ?: 'http://caddy/bitrix/pub/',
    // Подпись для аутентификации между Bitrix и push-server'ом. Должна
    // совпадать с PUSH_SECURITY_KEY в .env, который пробрасывается в контейнеры
    // push-server и push-server-sub.
    'signature_key'    => getenv('PUSH_SECURITY_KEY') ?: '',
    // Включает WebSocket-транспорт.
    'websocket'        => 'Y',
];

echo "[pull-init] connecting to MySQL {$required['DB_HOST']}...\n";

$maxAttempts = 30;
$db = null;
for ($i = 1; $i <= $maxAttempts; $i++) {
    try {
        $db = new mysqli(
            $required['DB_HOST'],
            $required['DB_USER'],
            $required['DB_PASSWORD'],
            $required['DB_NAME']
        );
        if (!$db->connect_errno) {
            break;
        }
    } catch (Throwable $e) {
        // ignore, retry
    }
    echo "[pull-init] waiting for MySQL ($i/$maxAttempts)...\n";
    sleep(2);
}
if (!$db || $db->connect_errno) {
    fwrite(STDERR, "[pull-init] FATAL: cannot connect to MySQL after $maxAttempts attempts\n");
    exit(1);
}
$db->set_charset('utf8mb4');
echo "[pull-init] connected\n";

// Проверяем, установлен ли модуль pull в Битриксе (есть ли таблица b_pull_channel).
$res = $db->query("SHOW TABLES LIKE 'b_pull_channel'");
if (!$res || $res->num_rows === 0) {
    echo "[pull-init] module 'pull' is not installed in Bitrix yet — skipping\n";
    exit(0);
}
echo "[pull-init] module 'pull' is installed, applying settings\n";

// Применяем настройки. Используем REPLACE, чтобы и upsert, и update.
$stmt = $db->prepare(
    "REPLACE INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('pull', ?, ?, '')"
);
if (!$stmt) {
    fwrite(STDERR, "[pull-init] prepare failed: " . $db->error . "\n");
    exit(1);
}

foreach ($expected as $name => $value) {
    $stmt->bind_param('ss', $name, $value);
    if (!$stmt->execute()) {
        fwrite(STDERR, "[pull-init] failed to set $name: " . $stmt->error . "\n");
        exit(1);
    }
    echo "[pull-init] set $name = $value\n";
}

// Сбрасываем кеш конфигурации модуля pull, чтобы Битрикс перечитал настройки
// при следующем AJAX-запросе (а не держал старый config в памяти).
$cacheTsName = 'config_timestamp';
$zero = '0';
$stmt->bind_param('ss', $cacheTsName, $zero);
$stmt->execute();
echo "[pull-init] reset config_timestamp to 0 (force Bitrix to re-read config)\n";

$stmt->close();
$db->close();
echo "[pull-init] DONE\n";
