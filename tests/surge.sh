#!/usr/bin/env bash
#
# Stage 2, task 3: far more orders than the supplier will accept per minute.
#
#   make surge         (or)   docker compose run --rm runner bash tests/surge.sh
#
# The stub is given a hard limit and told to answer 429 past it. What must hold: the limit is
# never exceeded, nothing is lost or failed just because it had to wait, paid orders are served
# ahead of unpaid ones, and the progress of the queue is visible while it drains.

set -uo pipefail
set -m
cd "$(dirname "$0")/.." || exit 1

export DELIVERY_TIMEOUT_SEC=2
export DELIVERY_CONNECT_TIMEOUT_SEC=1
export DELIVERY_MAX_ATTEMPTS=2
export DELIVERY_BACKOFF_BASE_MS=50
export DELIVERY_BACKOFF_CAP_MS=150
export RECOVERY_INTERVAL_SEC=2
export RECOVERY_DELAY_SEC=5
# a queue is not a failure: nothing may be refunded just for waiting its turn
export REFUND_AFTER_SEC=600
export WORKER_SLEEP_MS=100

# the supplier accepts this many issue calls a minute; we pace ourselves to the same number
LIMIT_PER_MIN=20
ORDERS=30
UNPAID=6

export SUPPLIER_RATE_LIMIT_PER_MIN=$LIMIT_PER_MIN

APP_URL="${APP_URL:-http://127.0.0.1:8000}"
FAILURES=0
APP_PID=""
SUP_A_PID=""
SUP_B_PID=""
WORKERS=()

check() {
    php tests/race_assert.php "$@" || FAILURES=$((FAILURES + 1))
}

note() {
    local value
    value=$(php -r 'require "vendor/autoload.php"; echo array_values((array) App\Db::one($argv[1]))[0];' "$2")
    printf "  ..    %-58s = %s\n" "$1" "$value"
}

kill_group() {
    [ -n "$1" ] || return 0
    { kill -- "-$1" 2>/dev/null; wait "$1"; } 2>/dev/null
}

stop_workers() {
    for p in "${WORKERS[@]:-}"; do kill_group "$p"; done
    WORKERS=()
}

cleanup() {
    stop_workers; kill_group "$SUP_A_PID"; kill_group "$SUP_B_PID"; kill_group "$APP_PID"
}
trap cleanup EXIT

probe_supplier() { curl -s -o /dev/null --max-time 1 -X POST "http://127.0.0.1:$1/issue" -d '{}'; }
wait_port_up() { for _ in $(seq 1 100); do probe_supplier "$1" && return 0; sleep 0.1; done; echo "  FAIL  port $1 never came up"; FAILURES=$((FAILURES + 1)); }

new_order() {
    curl -s -X POST "$APP_URL/api/orders" -H 'Content-Type: application/json' -d "{\"sku\":\"$1\"}" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";'
}

pick_sku() {
    php -r 'require "vendor/autoload.php";
        echo App\Db::one("SELECT sku FROM products WHERE supplier = ? AND sku NOT LIKE ? ORDER BY sku OFFSET ? LIMIT 1",
            ["A", "LOAD-%", (int) $argv[1]])["sku"];' "$1"
}

# bind the value - a $$literal$$ here would be eaten by PHP string interpolation, the function
# would return nothing, and every wait loop below would silently run to its full deadline
delivered_lines() {
    php -r 'require "vendor/autoload.php";
        echo App\Db::one("SELECT count(*) c FROM order_items WHERE status = ?", ["delivered"])["c"];'
}

echo "=== setup ==="
[ -f vendor/autoload.php ] || composer install --no-interaction --quiet
php bin/migrate.php
php bin/seed.php
php tests/race_reset.php
# the pool has to be deep enough that nothing fails for lack of stock
php bin/seed_load.php --skus=1 --keys-per-sku=200 >/dev/null

PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t public >/tmp/app.log 2>&1 &
APP_PID=$!
for _ in $(seq 1 60); do curl -sf "$APP_URL/health" >/dev/null && break; sleep 0.25; done

SUPPLIER_NAME=A RATE_LIMIT_PER_MIN=$LIMIT_PER_MIN PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 suppliers/supplier.php >>/tmp/supplier-a.log 2>&1 &
SUP_A_PID=$!
SUPPLIER_NAME=B RATE_LIMIT_PER_MIN=$LIMIT_PER_MIN PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9002 suppliers/supplier.php >>/tmp/supplier-b.log 2>&1 &
SUP_B_PID=$!
wait_port_up 9001; wait_port_up 9002
echo "app and stubs up, limit ${LIMIT_PER_MIN}/min per supplier"

SKU=$(pick_sku 0)
echo "sku under test: $SKU (supplier A)"

# ---------------------------------------------------------------------------
echo
echo "=== $ORDERS orders at once, $UNPAID of them never paid ==="
PAID_IDS=(); UNPAID_IDS=()
for i in $(seq 1 "$ORDERS"); do
    OID=$(new_order "$SKU")
    if [ "$i" -le "$UNPAID" ]; then
        UNPAID_IDS+=("$OID")
    else
        PAID_IDS+=("$OID")
        php bin/paysim.php "$OID" --status=paid --n=1 >/dev/null
    fi
done
echo "  ${#PAID_IDS[@]} paid, ${#UNPAID_IDS[@]} left unpaid"

