#!/usr/bin/env bash
set -e

SCRIPT_PATH=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "${SCRIPT_PATH}/.."

# Download dependencies
export SYMFONY_ENV=prod
composer install --no-interaction --no-dev --optimize-autoloader

# Warm up cache
php bin/console cache:warmup

# Build PHAR
box build