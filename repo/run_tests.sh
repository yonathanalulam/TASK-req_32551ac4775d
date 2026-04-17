#!/usr/bin/env bash
# run_tests.sh — Dockerized test runner for Meridian.
#
# Brings the compose stack up from a cold state (no leftover containers/volumes/images),
# builds the `app` image, waits for MySQL to pass its healthcheck, migrates the
# `meridian_test` database, and then runs the full PHPUnit suite (unit + integration)
# inside a disposable container. Tears everything back down at the end.
#
# Usage:
#   ./run_tests.sh                      # run all tests
#   ./run_tests.sh tests/Unit/Crypto    # run a specific suite or directory
#   ./run_tests.sh --filter=Signup      # pass a PHPUnit filter through
#
# Any extra argument is forwarded to the `vendor/bin/phpunit` invocation.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ---- deterministic test environment ---------------------------------------------
# A fixed AES-256 master key so cipher round-trips are reproducible across runs.
# The key is only ever used by the ephemeral test database and is never persisted.
export APP_ENV=testing
export APP_DEBUG=true
export APP_TIMEZONE=UTC
export APP_PORT=8080
export APP_MASTER_KEY="0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
export APP_MASTER_KEY_VERSION=1

# MySQL credentials for the test composition. These match docker-compose.yml defaults.
export DB_HOST=mysql
export DB_PORT=3306
export DB_DATABASE=meridian_test
export DB_USERNAME=meridian
export DB_PASSWORD=meridian_pass
export DB_ROOT_PASSWORD=meridian_root
export TEST_DB_HOST=mysql
export TEST_DB_DATABASE=meridian_test

# ---- helpers --------------------------------------------------------------------
log()   { printf "\n==> %s\n" "$*"; }
die()   { printf "\nERROR: %s\n" "$*" >&2; exit 1; }

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

cleanup() {
    log "Tearing down Meridian test stack"
    docker compose down -v --remove-orphans >/dev/null 2>&1 || true
}

require_cmd docker
docker compose version >/dev/null 2>&1 || die "'docker compose' plugin is required"

# ---- cold start -----------------------------------------------------------------
trap cleanup EXIT
log "Cold cleanup of any prior Meridian containers, volumes, and networks"
docker compose down -v --remove-orphans >/dev/null 2>&1 || true

log "Building the app image from scratch"
docker compose build app

log "Starting the MySQL service (healthcheck will gate the next step)"
docker compose up -d mysql

log "Waiting for MySQL to pass its healthcheck"
attempt=0
max_attempts=90    # ~3 minutes
while true; do
    status="$(docker inspect --format='{{.State.Health.Status}}' meridian_mysql 2>/dev/null || echo starting)"
    case "$status" in
        healthy) break ;;
        unhealthy)
            docker compose logs --tail=100 mysql
            die "MySQL container reported unhealthy"
            ;;
    esac
    attempt=$((attempt + 1))
    if [ "$attempt" -gt "$max_attempts" ]; then
        docker compose logs --tail=200 mysql
        die "MySQL did not become healthy after ${max_attempts} checks"
    fi
    sleep 2
done
log "MySQL is healthy"

# ---- test run -------------------------------------------------------------------
# The application image is built with --no-dev for production. For tests we install
# dev dependencies and the `phpunit` binary in the disposable run container before
# invoking the suites.

PHPUNIT_EXTRA="${*:-}"

log "Running the test suite inside a disposable app container"
set +e
docker compose run --rm --no-deps \
    -e APP_ENV=testing \
    -e APP_DEBUG=true \
    -e APP_TIMEZONE=UTC \
    -e APP_MASTER_KEY="$APP_MASTER_KEY" \
    -e APP_MASTER_KEY_VERSION="$APP_MASTER_KEY_VERSION" \
    -e DB_HOST=mysql \
    -e DB_PORT=3306 \
    -e DB_DATABASE=meridian_test \
    -e DB_USERNAME=meridian \
    -e DB_PASSWORD=meridian_pass \
    app \
    sh -eu -c "
        printf '\n==> Installing composer dev dependencies...\n'
        composer install --no-interaction --prefer-dist --no-progress

        printf '\n==> Ensuring meridian_test database exists...\n'
        php -r '
            \$dsn = \"mysql:host=\" . getenv(\"DB_HOST\") . \";port=\" . getenv(\"DB_PORT\");
            \$pdo = new PDO(\$dsn, \"root\", \"${DB_ROOT_PASSWORD}\");
            \$pdo->exec(\"CREATE DATABASE IF NOT EXISTS meridian_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\");
            \$pdo->exec(\"GRANT ALL PRIVILEGES ON meridian_test.* TO \\\"${DB_USERNAME}\\\"@\\\"%\\\"\");
            \$pdo->exec(\"FLUSH PRIVILEGES\");
        '

        printf '\n==> Running Phinx migrations against meridian_test...\n'
        vendor/bin/phinx migrate -e testing -c phinx.php

        printf '\n==> Running PHPUnit (unit + integration)...\n'
        vendor/bin/phpunit --colors=always ${PHPUNIT_EXTRA}
    "
exit_code=$?
set -e

if [ "$exit_code" -eq 0 ]; then
    log "TESTS PASSED"
else
    log "TESTS FAILED (exit code ${exit_code})"
fi

exit "$exit_code"
