#!/usr/bin/env bash
set -e

SCRIPT_PATH=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "${SCRIPT_PATH}/.."

hadolint --ignore DL3008 --ignore DL3015 docker/Dockerfile
hadolint --ignore DL3008 --ignore DL3015 --ignore DL3016 --ignore DL4001 scripts/build_env/Dockerfile
hadolint --ignore DL3008 --ignore DL3015 --ignore DL3016 --ignore DL4001 scripts/test_env/Dockerfile

export SYMFONY_ENV=test
composer install --dev --no-interaction

./bin/console security:check
./vendor/bin/phpcs --standard=PSR1,PSR2 -n src
./vendor/bin/phpstan analyse -c .phpstan.neon -l 7 src
PHAN_DISABLE_XDEBUG_WARN=1 ./vendor/bin/phan -k .phan.php