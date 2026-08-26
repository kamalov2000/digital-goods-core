.PHONY: up down migrate seed serve suppliers test

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

# supplier A on 9001, supplier B on 9002; both talk to the same postgres
suppliers:
	SUPPLIER_NAME=A php -S localhost:9001 suppliers/supplier.php & \
	SUPPLIER_NAME=B php -S localhost:9002 suppliers/supplier.php & \
	wait

test:
	vendor/bin/phpunit
