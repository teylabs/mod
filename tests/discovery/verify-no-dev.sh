#!/usr/bin/env bash
# Boot a fresh production install with a provider extending a dev-only package.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=${1:?Usage: verify-no-dev.sh <new-app-directory>}
export COMPOSER_NO_INTERACTION=1

composer create-project laravel/laravel "$WORK" '^12.0' --no-install --no-scripts --prefer-dist
cd "$WORK"
composer config repositories.mod "{\"type\":\"path\",\"url\":\"$ROOT\",\"options\":{\"symlink\":false}}"
composer require 'tey/mod:*@dev' --no-update
composer require --dev 'nunomaduro/collision:^8.0' --no-update
mkdir -p app/Providers
cat > app/Providers/DevServiceProvider.php <<'PHP'
<?php

namespace App\Providers;

class DevServiceProvider extends \NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider {}
PHP

# Resolve a lock with dev dependencies, but never install them in this app.
composer update --no-install --no-scripts --prefer-dist
composer install --no-dev --prefer-dist --optimize-autoloader
php -r 'require "vendor/autoload.php"; exit(class_exists("NunoMaduro\\Collision\\Adapters\\Laravel\\CollisionServiceProvider") ? 1 : 0);'
php artisan package:discover --no-ansi
php artisan mod:cache -v --no-ansi | tee discovery-output.txt
grep -F 'DevServiceProvider.php' discovery-output.txt
grep -F 'NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider' discovery-output.txt
php artisan mod:clear --no-ansi
