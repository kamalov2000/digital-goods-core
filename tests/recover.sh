#!/usr/bin/env bash
#
# Stage 4: recovery, ledger and reconciliation.
#
#   make recover       (or)   docker compose run --rm runner bash tests/recover.sh
#
# Phase 1 runs a chaos load with recovery switched off and a deliberately short pool, so it
# leaves behind exactly the residue stage 4 is meant to clear: orders in delivery_failed with
# an unresolved 'unknown' attempt, orders in out_of_stock, and keys reserved to orders that
# were never delivered.
# Phase 2 calms the suppliers, restocks, turns recovery on and requires that EVERY order ends
# delivered, with the money journal and the reconciliation report still clean.

set -uo pipefail
set -m
cd "$(dirname "$0")/.." || exit 1

export DELIVERY_TIMEOUT_SEC=1
export DELIVERY_CONNECT_TIMEOUT_SEC=1
export DELIVERY_MAX_ATTEMPTS=2
export DELIVERY_BACKOFF_BASE_MS=50
export DELIVERY_BACKOFF_CAP_MS=150
export RECOVERY_INTERVAL_SEC=1
SUPPLIER_HANG_SEC=3
ORDER_COUNT=12
POOL_DURING_CHAOS=7

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
    kill_group "$WORKER_PID"
    kill_group "$SUP_A_PID"
    kill_group "$SUP_B_PID"
    kill_group "$APP_PID"
}
trap cleanup EXIT

probe_supplier() { curl -s -o /dev/null --max-time 1 -X POST "http://127.0.0.1:$1/issue" -d '{}'; }

wait_port_down() {
    for _ in $(seq 1 100); do probe_supplier "$1" || return 0; sleep 0.1; done
    echo "  FAIL  port $1 still serving after kill"; FAILURES=$((FAILURES + 1)); return 1
}

wait_port_up() {
    for _ in $(seq 1 100); do probe_supplier "$1" && return 0; sleep 0.1; done
    echo "  FAIL  port $1 never came up"; FAILURES=$((FAILURES + 1)); return 1
}

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

# recovery delay is a per-process env, which is how phase 1 keeps recovery out of the way
start_worker() { RECOVERY_DELAY_SEC="$1" php bin/worker.php >>/tmp/worker.log 2>&1 & WORKER_PID=$!; }
stop_worker() { kill_group "$WORKER_PID"; WORKER_PID=""; }

new_order() {
    curl -s -X POST "$APP_URL/api/orders" -H 'Content-Type: application/json' -d "{\"sku\":\"$1\"}" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";'
}

# every value goes in as a bound ? - a $1 here is a postgres placeholder PDO does not bind,
# which silently returns 0 and makes the waits below lie
count_status() {
    php -r 'require "vendor/autoload.php"; echo App\Db::one("SELECT count(*) c FROM orders WHERE status = ANY(string_to_array(?, ?))", [$argv[1], ","])["c"];' "$1"
}

wait_all_terminal() {
    local deadline=$((SECONDS + $1))
    while [ $SECONDS -lt $deadline ]; do
        [ "$(count_status 'created,paid,delivering')" = "0" ] && return 0
        sleep 0.3
    done
    return 1
}

wait_all_delivered() {
    local deadline=$((SECONDS + $1))
    while [ $SECONDS -lt $deadline ]; do
        [ "$(count_status 'delivered')" = "$ORDER_COUNT" ] && return 0
        sleep 0.3
    done
    return 1
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

# ---------------------------------------------------------------------------
echo
echo "=== phase 1: chaos with recovery off and a short pool ==="
suppliers 0.35 0.45 0.35 0.35
# leave only a handful of keys so some orders genuinely run out of stock
php -r 'require "vendor/autoload.php"; App\Db::run("DELETE FROM key_pool WHERE code IN (SELECT code FROM key_pool WHERE order_id IS NULL OFFSET " . (int) $argv[1] . ")"); App\Stock::recompute();' "$POOL_DURING_CHAOS"
note "free keys in the pool" "SELECT count(*) FROM key_pool WHERE order_id IS NULL"

for _ in $(seq 1 "$ORDER_COUNT"); do
    OID=$(new_order KEY-CS2-PRIME)
    php bin/paysim.php "$OID" --status=paid --n=1 >/dev/null
done
echo "  $ORDER_COUNT orders paid"

start_worker 99999
if wait_all_terminal 180; then
    echo "  phase 1 settled"
else
    echo "  FAIL  phase 1 never settled - the residue below is not a stable snapshot"
    FAILURES=$((FAILURES + 1))
fi
stop_worker

echo "  --- residue left for stage 4 ---"
note "delivered"                       "SELECT count(*) FROM orders WHERE status = 'delivered'"
note "delivery_failed"                 "SELECT count(*) FROM orders WHERE status = 'delivery_failed'"
note "out_of_stock"                    "SELECT count(*) FROM orders WHERE status = 'out_of_stock'"
note "unresolved (unknown) attempts"   "SELECT count(*) FROM issue_requests WHERE status = 'unknown'"
note "orphaned keys"                   "SELECT count(*) FROM key_pool k JOIN orders o ON o.id = k.order_id WHERE o.status <> 'delivered'"

check "every order was paid and booked" "$ORDER_COUNT" \
    "SELECT count(*) FROM ledger WHERE type = 'payment_received'"

# Snapshot exactly which orders stalled, so the end of the run can prove that recovery - and
# not some lucky re-delivery - is what finished them.
php -r 'require "vendor/autoload.php"; foreach (App\Db::all("SELECT id FROM orders WHERE status <> ?", ["delivered"]) as $r) { echo $r["id"], PHP_EOL; }' > /tmp/residue.txt
RESIDUE=$(grep -c . /tmp/residue.txt)
RESIDUE_IDS=$(paste -sd, /tmp/residue.txt)
echo "  stalled orders handed to recovery: $RESIDUE"

if [ "$RESIDUE" -ge 1 ]; then
    echo "  PASS  phase 1 left real work for recovery"
else
    echo "  FAIL  phase 1 left nothing to recover - the phase 2 checks would be vacuous"
    FAILURES=$((FAILURES + 1))
fi

# ---------------------------------------------------------------------------
echo
echo "=== phase 2: calm suppliers, restock, recovery on ==="
suppliers 0 0 0 0
php bin/seed.php >/dev/null
note "free keys after restock" "SELECT count(*) FROM key_pool WHERE order_id IS NULL"

start_worker 1
wait_all_delivered 120 || echo "  (not every order reached delivered)"
stop_worker

check "ALL orders delivered" "$ORDER_COUNT" \
    "SELECT count(*) FROM orders WHERE status = 'delivered'"
check "no order left in a recoverable status" 0 \
    "SELECT count(*) FROM orders WHERE status IN ('out_of_stock', 'delivery_failed', 'paid', 'delivering')"
check "no unresolved attempts left" 0 \
    "SELECT count(*) FROM issue_requests WHERE status = 'unknown'"
check "no orphaned keys left" 0 \
    "SELECT count(*) FROM key_pool k JOIN orders o ON o.id = k.order_id WHERE o.status <> 'delivered'"
check "every stalled order was recovered" "$RESIDUE" \
    "SELECT count(*) FROM orders WHERE status = 'delivered' AND id = ANY(string_to_array(?, ','))" "$RESIDUE_IDS"

# ---------------------------------------------------------------------------
echo
echo "=== invariants: recovery must not have doubled anything ==="
check "one key per order" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM key_pool WHERE order_id IS NOT NULL
                           GROUP BY order_id HAVING count(*) > 1) d"
