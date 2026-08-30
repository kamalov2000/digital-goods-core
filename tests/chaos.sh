#!/usr/bin/env bash
#
# Stage 3 scenarios - acceptance criteria 4-6 of the assignment, plus a chaos run.
#
#   make chaos         (or)   docker compose run --rm runner bash tests/chaos.sh
#
# Runs inside the container: the supplier stubs need PHP_CLI_SERVER_WORKERS so that a stub
# hanging on an injected timeout does not block the retry queued behind it. Nothing is
# mocked - real HTTP, real timeouts, real worker, assertions in SQL.
#
# Client timeouts are squeezed right down here (2s total, 4 attempts, 100ms backoff base)
# so the whole suite finishes in seconds while still exercising the real code paths.

set -uo pipefail
# job control: every background job becomes its own process group, so killing -$PID takes
# down `php -S` together with all of its PHP_CLI_SERVER_WORKERS children and frees the port
set -m
cd "$(dirname "$0")/.." || exit 1

export DELIVERY_TIMEOUT_SEC=2
export DELIVERY_CONNECT_TIMEOUT_SEC=1
export DELIVERY_MAX_ATTEMPTS=4
export DELIVERY_BACKOFF_BASE_MS=100
export DELIVERY_BACKOFF_CAP_MS=400
# stub hangs must outlast the client budget above, otherwise the call would just succeed slowly
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
    stop_worker
    kill_group "$SUP_A_PID"
    kill_group "$SUP_B_PID"
    kill_group "$APP_PID"
}
trap cleanup EXIT

# A stub answers /issue even for a junk body, so a plain probe cannot tell a fresh process
# from a stale one. These two wait for the port to actually go down and come back up, which
# turns "the old stub is still serving with the old fault rates" into a visible failure.
wait_port_down() {
    for _ in $(seq 1 100); do
        curl -s -o /dev/null --max-time 1 -X POST "http://127.0.0.1:$1/issue" -d '{}' || return 0
        sleep 0.1
    done
    echo "  FAIL  port $1 still serving after kill"
    FAILURES=$((FAILURES + 1))
    return 1
}

wait_port_up() {
    for _ in $(seq 1 100); do
        curl -s -o /dev/null --max-time 1 -X POST "http://127.0.0.1:$1/issue" -d '{}' && return 0
        sleep 0.1
    done
    echo "  FAIL  port $1 never came up"
    FAILURES=$((FAILURES + 1))
    return 1
}

# Restarts both stubs with per-scenario fault rates. Ports stay fixed because the app process
# resolved SUPPLIER_A_URL / SUPPLIER_B_URL once, at startup.
#   suppliers <a_fail> <a_timeout> <b_fail> <b_timeout>
suppliers() {
    kill_group "$SUP_A_PID"
    kill_group "$SUP_B_PID"
    wait_port_down 9001
    wait_port_down 9002

    SUPPLIER_NAME=A FAIL_RATE="$1" TIMEOUT_RATE="$2" TIMEOUT_SEC="$SUPPLIER_HANG_SEC" PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 suppliers/supplier.php >>/tmp/supplier-a.log 2>&1 &
    SUP_A_PID=$!
    SUPPLIER_NAME=B FAIL_RATE="$3" TIMEOUT_RATE="$4" TIMEOUT_SEC="$SUPPLIER_HANG_SEC" PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9002 suppliers/supplier.php >>/tmp/supplier-b.log 2>&1 &
    SUP_B_PID=$!

    wait_port_up 9001
    wait_port_up 9002
    echo "  stubs restarted: A fail=$1 timeout=$2 | B fail=$3 timeout=$4 (hang ${SUPPLIER_HANG_SEC}s)"
}

start_worker() { php bin/worker.php >>/tmp/worker.log 2>&1 & WORKER_PID=$!; }
stop_worker() {
    kill_group "$WORKER_PID"
    WORKER_PID=""
}

new_order() {
    curl -s -X POST "$APP_URL/api/orders" -H 'Content-Type: application/json' -d "{\"sku\":\"$1\"}" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";'
}

order_status() {
    curl -s "$APP_URL/api/orders/$1" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["status"] ?? "";'
}

wait_for_terminal() {
    local id="$1" deadline=$((SECONDS + 60)) s
    while [ $SECONDS -lt $deadline ]; do
        s=$(order_status "$id")
        case "$s" in delivered|out_of_stock|delivery_failed|payment_failed) return 0 ;; esac
        sleep 0.2
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
echo "=== case 4: A issues, then hangs (criterion 4 - the timeout trap) ==="
suppliers 0 1.0 0 0
ORDER4=$(new_order KEY-CS2-PRIME)
echo "  order $ORDER4"
php bin/paysim.php "$ORDER4" --status=paid --n=1 >/dev/null
start_worker
wait_for_terminal "$ORDER4" || echo "  (timed out waiting for a terminal status)"
stop_worker

