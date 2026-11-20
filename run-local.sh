#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
compose=${SSO_DOCKER_COMPOSE:-"$root/../sso-docker/idp/compose.yaml"}

docker compose -f "$compose" exec -T \
    -e XDEBUG_MODE=off \
    -e CHAR_BASE_URL="${CHAR_BASE_URL:-http://idp-local.rschooltoday.test}" \
    -w /srv/idp/tests/characterization php-fpm sh -ec '
        export CHAR_ALLOW_FIXTURES=yes
        export CHAR_DB_HOST="$DB_HOST" CHAR_DB_PORT="${DB_PORT:-3306}"
        export CHAR_DB_USER="$DB_USER" CHAR_DB_PASSWORD="$DB_PASSWORD"
        export CHAR_DB_WAF="$DB_NAME_IDP_WAF" CHAR_DB_IP="$DB_NAME_IDP" CHAR_DB_AUDIT="$DB_NAME_IDP_AUDIT"
        export CHAR_DB_SSL_CA="${DB_SSL_CA:-}"
        export CHAR_TEST_PASSWORD="$(php -r "echo bin2hex(random_bytes(24));")"
        export CHAR_HTTP_IP="$(php -r "echo gethostbyname(\"nginx\");")"
        composer install --no-interaction --prefer-dist
        exec php vendor/bin/phpunit "$@"
    ' sh "$@"
