#!/usr/bin/env bash
#
# Stage 5: storefront under load.
#
#   make bench-catalog   (or)   docker compose run --rm runner bash tests/bench_catalog.sh
#
# Seeds 5000 SKUs / 200k keys, records EXPLAIN (ANALYZE, BUFFERS) for the query variants into
# docs/stage5_explain.md, then measures the endpoint over 200 real HTTP requests.

set -uo pipefail
set -m
cd "$(dirname "$0")/.." || exit 1

APP_URL="${APP_URL:-http://127.0.0.1:8000}"
REQUESTS="${REQUESTS:-200}"
APP_PID=""

cleanup() { [ -n "$APP_PID" ] && { kill -- "-$APP_PID" 2>/dev/null; wait "$APP_PID"; } 2>/dev/null; }
trap cleanup EXIT

echo "=== setup ==="
[ -f vendor/autoload.php ] || composer install --no-interaction --quiet
php bin/migrate.php
php bin/seed.php
php bin/seed_load.php

PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8000 -t public >/tmp/app.log 2>&1 &
APP_PID=$!
for _ in $(seq 1 60); do curl -sf "$APP_URL/health" >/dev/null && break; sleep 0.25; done
echo "app up"

echo
echo "=== query plans ==="
php tests/bench_explain.php

echo
echo "=== endpoint latency: $REQUESTS requests to /api/catalog ==="
# warm up so the first request does not pay for connection and plan setup
for _ in $(seq 1 10); do curl -s -o /dev/null "$APP_URL/api/catalog?limit=50&offset=0"; done

: > /tmp/latencies.txt
for i in $(seq 1 "$REQUESTS"); do
    OFFSET=$(( (i * 137) % 4900 ))
    curl -s -o /dev/null -w '%{time_total}\n' "$APP_URL/api/catalog?limit=50&offset=$OFFSET&in_stock=1" >> /tmp/latencies.txt
done

php -r '
    $ms = array_map(static fn ($l) => ((float) $l) * 1000, file("/tmp/latencies.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    sort($ms);
    $pick = static fn (float $p) => $ms[min(count($ms) - 1, (int) ceil($p * count($ms)) - 1)];
    printf("  requests %d\n  p50 %6.1f ms\n  p95 %6.1f ms\n  p99 %6.1f ms\n  max %6.1f ms\n  avg %6.1f ms\n",
        count($ms), $pick(0.50), $pick(0.95), $pick(0.99), $ms[count($ms) - 1],
        array_sum($ms) / count($ms));
'

echo
echo "=== sanity: the storefront numbers are real ==="
curl -s "$APP_URL/api/catalog?limit=3&offset=0" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    foreach ($d["items"] as $i) { printf("  %-14s %-22s available=%d\n", $i["sku"], $i["name"], $i["available"]); }
    printf("  shared generic pool: %d\n", $d["shared_pool"]);
'
php tests/race_assert.php "stock counter matches the pool for every sku" 0 \
    "SELECT count(*) FROM (SELECT s.sku FROM stock s
                           LEFT JOIN key_pool k ON k.sku = s.sku AND k.order_id IS NULL
                           GROUP BY s.sku, s.available
                           HAVING s.available <> count(k.code)) d"
