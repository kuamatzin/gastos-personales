#!/bin/bash

# Clear all Laravel caches on production server
# Run this via SSH on your production server

echo "Clearing Laravel caches..."

# Clear application cache
php artisan cache:clear

# Clear route cache
php artisan route:clear

# Clear config cache
php artisan config:clear

# Clear compiled views
php artisan view:clear

# Clear event cache
php artisan event:clear

# Clear all cached bootstrap files
php artisan clear-compiled

# Clear opcache if available
php artisan opcache:clear

# Optimize for production
php artisan optimize

echo "All caches cleared!"

# If you're using PHP-FPM, you might need to restart it
# sudo service php8.2-fpm restart
# or
# sudo systemctl restart php8.2-fpm

# For queue workers, restart them
php artisan queue:restart

echo "Queue workers restarted!"