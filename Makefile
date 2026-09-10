.PHONY: up down migrate seed seed-load serve suppliers worker test race chaos recover partial dishonest surge bench-catalog reconcile ledger-check

up:
	docker compose up -d
	docker compose exec -T postgres sh -c 'until pg_isready -U shop -d shop; do sleep 1; done'

down:
	docker compose down

migrate:
	php bin/migrate.php

seed:
	php bin/seed.php

seed-load:
	php bin/seed_load.php

reconcile:
	php bin/reconcile.php

ledger-check:
	php bin/ledger_check.php

serve:
	php -S localhost:8000 -t public

# supplier A on 9001, supplier B on 9002; both talk to the same postgres.
# PHP_CLI_SERVER_WORKERS matters here: a stub hanging on an injected timeout must not
# block the retry queued behind it (POSIX only, ignored on Windows).
suppliers:
	SUPPLIER_NAME=A PHP_CLI_SERVER_WORKERS=8 php -S localhost:9001 suppliers/supplier.php & \
	SUPPLIER_NAME=B PHP_CLI_SERVER_WORKERS=8 php -S localhost:9002 suppliers/supplier.php & \
	wait

worker:
	php bin/worker.php

test:
	vendor/bin/phpunit

# race harness runs in the container: php -S is single-process on Windows and
# PHP_CLI_SERVER_WORKERS is POSIX-only, so 50 "concurrent" webhooks would just queue up
race:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/race.sh

# stage 3: timeout trap, supplier fallback, empty pool, chaos run
chaos:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/chaos.sh

# stage 4: chaos residue -> recovery -> every order delivered, ledger and reconcile clean
recover:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/recover.sh

# stage 2 task 1: basket with partial fulfilment, refunds and the money identity
partial:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/partial.sh

# stage 2 task 2: a supplier that lies - duplicate codes, foreign codes, errors after issuing
dishonest:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/dishonest.sh

# stage 2 task 3: more orders than the supplier accepts per minute
surge:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/surge.sh

# stage 5: 5000 skus / 200k keys, query plans and storefront latency
bench-catalog:
	docker compose up -d postgres
	docker compose run --rm --build runner bash tests/bench_catalog.sh
