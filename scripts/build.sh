#!/usr/bin/env bash
set -e

SCRIPT_PATH=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "${SCRIPT_PATH}/.."

# Download dependencies
export SYMFONY_ENV=prod
export APP_ENV=prod
composer install --no-interaction --no-dev --optimize-autoloader

# Warm up cache
php bin/console cache:warmup

# Save version from git tag
BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ ${BRANCH} == "master" ]]; then
  export RIPS_CLI_VERSION=$(git describe --abbrev=0 --tags)
else
  export RIPS_CLI_VERSION=$(git describe --tags)
fi

echo -n $RIPS_CLI_VERSION > version.txt

# Build PHAR
box build