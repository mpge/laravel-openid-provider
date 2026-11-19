#!/bin/sh
# Starts the demo provider (the Testbench workbench) inside the "op" container.
# The repository is mounted read-only at /src and copied to /app so the
# container never writes into the working tree.
set -eu

rm -rf /app
cp -R /src /app
rm -rf /app/.git /app/conformance/.runtime /app/moodle-smoke/.runtime
cd /app

if [ ! -f vendor/autoload.php ]; then
    echo "vendor/ is missing: run composer install in the repository first." >&2
    exit 1
fi

php vendor/bin/testbench workbench:build --ansi
php vendor/bin/testbench db:seed --class='Workbench\Database\Seeders\DatabaseSeeder' --force
php vendor/bin/testbench passport:keys --force

exec php -d opcache.enable_cli=1 vendor/bin/testbench serve --host=0.0.0.0 --port=8000 --no-reload
