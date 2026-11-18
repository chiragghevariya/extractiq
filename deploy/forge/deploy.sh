# Laravel Forge deploy script for the API (site root: /home/forge/api.example.com, web directory: /api/public).
# Paste into Site → Deployments → Deploy Script. Forge provides $FORGE_SITE_BRANCH, $FORGE_PHP_FPM, $FORGE_PHP.
cd /home/forge/api.example.com
git pull origin $FORGE_SITE_BRANCH

cd api
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan event:cache

# Workers pick up the new code: Horizon restarts itself via its Forge daemon; Reverb too.
$FORGE_PHP artisan horizon:terminate
$FORGE_PHP artisan reverb:restart
