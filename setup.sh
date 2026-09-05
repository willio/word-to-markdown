#!/bin/sh
set -eu

command -v php >/dev/null 2>&1 || { echo "PHP 8.1+ is required." >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "PHP 8.1+ is required." >&2; exit 1; }
php -m | grep -qi '^dom$' || { echo "The PHP DOM extension is required." >&2; exit 1; }
php -m | grep -qi '^zip$' || { echo "The PHP ZIP extension is required." >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "Composer is required." >&2; exit 1; }
composer install --no-interaction --prefer-dist
echo "Word to Markdown is ready. Run: bin/word-to-markdown --help"
