#!/usr/bin/env bash
#
# Stage 2, task 1: a basket where part of it cannot be delivered.
#
#   make partial       (or)   docker compose run --rm runner bash tests/partial.sh
#
# What it proves: delivered lines stay with the buyer, undeliverable lines are refunded, the
# money identity paid = delivered + refunded holds at every point, replaying any step creates
# neither a second code nor a second refund, and an order still reaches a final state after the
# worker is killed in the middle of the delivery.

set -uo pipefail
set -m
cd "$(dirname "$0")/.." || exit 1

export DELIVERY_TIMEOUT_SEC=2
export DELIVERY_CONNECT_TIMEOUT_SEC=1
export DELIVERY_MAX_ATTEMPTS=2
export DELIVERY_BACKOFF_BASE_MS=50
export DELIVERY_BACKOFF_CAP_MS=150
export RECOVERY_INTERVAL_SEC=1
export RECOVERY_DELAY_SEC=1
# short enough for a test, still well above RECOVERY_DELAY_SEC so recovery gets its chances
export REFUND_AFTER_SEC=3
SUPPLIER_HANG_SEC=6

APP_URL="${APP_URL:-http://127.0.0.1:8000}"
FAILURES=0
APP_PID=""
WORKER_PID=""
SUP_A_PID=""
SUP_B_PID=""

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

cleanup() {
    kill_group "$WORKER_PID"; kill_group "$SUP_A_PID"; kill_group "$SUP_B_PID"; kill_group "$APP_PID"
}
trap cleanup EXIT

probe_supplier() { curl -s -o /dev/null --max-time 1 -X POST "http://127.0.0.1:$1/issue" -d '{}'; }
wait_port_down() { for _ in $(seq 1 100); do probe_supplier "$1" || return 0; sleep 0.1; done; echo "  FAIL  port $1 still up"; FAILURES=$((FAILURES + 1)); }
wait_port_up()   { for _ in $(seq 1 100); do probe_supplier "$1" && return 0; sleep 0.1; done; echo "  FAIL  port $1 never came up"; FAILURES=$((FAILURES + 1)); }

suppliers() {
    kill_group "$SUP_A_PID"; kill_group "$SUP_B_PID"
    wait_port_down 9001; wait_port_down 9002
    SUPPLIER_NAME=A FAIL_RATE="$1" TIMEOUT_RATE="$2" TIMEOUT_SEC="$SUPPLIER_HANG_SEC" PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 suppliers/supplier.php >>/tmp/supplier-a.log 2>&1 &
    SUP_A_PID=$!
    SUPPLIER_NAME=B FAIL_RATE="$3" TIMEOUT_RATE="$4" TIMEOUT_SEC="$SUPPLIER_HANG_SEC" PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9002 suppliers/supplier.php >>/tmp/supplier-b.log 2>&1 &
    SUP_B_PID=$!
    wait_port_up 9001; wait_port_up 9002
    echo "  stubs: A fail=$1 timeout=$2 | B fail=$3 timeout=$4"
}

start_worker() { php bin/worker.php >>/tmp/worker.log 2>&1 & WORKER_PID=$!; }
stop_worker()  { kill_group "$WORKER_PID"; WORKER_PID=""; }

# {"items": [...]} - the basket shape added in stage 2
new_basket() {
    curl -s -X POST "$APP_URL/api/orders" -H 'Content-Type: application/json' -d "$1" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";'
}

order_status() {
    curl -s "$APP_URL/api/orders/$1" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["status"] ?? "";'
}

wait_final() {
    local id="$1" deadline=$((SECONDS + ${2:-60})) s
    while [ $SECONDS -lt $deadline ]; do
        s=$(order_status "$id")
        case "$s" in delivered|partially_delivered|refunded|payment_failed) return 0 ;; esac
        sleep 0.3
    done
    return 1
}

free_keys() { php -r 'require "vendor/autoload.php"; echo App\Db::one("SELECT count(*) c FROM key_pool WHERE order_id IS NULL")["c"];'; }

# keeps exactly $1 free keys in the pool, deleting the rest
trim_pool() {
    php -r 'require "vendor/autoload.php";
        App\Db::run("DELETE FROM key_pool WHERE code IN (SELECT code FROM key_pool WHERE order_id IS NULL OFFSET " . (int) $argv[1] . ")");
        App\Stock::recompute();' "$1"
}

echo "=== setup ==="
[ -f vendor/autoload.php ] || composer install --no-interaction --quiet
php bin/migrate.php
php bin/seed.php
php tests/race_reset.php

PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t public >/tmp/app.log 2>&1 &
APP_PID=$!
for _ in $(seq 1 60); do curl -sf "$APP_URL/health" >/dev/null && break; sleep 0.25; done
echo "app up"
suppliers 0 0 0 0

# ---------------------------------------------------------------------------
echo
echo "=== case 1: basket of 4 lines across both suppliers, all deliverable ==="
ORDER1=$(new_basket '{"items":[{"sku":"KEY-CS2-PRIME","qty":2},"KEY-GTA5","SUB-YT-3M"]}')
echo "  order $ORDER1"
check "order has 4 lines" 4 "SELECT count(*) FROM order_items WHERE order_id = ?" "$ORDER1"
check "lines are split across both suppliers" 2 \
    "SELECT count(DISTINCT supplier) FROM order_items WHERE order_id = ?" "$ORDER1"
check "order total is the sum of its lines" 0 \
    "SELECT o.amount - (SELECT sum(i.amount) FROM order_items i WHERE i.order_id = o.id)
     FROM orders o WHERE o.id = ?" "$ORDER1"

php bin/paysim.php "$ORDER1" --status=paid --n=1 >/dev/null
start_worker
wait_final "$ORDER1" || echo "  (order never reached a final status)"
stop_worker

check "order delivered" delivered "SELECT status FROM orders WHERE id = ?" "$ORDER1"
check "every line delivered" 4 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND status = 'delivered'" "$ORDER1"
check "4 distinct codes" 4 \
    "SELECT count(DISTINCT code) FROM order_items WHERE order_id = ?" "$ORDER1"
check "paid = delivered, nothing refunded" 0 \
    "SELECT (SELECT COALESCE(sum(amount),0) FROM ledger WHERE order_id = ? AND type = 'payment_received')
          - (SELECT COALESCE(sum(amount),0) FROM ledger WHERE order_id = ? AND type = 'revenue_recognised')" \
    "$ORDER1" "$ORDER1"
check "no refunds on a fully delivered order" 0 \
    "SELECT count(*) FROM ledger WHERE order_id = ? AND type = 'refund_issued'" "$ORDER1"

# ---------------------------------------------------------------------------
echo
echo "=== case 2: basket of 5 lines, only 2 keys left in the pool ==="
trim_pool 2
note "free keys before the basket" "SELECT count(*) FROM key_pool WHERE order_id IS NULL"
ORDER2=$(new_basket '{"items":[{"sku":"KEY-GTA5","qty":5}]}')
echo "  order $ORDER2"
php bin/paysim.php "$ORDER2" --status=paid --n=1 >/dev/null
start_worker
wait_final "$ORDER2" 90 || echo "  (order never reached a final status)"
stop_worker

check "order is partially_delivered" partially_delivered "SELECT status FROM orders WHERE id = ?" "$ORDER2"
check "2 lines delivered" 2 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND status = 'delivered'" "$ORDER2"
check "3 lines refunded" 3 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND status = 'refunded'" "$ORDER2"
check "delivered lines kept their codes" 2 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND status = 'delivered' AND code IS NOT NULL" "$ORDER2"
check "refunded lines carry no code" 0 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND status = 'refunded' AND code IS NOT NULL" "$ORDER2"
check "paid = delivered + refunded" 0 \
    "SELECT (SELECT COALESCE(sum(amount),0) FROM ledger WHERE order_id = ? AND type = 'payment_received')
          - (SELECT COALESCE(sum(amount),0) FROM ledger WHERE order_id = ? AND type IN ('revenue_recognised','refund_issued'))" \
    "$ORDER2" "$ORDER2"
check "one refund entry per refunded line" 3 \
    "SELECT count(*) FROM ledger WHERE order_id = ? AND type = 'refund_issued'" "$ORDER2"

# ---------------------------------------------------------------------------
echo
echo "=== case 3: replaying every step changes nothing ==="
LEDGER_BEFORE=$(php -r 'require "vendor/autoload.php"; echo App\Db::one("SELECT count(*) c FROM ledger")["c"];')
CODES_BEFORE=$(php -r 'require "vendor/autoload.php"; echo App\Db::one("SELECT count(*) c FROM order_items WHERE code IS NOT NULL")["c"];')

# same event_id again, plus a brand new event_id for an order that is already final
php bin/paysim.php "$ORDER2" --status=paid --n=5 >/dev/null
php bin/paysim.php "$ORDER2" --status=paid --n=3 --unique >/dev/null
start_worker
sleep 4
stop_worker

check "no ledger entry was added by the replay" "$LEDGER_BEFORE" "SELECT count(*) FROM ledger"
check "no code was added by the replay" "$CODES_BEFORE" \
    "SELECT count(*) FROM order_items WHERE code IS NOT NULL"
