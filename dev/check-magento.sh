#!/usr/bin/env bash
# Checks the Magento-dependent code against the real Magento 2.4.9 sources: PHPStan (API calls, types) and XSD
# validation of all XML. Needs a checkout of magento2 and PHPStan; neither is installed by `composer install` here
# because Composer cannot reach GitHub's dist/API in the cloud sandbox. Known, accepted findings are listed in docs/STATUS.md.
#
#   git clone --depth 1 --branch 2.4.9 https://github.com/magento/magento2 /home/user/magento/magento2
#   git clone --depth 1 --branch 1.12.34 https://github.com/phpstan/phpstan /home/user/tools/phpstan   # contains phpstan.phar
set -uo pipefail
cd "$(dirname "$0")/.."
export MAGENTO_SRC="${MAGENTO_SRC:-/home/user/magento/magento2}"
PHPSTAN="${PHPSTAN:-/home/user/tools/phpstan/phpstan.phar}"

echo "== XML against Magento XSDs"
php dev/validate-xml.php

echo "== PHPStan (level 4) against Magento sources, new/changed code only is expected to be clean"
php -d memory_limit=2G "$PHPSTAN" analyse -c dev/phpstan.neon --no-progress --error-format=raw 2>&1 \
    | grep -E '^/' | sed "s#$PWD/##"
