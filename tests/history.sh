#!/usr/bin/env bash
#
# Stage 2, task 4: the picture at any past moment.
#
#   make history       (or)   docker compose run --rm runner bash tests/history.sh
#
# The run takes a basket through a partial failure, stamping the clock at each step, and then
# asks the system what things looked like at each of those instants. What must hold: the answers
# match what was true then rather than what is true now, the log cannot be rewritten, and the
# period totals add up - closing balance equals opening balance plus the movements in between.

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
export REFUND_AFTER_SEC=3

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
wait_port_up() { for _ in $(seq 1 100); do probe_supplier "$1" && return 0; sleep 0.1; done; echo "  FAIL  port $1 never came up"; FAILURES=$((FAILURES + 1)); }

start_worker() { php bin/worker.php >>/tmp/worker.log 2>&1 & WORKER_PID=$!; }
stop_worker()  { kill_group "$WORKER_PID"; WORKER_PID=""; }

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

stamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }

# asks the endpoint what the order looked like at $2 and prints one field of the answer
at_field() {
    curl -s "$APP_URL/api/admin/orders/$1/at?at=$2" \
        | php -r '$d = json_decode(stream_get_contents(STDIN), true);
            $k = $argv[1];
            echo $k === "unsettled" ? $d["money"]["unsettled"] : (string) ($d[$k] ?? "");' "$3"
}

expect() {
    if [ "$2" = "$3" ]; then
        printf "  PASS  %-58s = %s\n" "$1" "$2"
    else
        printf "  FAIL  %-58s expected %s, got %s\n" "$1" "$3" "$2"
        FAILURES=$((FAILURES + 1))
    fi
}

