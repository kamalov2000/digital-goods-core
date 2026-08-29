# Runtime for the app, the worker and the race harness. Only reason it exists: the built-in
# php -S is single-process on Windows, and the race test needs real concurrent requests
# (PHP_CLI_SERVER_WORKERS works on POSIX only).
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev unzip \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