check "order delivered despite the timeout" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER4"
check "exactly one key left the pool" 1 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER4"
check "supplier issued exactly once" 1 \
    "SELECT count(*) FROM supplier_issues WHERE order_id = ? AND code IS NOT NULL" "$ORDER4"
check "delivered code == the code of the hung issue" 1 \
    "SELECT count(*) FROM issue_requests r JOIN supplier_issues s ON s.request_id = r.request_id
     WHERE r.order_id = ? AND r.status = 'issued' AND r.code = s.code" "$ORDER4"
check "supplier B was never contacted" 0 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ? AND supplier = 'B'" "$ORDER4"
check "the timeout was retried on the same request_id" 1 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ? AND supplier = 'A' AND attempts > 1" "$ORDER4"

# ---------------------------------------------------------------------------
echo
echo "=== case 5: A refuses outright, fallback to B (criterion 5) ==="
suppliers 1.0 0 0 0
ORDER5=$(new_order KEY-GTA5)
echo "  order $ORDER5"
php bin/paysim.php "$ORDER5" --status=paid --n=1 >/dev/null
start_worker
wait_for_terminal "$ORDER5" || echo "  (timed out waiting for a terminal status)"
stop_worker

check "order delivered by the fallback" delivered \
    "SELECT status FROM orders WHERE id = ?" "$ORDER5"
check "exactly one key left the pool" 1 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER5"
check "A was asked" 1 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ? AND supplier = 'A'" "$ORDER5"
check "A recorded a definite failure" failed \
    "SELECT status FROM issue_requests WHERE order_id = ? AND supplier = 'A'" "$ORDER5"
check "A issued nothing" 0 \
    "SELECT count(*) FROM supplier_issues WHERE order_id = ? AND supplier = 'A'" "$ORDER5"
check "B issued the code" 1 \
    "SELECT count(*) FROM supplier_issues WHERE order_id = ? AND supplier = 'B' AND code IS NOT NULL" "$ORDER5"

# ---------------------------------------------------------------------------
echo
echo "=== case 6: both suppliers out of stock (criterion 6) ==="
suppliers 0 0 0 0
# drain every unreserved key; already-sold keys stay bound to their orders
php -r 'require "vendor/autoload.php"; App\Db::run("DELETE FROM key_pool WHERE order_id IS NULL");'
check "pool is empty" 0 "SELECT count(*) FROM key_pool WHERE order_id IS NULL"

ORDER6=$(new_order KEY-EFT)
echo "  order $ORDER6"
php bin/paysim.php "$ORDER6" --status=paid --n=1 >/dev/null
start_worker
wait_for_terminal "$ORDER6" || echo "  (timed out waiting for a terminal status)"
stop_worker

check "order is out_of_stock, not delivery_failed" out_of_stock \
    "SELECT status FROM orders WHERE id = ?" "$ORDER6"
check "both suppliers were asked" 2 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ?" "$ORDER6"
check "both reported an empty pool" 2 \
    "SELECT count(*) FROM issue_requests WHERE order_id = ? AND status = 'out_of_stock'" "$ORDER6"
check "no key was reserved" 0 \
    "SELECT count(*) FROM key_pool WHERE order_id = ?" "$ORDER6"
check "no request_id was burned with a null code" 0 \
    "SELECT count(*) FROM supplier_issues WHERE order_id = ?" "$ORDER6"
check "the payment event is still consumed" 1 \
    "SELECT count(*) FROM payment_events WHERE order_id = ? AND applied AND result = 'applied'" "$ORDER6"

if curl -sf "$APP_URL/health" >/dev/null; then
    echo "  PASS  app survived the empty pool"
else
    echo "  FAIL  app is down after the empty pool"
    FAILURES=$((FAILURES + 1))
fi

php bin/seed.php >/dev/null
check "pool restocked to its full 50 keys" 50 "SELECT count(*) FROM key_pool"

# ---------------------------------------------------------------------------
echo
echo "=== case 7: A hangs after issuing, then refuses the retry ==="
# Regression guard. A rolls FAIL_RATE before it ever looks up the request_id, so a retry of an
# attempt that already timed out can come back 5xx. Reading that as "A issued nothing" would
# release the order to B while A key is already reserved - two codes for one order.
suppliers 0.5 1.0 0 0
CASE7_IDS=()
for _ in $(seq 1 6); do
    OID=$(new_order SUB-SPOTIFY-1M)
    CASE7_IDS+=("$OID")
    php bin/paysim.php "$OID" --status=paid --n=1 >/dev/null
