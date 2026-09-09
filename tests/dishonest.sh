#!/usr/bin/env bash
#
# Stage 2, task 2: a supplier whose answers cannot be trusted.
#
#   make dishonest     (or)   docker compose run --rm runner bash tests/dishonest.sh
#
# The stub is told to lie in three different ways - hand back a code that already belongs to
# someone else, invent a code that was never in the pool, and answer with an error for a request
# it actually fulfilled. What must hold regardless: one code never reaches two orders, every
# buyer ends up with exactly one working code, and every discrepancy is found and closed by the
# system itself.

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
export REFUND_AFTER_SEC=600
export AUDIT_GRACE_SEC=2
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

# suppliers <a_dup> <a_foreign> <a_error_after_issue> <b_dup> <b_foreign> <b_error_after_issue>
suppliers() {
    kill_group "$SUP_A_PID"; kill_group "$SUP_B_PID"
    wait_port_down 9001; wait_port_down 9002
    SUPPLIER_NAME=A DUPLICATE_RATE="$1" FOREIGN_CODE_RATE="$2" ERROR_AFTER_ISSUE_RATE="$3" TIMEOUT_SEC="$SUPPLIER_HANG_SEC" PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 suppliers/supplier.php >>/tmp/supplier-a.log 2>&1 &
    SUP_A_PID=$!
    SUPPLIER_NAME=B DUPLICATE_RATE="$4" FOREIGN_CODE_RATE="$5" ERROR_AFTER_ISSUE_RATE="$6" TIMEOUT_SEC="$SUPPLIER_HANG_SEC" PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9002 suppliers/supplier.php >>/tmp/supplier-b.log 2>&1 &
    SUP_B_PID=$!
    wait_port_up 9001; wait_port_up 9002
    echo "  stubs: A dup=$1 foreign=$2 err_after=$3 | B dup=$4 foreign=$5 err_after=$6"
}

start_worker() { php bin/worker.php >>/tmp/worker.log 2>&1 & WORKER_PID=$!; }
stop_worker()  { kill_group "$WORKER_PID"; WORKER_PID=""; }

