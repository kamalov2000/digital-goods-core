.PHONY: up down migrate seed serve suppliers worker test race

up:
	docker compose up -d
	docker compose exec -T postgres sh -c 'until pg_isready -U shop -d shop; do sleep 1; done'

down:
	docker compose down

migrate:
	php bin/migrate.php

seed:
	php bin/seed.php

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