done
start_worker
for OID in "${CASE7_IDS[@]}"; do
    wait_for_terminal "$OID" || echo "  (order $OID never reached a terminal status)"
done
stop_worker

check "all 6 orders reached a terminal status" 6 \
    "SELECT count(*) FROM orders WHERE sku = 'SUB-SPOTIFY-1M'
     AND status IN ('delivered', 'out_of_stock', 'delivery_failed')"
check "no order was issued a code by both suppliers" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM supplier_issues WHERE code IS NOT NULL
                           GROUP BY order_id HAVING count(*) > 1) d"
check "B was never asked while A was still unknown" 0 \
    "SELECT count(*) FROM issue_requests a JOIN issue_requests b ON b.order_id = a.order_id
     WHERE a.supplier = 'A' AND a.status = 'unknown' AND b.supplier = 'B'"

# ---------------------------------------------------------------------------
echo
echo "=== chaos: 10 orders, A 0.3/0.3, B 0.2/0.2 ==="
suppliers 0.3 0.3 0.2 0.2
CHAOS_IDS=()
for _ in $(seq 1 10); do
    OID=$(new_order SUB-YT-3M)
    CHAOS_IDS+=("$OID")
    php bin/paysim.php "$OID" --status=paid --n=1 >/dev/null
done
echo "  10 orders paid, letting the worker drive them"
start_worker
for OID in "${CHAOS_IDS[@]}"; do
    wait_for_terminal "$OID" || echo "  (order $OID never reached a terminal status)"
done
stop_worker

check "all 10 chaos orders reached a terminal status" 10 \
    "SELECT count(*) FROM orders WHERE sku = 'SUB-YT-3M'
     AND status IN ('delivered', 'out_of_stock', 'delivery_failed')"
echo "  --- chaos outcome (informational, no assertion) ---"
note "delivered"       "SELECT count(*) FROM orders WHERE sku = 'SUB-YT-3M' AND status = 'delivered'"
note "delivery_failed" "SELECT count(*) FROM orders WHERE sku = 'SUB-YT-3M' AND status = 'delivery_failed'"
note "fell back to B"  "SELECT count(*) FROM issue_requests WHERE supplier = 'B'"
note "unresolved (unknown) attempts" "SELECT count(*) FROM issue_requests WHERE status = 'unknown'"
note "orphaned keys (issued, order not delivered)" \
    "SELECT count(*) FROM key_pool k JOIN orders o ON o.id = k.order_id
     WHERE k.order_id IS NOT NULL AND o.status <> 'delivered'"

# ---------------------------------------------------------------------------
echo
echo "=== global invariants (must hold through all of the above) ==="
# The supplier reserves the key and records the issue in one transaction, so these two
# counts are equal no matter how many calls timed out or were retried.
check "reserved keys == supplier issues" 0 \
    "SELECT (SELECT count(*) FROM key_pool WHERE order_id IS NOT NULL)
          - (SELECT count(*) FROM supplier_issues WHERE code IS NOT NULL)"
check "no order holds two codes" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM key_pool WHERE order_id IS NOT NULL
                           GROUP BY order_id HAVING count(*) > 1) d"
# the double-issue signature: one order, codes from two different suppliers
check "no order was issued a code by two suppliers" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM supplier_issues WHERE code IS NOT NULL
                           GROUP BY order_id HAVING count(DISTINCT supplier) > 1) d"
check "no code issued against two request_ids" 0 \
    "SELECT count(*) FROM (SELECT code FROM supplier_issues WHERE code IS NOT NULL
                           GROUP BY code HAVING count(*) > 1) d"
check "no delivered order without a code" 0 \
    "SELECT count(*) FROM orders o WHERE o.status = 'delivered'
     AND NOT EXISTS (SELECT 1 FROM issue_requests r
                     WHERE r.order_id = o.id AND r.status = 'issued' AND r.code IS NOT NULL)"
check "every delivered code is the key reserved for that order" 0 \
    "SELECT count(*) FROM orders o
     JOIN issue_requests r ON r.order_id = o.id AND r.status = 'issued'
     WHERE o.status = 'delivered'
     AND NOT EXISTS (SELECT 1 FROM key_pool k WHERE k.code = r.code AND k.order_id = o.id)"
check "no order was delivered twice" 0 \
    "SELECT count(*) FROM (SELECT order_id FROM issue_requests WHERE status = 'issued'
                           GROUP BY order_id HAVING count(*) > 1) d"
check "no unpaid order ever got a code" 0 \
    "SELECT count(*) FROM issue_requests r JOIN orders o ON o.id = r.order_id
     WHERE r.status = 'issued' AND o.status IN ('created', 'payment_failed')"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