check "order is still partially_delivered" partially_delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER2"
check "exactly one payment booked for the order" 1 \
    "SELECT count(*) FROM ledger WHERE order_id = ? AND type = 'payment_received'" "$ORDER2"

# ---------------------------------------------------------------------------
echo
echo "=== case 4: worker killed in the middle of a basket ==="
php bin/seed.php >/dev/null
# every call hangs past the client budget, so the worker is guaranteed to be mid-delivery
suppliers 0 1.0 0 1.0
ORDER4=$(new_basket '{"items":[{"sku":"KEY-EFT","qty":4}]}')
echo "  order $ORDER4"
php bin/paysim.php "$ORDER4" --status=paid --n=1 >/dev/null
start_worker
sleep 3
echo "  killing the worker mid-delivery"
stop_worker
note "lines left mid-flight" \
    "SELECT count(*) FROM order_items WHERE order_id = '$ORDER4' AND status IN ('pending','delivering')"

suppliers 0 0 0 0
start_worker
wait_final "$ORDER4" 90 || echo "  (order never reached a final status)"
stop_worker

check "order reached a final state after the restart" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER4"
check "all 4 lines delivered, none twice" 4 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND status = 'delivered'" "$ORDER4"
check "4 distinct codes" 4 \
    "SELECT count(DISTINCT code) FROM order_items WHERE order_id = ?" "$ORDER4"

# ---------------------------------------------------------------------------
echo
echo "=== case 5: nothing deliverable at all ==="
trim_pool 0
ORDER5=$(new_basket '{"items":[{"sku":"SUB-SPOTIFY-1M","qty":3}]}')
echo "  order $ORDER5"
php bin/paysim.php "$ORDER5" --status=paid --n=1 >/dev/null
start_worker
wait_final "$ORDER5" 90 || echo "  (order never reached a final status)"
stop_worker

check "order is fully refunded" refunded "SELECT status FROM orders WHERE id = ?" "$ORDER5"
check "paid = refunded" 0 \
    "SELECT (SELECT COALESCE(sum(amount),0) FROM ledger WHERE order_id = ? AND type = 'payment_received')
          - (SELECT COALESCE(sum(amount),0) FROM ledger WHERE order_id = ? AND type = 'refund_issued')" \
    "$ORDER5" "$ORDER5"
check "nothing was delivered" 0 \
    "SELECT count(*) FROM ledger WHERE order_id = ? AND type = 'revenue_recognised'" "$ORDER5"

# ---------------------------------------------------------------------------
echo
echo "=== money and inventory invariants ==="
check "every final order satisfies paid = delivered + refunded" 0 \
    "SELECT count(*) FROM (
         SELECT o.id
         FROM orders o JOIN ledger l ON l.order_id = o.id
         WHERE o.status IN ('delivered','partially_delivered','refunded')
         GROUP BY o.id
         HAVING COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'),0)
             <> COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'),0)
              + COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'),0)) d"
check "no code sits on two lines" 0 \
    "SELECT count(*) FROM (SELECT code FROM order_items WHERE code IS NOT NULL
                           GROUP BY code HAVING count(*) > 1) d"
check "no line was both delivered and refunded" 0 \
    "SELECT count(*) FROM ledger a JOIN ledger b ON a.ref = b.ref
     WHERE a.type = 'revenue_recognised' AND b.type = 'refund_issued'"
check "delivered lines match revenue entries" 0 \
    "SELECT (SELECT count(*) FROM order_items WHERE status = 'delivered')
          - (SELECT count(*) FROM ledger WHERE type = 'revenue_recognised')"
check "refunded lines match refund entries" 0 \
    "SELECT (SELECT count(*) FROM order_items WHERE status = 'refunded')
          - (SELECT count(*) FROM ledger WHERE type = 'refund_issued')"
check "every delivered code really left the pool" 0 \
    "SELECT count(*) FROM order_items i
     WHERE i.status = 'delivered'
       AND NOT EXISTS (SELECT 1 FROM key_pool k WHERE k.code = i.code AND k.order_id = i.order_id)"

if php bin/ledger_check.php > /tmp/ledger.json; then
    echo "  PASS  ledger_check balances"
else
    echo "  FAIL  ledger_check reported discrepancies"; cat /tmp/ledger.json; FAILURES=$((FAILURES + 1))
fi
php -r '$d = json_decode(file_get_contents("/tmp/ledger.json"), true);
    printf("  ..    %-58s = %d / %d + %d\n", "paid / delivered + refunded (final orders)",
        $d["final_orders"]["paid"], $d["final_orders"]["delivered"], $d["final_orders"]["refunded"]);'

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