new_order() {
    curl -s -X POST "$APP_URL/api/orders" -H 'Content-Type: application/json' -d "{\"sku\":\"$1\"}" \
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

# both cases below inject their faults into supplier A, so the sku has to be one A owns
pick_sku() {
    php -r 'require "vendor/autoload.php";
        echo App\Db::one("SELECT sku FROM products WHERE supplier = ? AND sku NOT LIKE ? ORDER BY sku OFFSET ? LIMIT 1",
            ["A", "LOAD-%", (int) $argv[1]])["sku"];' "$1"
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

SKU_1=$(pick_sku 0); SKU_2=$(pick_sku 1); SKU_3=$(pick_sku 2); SKU_4=$(pick_sku 3)
echo "skus with supplier A: $SKU_1 $SKU_2 $SKU_3 $SKU_4"

# a first, honest order gives the duplicate injection something to steal
suppliers 0 0 0 0 0 0
SEED_ORDER=$(new_order "$SKU_1")
php bin/paysim.php "$SEED_ORDER" --status=paid --n=1 >/dev/null
start_worker
wait_final "$SEED_ORDER" || echo "  (seed order never finished)"
stop_worker
SEED_CODE=$(php -r 'require "vendor/autoload.php"; echo App\Db::one("SELECT code FROM order_items WHERE order_id = ?", [$argv[1]])["code"];' "$SEED_ORDER")
echo "  honest order $SEED_ORDER holds $SEED_CODE"

# ---------------------------------------------------------------------------
echo
echo "=== case 1: A hands back a code that already belongs to another order ==="
suppliers 1.0 0 0 0 0 0
ORDER1=$(new_order "$SKU_2")
echo "  order $ORDER1"
php bin/paysim.php "$ORDER1" --status=paid --n=1 >/dev/null
start_worker
wait_final "$ORDER1" || echo "  (order never reached a final status)"
stop_worker

check "the duplicate was refused, order still delivered" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER1"
check "the stolen code stayed with its original order" "$SEED_ORDER" \
    "SELECT order_id FROM order_items WHERE code = ?" "$SEED_CODE"
check "the new order did NOT get the stolen code" 0 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND code = ?" "$ORDER1" "$SEED_CODE"
check "A recorded a duplicate_code discrepancy" 1 \
    "SELECT count(*) FROM supplier_discrepancies
     WHERE order_id = ? AND kind = 'duplicate_code' AND supplier = 'A'" "$ORDER1"
check "the discrepancy was closed automatically" 0 \
    "SELECT count(*) FROM supplier_discrepancies WHERE order_id = ? AND resolved_at IS NULL" "$ORDER1"
check "A is marked as having answered with an unusable code" 1 \
    "SELECT count(*) FROM issue_requests
     WHERE order_id = ? AND supplier = 'A' AND status = 'invalid_code'" "$ORDER1"
check "the line was served by the fallback" 1 \
    "SELECT count(*) FROM order_items WHERE order_id = ? AND supplier = 'B' AND status = 'delivered'" "$ORDER1"

# ---------------------------------------------------------------------------
echo
echo "=== case 2: A invents a code that was never in the pool ==="
suppliers 0 1.0 0 0 0 0
ORDER2=$(new_order "$SKU_3")
echo "  order $ORDER2"
php bin/paysim.php "$ORDER2" --status=paid --n=1 >/dev/null
start_worker
wait_final "$ORDER2" || echo "  (order never reached a final status)"
stop_worker

check "the invented code was refused, order still delivered" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER2"
check "the delivered code is a real key from the pool" 1 \
    "SELECT count(*) FROM order_items i JOIN key_pool k ON k.code = i.code
     WHERE i.order_id = ? AND i.status = 'delivered'" "$ORDER2"
check "A recorded a foreign_code discrepancy" 1 \
    "SELECT count(*) FROM supplier_discrepancies
     WHERE order_id = ? AND kind = 'foreign_code' AND supplier = 'A'" "$ORDER2"
check "no invented code was ever stored on a line" 0 \
    "SELECT count(*) FROM order_items WHERE code LIKE 'XXXX-%'"

# ---------------------------------------------------------------------------
echo
echo "=== case 3: A answers 500 for a request it actually fulfilled ==="
suppliers 0 0 1.0 0 0 0
ORDER3=$(new_order "$SKU_4")
echo "  order $ORDER3"
php bin/paysim.php "$ORDER3" --status=paid --n=1 >/dev/null
start_worker
wait_final "$ORDER3" || echo "  (order never reached a final status)"
stop_worker

check "order delivered despite the error" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER3"
check "exactly one key left the pool for this order" 1 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER3"
check "the fallback was never asked" 0 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ? AND supplier = 'B'" "$ORDER3"
check "the delivered code is the one A had already issued" 1 \
    "SELECT count(*) FROM order_items i JOIN supplier_issues s ON s.code = i.code
     WHERE i.order_id = ? AND s.supplier = 'A'" "$ORDER3"
check "the lie was recorded as error_after_issue" 1 \
    "SELECT count(*) FROM supplier_discrepancies
     WHERE order_id = ? AND kind = 'error_after_issue'" "$ORDER3"
check "and closed by confirmation, not by hand" 1 \
    "SELECT count(*) FROM supplier_discrepancies
     WHERE order_id = ? AND kind = 'error_after_issue'
       AND resolution = 'code recovered by confirmation' AND resolved_at IS NOT NULL" "$ORDER3"

# ---------------------------------------------------------------------------
echo
echo "=== case 4: 12 orders against two suppliers lying in every way at once ==="
suppliers 0.3 0.3 0.3 0.3 0.3 0.3
IDS=()
for _ in $(seq 1 12); do
    OID=$(new_order "$SKU_1")
    IDS+=("$OID")
    php bin/paysim.php "$OID" --status=paid --n=1 >/dev/null
done
echo "  12 orders paid"
start_worker
for OID in "${IDS[@]}"; do
    wait_final "$OID" 90 || echo "  (order $OID never reached a final status)"
done
# let the audit pass run over whatever residue is left
sleep 5
stop_worker

note "orders delivered"          "SELECT count(*) FROM orders WHERE status = 'delivered'"
note "discrepancies recorded"    "SELECT count(*) FROM supplier_discrepancies"
note "  of them duplicate_code"  "SELECT count(*) FROM supplier_discrepancies WHERE kind = 'duplicate_code'"
note "  of them foreign_code"    "SELECT count(*) FROM supplier_discrepancies WHERE kind = 'foreign_code'"
note "  of them error_after"     "SELECT count(*) FROM supplier_discrepancies WHERE kind = 'error_after_issue'"
note "  of them orphaned keys"   "SELECT count(*) FROM supplier_discrepancies WHERE kind = 'orphaned_reservation'"
note "still open"                "SELECT count(*) FROM supplier_discrepancies WHERE resolved_at IS NULL"

check "the run actually provoked discrepancies" 1 \
    "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END FROM supplier_discrepancies"

# ---------------------------------------------------------------------------
echo
echo "=== invariants that must hold whatever the supplier says ==="
check "one code never reaches two lines" 0 \
    "SELECT count(*) FROM (SELECT code FROM order_items WHERE code IS NOT NULL
                           GROUP BY code HAVING count(*) > 1) d"
check "one code never reaches two orders" 0 \
    "SELECT count(*) FROM (SELECT code FROM order_items WHERE code IS NOT NULL
                           GROUP BY code HAVING count(DISTINCT order_id) > 1) d"
check "every delivered line has exactly one code" 0 \
    "SELECT count(*) FROM order_items WHERE status = 'delivered' AND code IS NULL"
check "every delivered code is a real key of ours" 0 \
    "SELECT count(*) FROM order_items i
     WHERE i.status = 'delivered' AND NOT EXISTS (SELECT 1 FROM key_pool k WHERE k.code = i.code)"
check "every delivered code was reserved for its own order" 0 \
    "SELECT count(*) FROM order_items i JOIN key_pool k ON k.code = i.code
     WHERE i.status = 'delivered' AND k.order_id <> i.order_id"
check "no invented code anywhere in the system" 0 \
    "SELECT count(*) FROM order_items WHERE code LIKE 'XXXX-%'"
check "no key is reserved and unattached after the audit" 0 \
    "SELECT count(*) FROM key_pool k
     WHERE k.order_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.code = k.code)
       AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = k.order_id
                   AND i.status IN ('delivered', 'refunded'))"
check "money still balances on every finished order" 0 \
    "SELECT count(*) FROM (
         SELECT o.id FROM orders o JOIN ledger l ON l.order_id = o.id
         WHERE o.status IN ('delivered','partially_delivered','refunded')
         GROUP BY o.id
         HAVING COALESCE(sum(l.amount) FILTER (WHERE l.type = 'payment_received'),0)
             <> COALESCE(sum(l.amount) FILTER (WHERE l.type = 'revenue_recognised'),0)
              + COALESCE(sum(l.amount) FILTER (WHERE l.type = 'refund_issued'),0)) d"

if php bin/ledger_check.php > /tmp/ledger.json; then
    echo "  PASS  ledger_check balances"
else
    echo "  FAIL  ledger_check reported discrepancies"; cat /tmp/ledger.json; FAILURES=$((FAILURES + 1))
fi

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
