# Ядро магазина цифровых товаров

Заказы, вебхук оплаты и автоматическая выдача ключей от поставщиков-заглушек.

## Стек

PHP 8.3 без фреймворка, PostgreSQL 16. Из зависимостей — только автозагрузчик composer (PSR-4)
и phpunit.

Фреймворк здесь ничего не добавил бы к надёжности. Всё, на чём держится однократность выдачи, —
это уникальные индексы, условные `UPDATE` с проверкой `rowCount` и границы транзакций. Это
пишется прямым SQL, и любой слой поверх PDO только прячет то, что в ядре платежей нужно видеть.

## Запуск

Нужны Docker и PHP 8.3 с расширениями `pdo_pgsql` и `curl`. Если локального PHP нет, все команды
можно выполнять в контейнере: `docker compose run --rm runner <команда>`.

```bash
cp .env.example .env
docker compose up -d
composer install
php bin/migrate.php
php bin/seed.php
```

Дальше три процесса, каждый в своём терминале:

```bash
SUPPLIER_NAME=A PHP_CLI_SERVER_WORKERS=8 php -S localhost:9001 suppliers/supplier.php & \
SUPPLIER_NAME=B PHP_CLI_SERVER_WORKERS=8 php -S localhost:9002 suppliers/supplier.php
```

```bash
php -S localhost:8000 -t public
```

```bash
php bin/worker.php
```

Те же шаги есть как `make up`, `make migrate`, `make seed`, `make suppliers`, `make serve`,
`make worker`.

### Заказ → вебхук → delivered

```bash
curl -s -X POST localhost:8000/api/orders -H 'Content-Type: application/json' -d '{"sku":"KEY-CS2-PRIME"}'
```

```json
{"id":"ord_f68d66677f12f8d4","sku":"KEY-CS2-PRIME","amount":1290,"currency":"RUB","status":"created","code":null}
```

```bash
php bin/paysim.php ord_f68d66677f12f8d4 --status=paid --n=1
```

Хендлер вебхука только записывает событие и переводит заказ в `paid`; к поставщику идёт воркер,
поэтому код появляется через доли секунды после ответа `200`.

```bash
curl -s localhost:8000/api/orders/ord_f68d66677f12f8d4
```

```json
{"id":"ord_f68d66677f12f8d4","sku":"KEY-CS2-PRIME","amount":1290,"currency":"RUB","status":"delivered","code":"1RG2-L28O-O80G"}
```

`php bin/paysim.php <order_id> --n=50` шлёт 50 вебхуков параллельно через `curl_multi` с одним
`event_id`; с флагом `--unique` — с разными.

## Проверка критериев приёмки

Все проверки идут в контейнере: встроенный сервер PHP однопроцессный на Windows, а
`PHP_CLI_SERVER_WORKERS` работает только на POSIX — без этого 50 «параллельных» вебхуков просто
встанут в очередь. Ассерты — SQL-запросами к живой базе, HTTP и таймауты настоящие.

| Критерий из ТЗ | Команда |
| --- | --- |
| 1. 50 параллельных вебхуков по одному заказу, ровно одна выдача | `make race` (case 2) |
| 2. Повторный вебхук с тем же `event_id` ничего не меняет | `make race` (case 1) |
| 3. Вебхук вне порядка или раньше заказа обработан корректно | `make race` (case 3) |
| 4. Таймаут поставщика, который успел выдать код, не даёт вторую выдачу | `make chaos` (case 4, 7) |
| 5. Поставщик A недоступен, fallback на B, товар выдан один раз | `make chaos` (case 5) |
| 6. Пустой остаток — восстановимое состояние, без падения | `make chaos` (case 6) |
| Этап 4: доведение зависших заказов, ledger, сверка | `make recover` |
| Этап 5: витрина на 5000 SKU и 200k ключей | `make bench-catalog` |

`make chaos` дополнительно гоняет 10 заказов при 30% отказов и 30% таймаутов у A и 20/20 у B.
`make recover` роняет 12 заказов в `delivery_failed`/`out_of_stock` при коротком пуле, потом
включает восстановление и требует, чтобы все 12 стали `delivered`, ledger сошёлся, а список
«выдан, но не оплачен» остался пустым.

Смоук-тесты API: `make test` (нужны поднятые база, поставщики и приложение).
Сверка и журнал отдельно: `make reconcile`, `make ledger-check`, `GET /api/admin/reconcile`.

## Структура

| Путь | Что там |
| --- | --- |
| `src/Orders.php` | `transition()` — единственный способ сменить статус заказа, условный `UPDATE` + `rowCount` |
| `src/Payments.php` | приём вебхука и применение события, семантика `applied`/`result`, запись в ledger |
| `src/Delivery.php` | попытки к поставщику, бэкофф, липкий `unknown`, fallback, восстановление |
| `src/Reconcile.php` | запросы сверки, общие для CLI и HTTP |
| `suppliers/supplier.php` | заглушка поставщика: свой PDO, свои таблицы, инъекция отказов и таймаутов |
| `bin/worker.php` | три обязанности за проход: выдача, отложенные события, восстановление |
| `bin/paysim.php` | эмулятор платёжной системы, он же харнесс гонок |
| `migrations/` | plain SQL, применяются по порядку, учёт в `schema_migrations` |
| `tests/*.sh` | сценарные прогоны, каждый поднимает свой стек внутри контейнера |
| `docs/stage5_explain.md` | планы запросов витрины с `EXPLAIN (ANALYZE, BUFFERS)` |

## Переменные окружения

| Переменная | По умолчанию | Назначение |
| --- | --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | `127.0.0.1`, `5432`, `shop`, `shop`, `shop` | подключение к Postgres |
| `APP_URL` | `http://localhost:8000` | база для `paysim` и тестов |
| `SUPPLIER_A_URL`, `SUPPLIER_B_URL` | `http://127.0.0.1:9001`, `:9002` | адреса заглушек |
| `DELIVERY_CONNECT_TIMEOUT_SEC` | `2` | таймаут соединения с поставщиком |
| `DELIVERY_TIMEOUT_SEC` | `5` | общий бюджет вызова; его превышение даёт `unknown`, а не `failed` |
| `DELIVERY_MAX_ATTEMPTS` | `4` | попыток на поставщика, все с одним `request_id` |
| `DELIVERY_BACKOFF_BASE_MS`, `DELIVERY_BACKOFF_CAP_MS` | `200`, `5000` | экспоненциальный бэкофф с джиттером |
| `WORKER_BATCH` | `20` | заказов и событий за проход |
| `WORKER_SLEEP_MS` | `200` | пауза, когда проход ничего не нашёл |
| `RECOVERY_DELAY_SEC` | `30` | сколько заказ должен простоять, прежде чем его возьмёт восстановление; он же бэкофф |
| `RECOVERY_INTERVAL_SEC` | `5` | как часто воркер вообще сканирует зависшие |
| `RECONCILE_STALE_MIN` | `5` | с какого возраста заказ попадает в «оплачен, но не выдан» |
| `SUPPLIER_NAME` | `A` | имя процесса-заглушки |
| `FAIL_RATE` | `0` | доля ответов 5xx до какой-либо выдачи |
| `TIMEOUT_RATE` | `0` | доля зависаний после того, как код уже выдан |
| `TIMEOUT_SEC` | `10` | длительность зависания; должна превышать `DELIVERY_TIMEOUT_SEC` |