trim_pool() {
    php -r 'require "vendor/autoload.php";
        App\Db::run("DELETE FROM key_pool WHERE order_id IS NULL AND sku IS NOT NULL");
        App\Db::run("DELETE FROM key_pool WHERE code IN (SELECT code FROM key_pool
                     WHERE order_id IS NULL ORDER BY code OFFSET " . (int) $argv[1] . ")");
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
SUPPLIER_NAME=A PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 suppliers/supplier.php >>/tmp/supplier-a.log 2>&1 &
SUP_A_PID=$!
SUPPLIER_NAME=B PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9002 suppliers/supplier.php >>/tmp/supplier-b.log 2>&1 &
SUP_B_PID=$!
wait_port_up 9001; wait_port_up 9002
echo "app and stubs up"

T0=$(stamp)
sleep 1

# ---------------------------------------------------------------------------
echo
echo "=== a basket of 4 lines with only 1 key available, clock stamped at each step ==="
trim_pool 1
note "free keys" "SELECT count(*) FROM key_pool WHERE order_id IS NULL"

ORDER=$(new_basket '{"items":[{"sku":"KEY-GTA5","qty":4}]}')
echo "  order $ORDER"
sleep 1
T_CREATED=$(stamp)

php bin/paysim.php "$ORDER" --status=paid --n=1 >/dev/null
sleep 1
T_PAID=$(stamp)

start_worker
wait_final "$ORDER" 90 || echo "  (order never reached a final status)"
stop_worker
sleep 1
T_END=$(stamp)

note "final order status"  "SELECT status FROM orders WHERE id = '$ORDER'"
note "history rows for it" "SELECT count(*) FROM order_events WHERE order_id = '$ORDER'"

# ---------------------------------------------------------------------------
echo
echo "=== rewinding the same order to each of those instants ==="
expect "before it existed, the order is unknown"        "$(at_field "$ORDER" "$T0" existed)"        ""
expect "just after creation it was 'created'"           "$(at_field "$ORDER" "$T_CREATED" status)"  "created"
expect "and nothing had been settled yet"               "$(at_field "$ORDER" "$T_CREATED" unsettled)" "0"
expect "just after payment it was 'paid'"               "$(at_field "$ORDER" "$T_PAID" status)"     "paid"
expect "and the whole amount was still unsettled"       "$(at_field "$ORDER" "$T_PAID" unsettled)"  "7960"
expect "at the end it is partially_delivered"           "$(at_field "$ORDER" "$T_END" status)"      "partially_delivered"
expect "and nothing is left unsettled"                  "$(at_field "$ORDER" "$T_END" unsettled)"   "0"

echo "  --- the same order, as of each stamp ---"
for T in "$T_CREATED" "$T_PAID" "$T_END"; do
    curl -s "$APP_URL/api/admin/orders/$ORDER/at?at=$T" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $by = [];
        foreach ($d["items"] as $i) { $by[$i["status"]] = ($by[$i["status"]] ?? 0) + 1; }
        ksort($by);
        $parts = [];
        foreach ($by as $k => $n) { $parts[] = $n . " " . $k; }
        printf("        %s  status=%-20s lines: %-40s unsettled=%d\n",
            $d["as_of"], $d["status"], implode(", ", $parts), $d["money"]["unsettled"]);'
done

# ---------------------------------------------------------------------------
echo
echo "=== the log cannot be rewritten ==="
UPD=$(php -r 'require "vendor/autoload.php";
    try { App\Db::run("UPDATE order_events SET to_state = ? WHERE order_id = ?", ["tampered", $argv[1]]); echo "ALLOWED"; }
    catch (Throwable $e) { echo "BLOCKED"; }' "$ORDER")
expect "UPDATE on order_events is refused by the database" "$UPD" "BLOCKED"

DEL=$(php -r 'require "vendor/autoload.php";
    try { App\Db::run("DELETE FROM order_events WHERE order_id = ?", [$argv[1]]); echo "ALLOWED"; }
    catch (Throwable $e) { echo "BLOCKED"; }' "$ORDER")
expect "DELETE on order_events is refused by the database" "$DEL" "BLOCKED"

LED=$(php -r 'require "vendor/autoload.php";
    try { App\Db::run("UPDATE ledger SET amount = 1 WHERE order_id = ?", [$argv[1]]); echo "ALLOWED"; }
    catch (Throwable $e) { echo "BLOCKED"; }' "$ORDER")
expect "UPDATE on the ledger is refused by the database" "$LED" "BLOCKED"

check "the history survived the tampering attempts intact" 0 \
    "SELECT count(*) FROM order_events WHERE to_state = 'tampered'"

# ---------------------------------------------------------------------------
echo
echo "=== period totals ==="
REPORT=$(curl -s "$APP_URL/api/admin/report?from=$T0&to=$T_END")
echo "$REPORT" | php -r '$d = json_decode(stream_get_contents(STDIN), true);
    printf("        opening   paid=%d delivered=%d refunded=%d\n", $d["opening"]["payment_received"], $d["opening"]["revenue_recognised"], $d["opening"]["refund_issued"]);
    printf("        movements paid=%d delivered=%d refunded=%d\n", $d["movements"]["payment_received"], $d["movements"]["revenue_recognised"], $d["movements"]["refund_issued"]);
    printf("        closing   paid=%d delivered=%d refunded=%d\n", $d["closing"]["payment_received"], $d["closing"]["revenue_recognised"], $d["closing"]["refund_issued"]);
    printf("        orders finalised in period: %d\n", $d["orders_finalised"]);'

CONT=$(echo "$REPORT" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["continuous"] ? "yes" : "no";')
BAL=$(echo "$REPORT" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["balanced"] ? "yes" : "no";')
FIN=$(echo "$REPORT" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["orders_finalised"];')
expect "closing = opening + movements"                  "$CONT" "yes"
expect "every order closed in the period balances"      "$BAL"  "yes"
expect "the period saw one order finalised"             "$FIN"  "1"

# a window that ends before the order was paid must not contain its money
EARLY=$(curl -s "$APP_URL/api/admin/report?from=$T0&to=$T_CREATED" \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["movements"]["payment_received"];')
expect "a window that ends before payment shows no money" "$EARLY" "0"

# splitting the period in two must give the same closing balance
SPLIT=$(curl -s "$APP_URL/api/admin/report?from=$T_CREATED&to=$T_END" \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["closing"]["payment_received"];')
WHOLE=$(echo "$REPORT" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["closing"]["payment_received"];')
expect "closing balance does not depend on where the window starts" "$SPLIT" "$WHOLE"

# ---------------------------------------------------------------------------
echo
echo "=== history agrees with the tables it describes ==="
check "every order has a creation event" 0 \
    "SELECT count(*) FROM orders o
     WHERE NOT EXISTS (SELECT 1 FROM order_events e
                       WHERE e.order_id = o.id AND e.type = 'order.created')"
check "the last recorded status matches the order itself" 0 \
    "SELECT count(*) FROM orders o
     WHERE o.status <> (
         SELECT e.to_state FROM order_events e
         WHERE e.order_id = o.id AND e.type IN ('order.created', 'order.status')
         ORDER BY e.occurred_at DESC, e.id DESC LIMIT 1)"
check "the last recorded line state matches the line itself" 0 \
    "SELECT count(*) FROM order_items i
     WHERE i.status <> (
         SELECT e.to_state FROM order_events e
         WHERE e.item_id = i.id AND e.type = 'item.status'
         ORDER BY e.occurred_at DESC, e.id DESC LIMIT 1)"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: all checks passed"
    exit 0
fi

echo "RESULT: $FAILURES check(s) failed"
exit 1
