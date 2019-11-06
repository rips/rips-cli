#!/usr/bin/env bash
set -e

SCRIPT_PATH=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "${SCRIPT_PATH}/.."

export SYMFONY_ENV=test
export APP_ENV=test
export RIPS_CLI_ALL=1
composer install --dev --no-interaction

rm -rf phpmetrics
./vendor/bin/phpmetrics --report-html=phpmetrics .
tar -zcvf phpmetrics.tar.gz -C phpmetrics .