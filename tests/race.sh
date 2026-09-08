#!/usr/bin/env bash
#
# Reproducible race test for stage 2 - acceptance criteria 1-3 of the assignment.
#
#   make race          (or)   docker compose run --rm runner bash tests/race.sh
#
# Runs inside the container on purpose: the built-in server is single-process on Windows,
# and PHP_CLI_SERVER_WORKERS (POSIX only) is what makes the 50 webhooks actually concurrent.
# Nothing is mocked - real php -S, real curl_multi, real worker process, assertions in SQL.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

FAILURES=0
WORKER_PID=""
APP_PID=""
SUP_A_PID=""
SUP_B_PID=""

check() {
    php tests/race_assert.php "$@" || FAILURES=$((FAILURES + 1))
}

cleanup() {
    [ -n "$WORKER_PID" ] && kill "$WORKER_PID" 2>/dev/null
    kill "$APP_PID" "$SUP_A_PID" "$SUP_B_PID" 2>/dev/null
    wait 2>/dev/null
}
trap cleanup EXIT

new_order() {
    curl -s -X POST "$APP_URL/api/orders" -H 'Content-Type: application/json' -d "{\"sku\":\"$1\"}" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";'
}

order_status() {
    curl -s "$APP_URL/api/orders/$1" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["status"] ?? "";'
}

wait_for_status() {
    local id="$1" want="$2" deadline=$((SECONDS + 20))
    while [ $SECONDS -lt $deadline ]; do
        [ "$(order_status "$id")" = "$want" ] && return 0
        sleep 0.2
    done
    return 1
}

start_worker() {
    php bin/worker.php >>/tmp/worker.log 2>&1 &
    WORKER_PID=$!
}

stop_worker() {
    [ -n "$WORKER_PID" ] && kill "$WORKER_PID" 2>/dev/null && wait "$WORKER_PID" 2>/dev/null
    WORKER_PID=""
}

APP_URL="${APP_URL:-http://127.0.0.1:8000}"

echo "=== setup ==="
[ -f vendor/autoload.php ] || composer install --no-interaction --quiet
php bin/migrate.php
php bin/seed.php
php tests/race_reset.php

SUPPLIER_NAME=A PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 suppliers/supplier.php >/tmp/supplier-a.log 2>&1 &
SUP_A_PID=$!
SUPPLIER_NAME=B PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9002 suppliers/supplier.php >/tmp/supplier-b.log 2>&1 &
SUP_B_PID=$!
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t public >/tmp/app.log 2>&1 &
APP_PID=$!

for _ in $(seq 1 60); do
    curl -sf "$APP_URL/health" >/dev/null && break
    sleep 0.25
done
echo "app up with PHP_CLI_SERVER_WORKERS=8, suppliers A/B up"

start_worker
echo "worker started (pid $WORKER_PID)"

# ---------------------------------------------------------------------------
echo
echo "=== case 1: 50 concurrent webhooks, ONE event_id (criterion 2) ==="
ORDER1=$(new_order KEY-CS2-PRIME)
echo "order $ORDER1"
php bin/paysim.php "$ORDER1" --status=paid --n=50 | sed 's/^/  /'
wait_for_status "$ORDER1" delivered || echo "  (timed out waiting for delivered)"

check "order reached delivered" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER1"
check "payment_events rows" 1 \
    "SELECT count(*) FROM payment_events WHERE order_id = ?" "$ORDER1"
check "payment_events applied" 1 \
    "SELECT count(*) FROM payment_events WHERE order_id = ? AND applied" "$ORDER1"
check "issue_requests rows" 1 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ?" "$ORDER1"
check "issue_requests issued" 1 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ? AND status = 'issued'" "$ORDER1"
check "keys reserved in key_pool" 1 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER1"
check "supplier issued exactly once" 1 \
    "SELECT count(*) FROM supplier_issues WHERE order_id = ? AND code IS NOT NULL" "$ORDER1"

# ---------------------------------------------------------------------------
echo
echo "=== case 2: 50 concurrent webhooks, DISTINCT event_ids (criterion 1) ==="
ORDER2=$(new_order KEY-GTA5)
echo "order $ORDER2"
php bin/paysim.php "$ORDER2" --status=paid --n=50 --unique | sed 's/^/  /'
wait_for_status "$ORDER2" delivered || echo "  (timed out waiting for delivered)"

check "order reached delivered" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER2"
check "payment_events rows" 50 \
    "SELECT count(*) FROM payment_events WHERE order_id = ?" "$ORDER2"
check "every event consumed (applied=true)" 50 \
    "SELECT count(*) FROM payment_events WHERE order_id = ? AND applied" "$ORDER2"