check "no order issued a code by two suppliers" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM supplier_issues WHERE code IS NOT NULL
                           GROUP BY order_id HAVING count(DISTINCT supplier) > 1) d"
check "reserved keys == supplier issues" 0 \
    "SELECT (SELECT count(*) FROM key_pool WHERE order_id IS NOT NULL)
          - (SELECT count(*) FROM supplier_issues WHERE code IS NOT NULL)"
check "no delivered order without a code" 0 \
    "SELECT count(*) FROM orders o WHERE o.status = 'delivered'
     AND NOT EXISTS (SELECT 1 FROM issue_requests r
                     WHERE r.order_id = o.id AND r.status = 'issued' AND r.code IS NOT NULL)"
check "exactly one ledger entry per order" "$ORDER_COUNT" \
    "SELECT count(*) FROM ledger"

# ---------------------------------------------------------------------------
echo
echo "=== ledger and reconciliation ==="
if php bin/ledger_check.php > /tmp/ledger.json; then
    echo "  PASS  ledger_check balances"
else
    echo "  FAIL  ledger_check reported discrepancies"; cat /tmp/ledger.json; FAILURES=$((FAILURES + 1))
fi
php -r '$d = json_decode(file_get_contents("/tmp/ledger.json"), true); printf("  ..    %-58s = %s\n", "ledger total vs paid orders total", $d["ledger_total"] . " / " . $d["paid_orders_total"]);'

if php bin/reconcile.php --stale-minutes=0 > /tmp/reconcile.json; then
    echo "  PASS  reconcile clean (exit 0)"
else
    echo "  FAIL  reconcile reported a broken invariant"; cat /tmp/reconcile.json; FAILURES=$((FAILURES + 1))
fi
php -r '$d = json_decode(file_get_contents("/tmp/reconcile.json"), true);
    foreach (["paid_not_delivered", "delivered_not_paid", "orphaned_keys", "stock_drift"] as $k) {
        printf("  ..    %-58s = %d\n", $k, $d[$k]["count"]);
    }'

check "delivered-but-not-paid list is empty" 0 \
    "SELECT count(*) FROM orders o
     JOIN issue_requests r ON r.order_id = o.id AND r.status = 'issued'
     WHERE NOT EXISTS (SELECT 1 FROM payment_events e
                       WHERE e.order_id = o.id AND e.status = 'paid' AND e.applied AND e.result = 'applied')"

HTTP=$(curl -s -o /tmp/recon_http.json -w '%{http_code}' "$APP_URL/api/admin/reconcile?stale_minutes=0")
if [ "$HTTP" = "200" ]; then
    echo "  PASS  GET /api/admin/reconcile answered 200"
else
    echo "  FAIL  GET /api/admin/reconcile answered $HTTP"; FAILURES=$((FAILURES + 1))
fi

echo
echo "=== one order, end to end, from the logs ==="
SAMPLE=$(php -r 'require "vendor/autoload.php"; echo App\Db::one("SELECT id FROM orders ORDER BY created_at LIMIT 1")["id"];')
echo "  grep $SAMPLE:"
grep -h "$SAMPLE" /tmp/app.log /tmp/worker.log 2>/dev/null | grep -o '{"ts.*}' | head -12 | sed 's/^/    /'

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
