#!/bin/sh
set -eu
python manage.py migrate --noinput
chmod 700 /data
chmod 600 /data/integra.sqlite3
exec gunicorn config.wsgi:application --bind 0.0.0.0:8000 --workers 1 --threads 4 --timeout 60 --access-logfile - --error-logfile -