check "exactly one event moved the order" 1 \
    "SELECT count(*) FROM payment_events WHERE order_id = ? AND result = 'applied'" "$ORDER2"
check "the other 49 are ignored" 49 \
    "SELECT count(*) FROM payment_events WHERE order_id = ? AND result = 'ignored'" "$ORDER2"
check "issue_requests rows" 1 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ?" "$ORDER2"
check "keys reserved in key_pool" 1 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER2"
check "supplier issued exactly once" 1 \
    "SELECT count(*) FROM supplier_issues WHERE order_id = ? AND code IS NOT NULL" "$ORDER2"

# ---------------------------------------------------------------------------
echo
echo "=== case 3: webhook ahead of its order (criterion 3) ==="
stop_worker
echo "worker stopped"

ORDER3=$(php -r 'echo "ord_" . bin2hex(random_bytes(8));')
EVENT3=$(php -r 'echo "evt_" . bin2hex(random_bytes(6));')
echo "webhook for not-yet-existing order $ORDER3"

HTTP3=$(curl -s -o /tmp/case3.json -w '%{http_code}' -X POST "$APP_URL/webhook/payment" \
    -H 'Content-Type: application/json' \
    -d "{\"event_id\":\"$EVENT3\",\"order_id\":\"$ORDER3\",\"status\":\"paid\",\"amount\":3490,\"currency\":\"RUB\"}")
echo "  HTTP $HTTP3 $(cat /tmp/case3.json)"

if [ "$HTTP3" = "200" ]; then
    echo "  PASS  webhook for unknown order answered 200"
else
    echo "  FAIL  webhook for unknown order answered $HTTP3"
    FAILURES=$((FAILURES + 1))
fi

check "event stored but not applied" false \
    "SELECT applied::text FROM payment_events WHERE event_id = ?" "$EVENT3"
check "event marked retryable" order_missing \
    "SELECT result FROM payment_events WHERE event_id = ?" "$EVENT3"

# the creation request the webhook overtook finally lands
# A real order is a header plus at least one line, so the harness has to create both -
# a header on its own would leave the worker with nothing to deliver.
check "order row finally appears" "$ORDER3" \
    "WITH o AS (
         INSERT INTO orders (id, sku, amount, currency, status)
         SELECT ?, p.sku, p.price, p.currency, 'created' FROM products p WHERE p.sku = ?
         RETURNING id, sku, amount, currency
     ), i AS (
         INSERT INTO order_items (id, order_id, sku, amount, currency, supplier, status)
         SELECT 'itm_' || substr(md5(o.id), 1, 16), o.id, o.sku, o.amount, o.currency,
                (SELECT supplier FROM products WHERE sku = o.sku), 'pending'
         FROM o RETURNING order_id
     )
     SELECT id FROM o" \
    "$ORDER3" KEY-EFT

start_worker
echo "worker restarted (pid $WORKER_PID)"
wait_for_status "$ORDER3" delivered || echo "  (timed out waiting for delivered)"

check "pending event applied on a later pass" true \
    "SELECT applied::text FROM payment_events WHERE event_id = ?" "$EVENT3"
check "result flipped to applied" applied \
    "SELECT result FROM payment_events WHERE event_id = ?" "$EVENT3"
check "order delivered by the worker" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER3"
check "keys reserved in key_pool" 1 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER3"

# ---------------------------------------------------------------------------
echo
echo "=== global invariants ==="
check "issued requests == reserved keys" 0 \
    "SELECT (SELECT count(*) FROM issue_requests WHERE status = 'issued')
          - (SELECT count(*) FROM key_pool WHERE order_id IS NOT NULL)"
check "issued requests == supplier issues" 0 \
    "SELECT (SELECT count(*) FROM issue_requests WHERE status = 'issued')
          - (SELECT count(*) FROM supplier_issues WHERE code IS NOT NULL)"
check "no key reserved for two orders" 0 \
    "SELECT count(*) FROM (SELECT code FROM key_pool WHERE order_id IS NOT NULL
                           GROUP BY code HAVING count(DISTINCT order_id) > 1) d"
check "no order holds two codes" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM key_pool WHERE order_id IS NOT NULL
                           GROUP BY order_id HAVING count(*) > 1) d"
check "no delivered order without a code" 0 \
    "SELECT count(*) FROM orders o WHERE o.status = 'delivered'
     AND NOT EXISTS (SELECT 1 FROM issue_requests r
                     WHERE r.order_id = o.id AND r.status = 'issued' AND r.code IS NOT NULL)"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