# three workers, so the limit has to hold across processes and not just inside one
for _ in 1 2 3; do php bin/worker.php >>/tmp/worker.log 2>&1 & WORKERS+=($!); done
echo "  3 workers started"

echo "  --- queue draining (limit is ${LIMIT_PER_MIN}/min, so this takes a couple of minutes) ---"
DEADLINE=$((SECONDS + 300))
while [ $SECONDS -lt $DEADLINE ]; do
    D=$(delivered_lines)
    [ "$D" -ge "${#PAID_IDS[@]}" ] && break
    Q=$(curl -s "$APP_URL/api/admin/queue")
    echo "$Q" | php -r '$d = json_decode(stream_get_contents(STDIN), true);
        $a = $d["suppliers"][0];
        printf("        delivered=%-3d awaiting=%-3d in_flight=%-2d | A used=%d/%s\n",
            $d["lines"]["delivered"], $d["lines"]["awaiting_delivery"], $d["lines"]["in_flight"],
            $a["used_last_min"], $a["limit_per_min"]);'
    sleep 10
done
stop_workers

# ---------------------------------------------------------------------------
echo
echo "=== results ==="
note "supplier calls accepted, A" "SELECT count(*) FROM supplier_calls WHERE supplier = 'A'"
note "supplier calls accepted, B" "SELECT count(*) FROM supplier_calls WHERE supplier = 'B'"
note "slots we took, A"           "SELECT count(*) FROM delivery_slots WHERE supplier = 'A'"

check "every paid order was delivered, nothing lost" "${#PAID_IDS[@]}" \
    "SELECT count(*) FROM orders WHERE status = 'delivered'"
check "no paid order failed just for waiting" 0 \
    "SELECT count(*) FROM orders WHERE status IN ('delivery_failed', 'out_of_stock', 'refunded', 'partially_delivered')"
check "nothing was refunded" 0 "SELECT count(*) FROM ledger WHERE type = 'refund_issued'"
check "unpaid orders were never delivered" "$UNPAID" \
    "SELECT count(*) FROM orders WHERE status = 'created'"
check "no supplier call was ever made for an unpaid order" 0 \
    "SELECT count(*) FROM issue_requests r JOIN orders o ON o.id = r.order_id WHERE o.status = 'created'"

# The limit is per rolling minute, so this is the real check: at no instant did the calls the
# supplier accepted in the preceding 60 seconds go above what it allows.
check "the supplier limit was never exceeded in any 60s window" 0 \
    "SELECT count(*) FROM (
         SELECT c.called_at,
                (SELECT count(*) FROM supplier_calls x
                 WHERE x.supplier = c.supplier
                   AND x.called_at > c.called_at - interval '1 minute'
                   AND x.called_at <= c.called_at) AS in_window
         FROM supplier_calls c
     ) w WHERE w.in_window > $LIMIT_PER_MIN"

check "the stub never had to answer 429" 0 \
    "SELECT count(*) FROM issue_requests WHERE last_error LIKE '%429%'"

# ---------------------------------------------------------------------------
echo
echo "=== the rest of the queue drains once the money arrives ==="
for OID in "${UNPAID_IDS[@]}"; do php bin/paysim.php "$OID" --status=paid --n=1 >/dev/null; done
echo "  remaining $UNPAID orders paid"
for _ in 1 2 3; do php bin/worker.php >>/tmp/worker.log 2>&1 & WORKERS+=($!); done

DEADLINE=$((SECONDS + 180))
while [ $SECONDS -lt $DEADLINE ]; do
    [ "$(delivered_lines)" -ge "$ORDERS" ] && break
    sleep 5
done
stop_workers

check "every order is delivered now" "$ORDERS" \
    "SELECT count(*) FROM orders WHERE status = 'delivered'"
check "the limit still held for the whole run" 0 \
    "SELECT count(*) FROM (
         SELECT c.called_at,
                (SELECT count(*) FROM supplier_calls x
                 WHERE x.supplier = c.supplier
                   AND x.called_at > c.called_at - interval '1 minute'
                   AND x.called_at <= c.called_at) AS in_window
         FROM supplier_calls c
     ) w WHERE w.in_window > $LIMIT_PER_MIN"
check "one code per order, none shared" 0 \
    "SELECT count(*) FROM (SELECT code FROM order_items WHERE code IS NOT NULL
                           GROUP BY code HAVING count(*) > 1) d"
check "money balances on every order" 0 \
    "SELECT count(*) FROM (
         SELECT o.id FROM orders o JOIN ledger l ON l.order_id = o.id
         WHERE o.status IN ('delivered','partially_delivered','refunded')
         GROUP BY o.id
         HAVING COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'),0)
             <> COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'),0)
              + COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'),0)) d"

HTTP=$(curl -s -o /tmp/queue.json -w '%{http_code}' "$APP_URL/api/admin/queue")
if [ "$HTTP" = "200" ]; then
    echo "  PASS  GET /api/admin/queue answered 200"
    php -r '$d = json_decode(file_get_contents("/tmp/queue.json"), true);
        printf("  ..    %-58s = %d delivered, %d awaiting, %d in flight\n", "final queue snapshot",
            $d["lines"]["delivered"], $d["lines"]["awaiting_delivery"], $d["lines"]["in_flight"]);'
else
    echo "  FAIL  GET /api/admin/queue answered $HTTP"; FAILURES=$((FAILURES + 1))
fi

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
