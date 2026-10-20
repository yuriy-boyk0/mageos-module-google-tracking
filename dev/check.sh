#!/usr/bin/env bash
# Runs every check that works without a Magento install. Run from anywhere; exits non-zero on the first failure.
# One-time setup: (cd dev && composer install)
set -euo pipefail
cd "$(dirname "$0")/.."

echo "== PHP syntax"
find . -name '*.php' -not -path './dev/vendor/*' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
find . -name '*.php' -not -path './dev/vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null

echo "== XML well-formedness"
find . -name '*.xml' -not -path './dev/vendor/*' -not -path './.git/*' -print0 | xargs -0 -n1 xmllint --noout

echo "== JSON validity (etc/*.json use \\{ \\} escapes for Liquid; undone before parsing)"
php -r '
foreach (glob("etc/*.json") as $f) {
    $raw = str_replace(["\\{", "\\}"], ["{", "}"], file_get_contents($f));
    json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    echo "ok $f\n";
}'

echo "== PHPUnit"
dev/vendor/bin/phpunit -c phpunit.xml.dist

echo "== JS (phtml inline scripts)"
node --test Test/js/phtml-scripts.test.js
