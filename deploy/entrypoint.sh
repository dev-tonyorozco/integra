#!/bin/sh
set -eu
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ "${DB_CONNECTION:-sqlite}" = sqlite ]; then
 db_file="${DB_DATABASE:-/var/www/html/storage/app/private/integra.sqlite}"
 export DB_DATABASE="$db_file"
 mkdir -p "$(dirname "$db_file")"
 touch "$db_file"
fi
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
