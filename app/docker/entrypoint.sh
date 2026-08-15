#!/bin/sh
set -e

# Config, route and view caches are built here rather than in the Dockerfile.
#
# `php artisan config:cache` freezes the value of every env() call into a PHP
# array. Run it at build time and you bake in the build machine's environment:
# no database host, no bucket name, APP_KEY empty. Run it here, after ECS has
# injected the task definition's environment and secrets, and it caches the real
# values. The cost is a few hundred milliseconds per container start.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# No `storage:link` here. The filesystem disk is S3, so there is nothing local
# to expose, and public/ is not writable by the app user anyway.

exec "$@"
