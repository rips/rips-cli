#!/usr/bin/env bash
set -e

SCRIPT_PATH=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "${SCRIPT_PATH}/.."

docklint docker/Dockerfile
docklint scripts/build_env/Dockerfile
docklint scripts/test_env/Dockerfile

export SYMFONY_ENV=test
composer install --dev --no-interaction

./bin/console security:check
./vendor/bin/phpcs --standard=PSR1,PSR2 -n src
./vendor/bin/phpstan analyse -c .phpstan.neon -l 7 src
PHAN_DISABLE_XDEBUG_WARN=1 ./vendor/bin/phan -k .phan.php