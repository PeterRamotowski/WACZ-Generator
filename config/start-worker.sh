#!/bin/bash

# WACZ Worker Startup Script

php /var/www/bin/console cache:clear --no-warmup
php /var/www/bin/console cache:warmup

# Reset stuck requests
STUCK_COUNT=$(php /var/www/bin/console wacz:reset-stuck --timeout=5 --dry-run 2>/dev/null | grep -c "Found.*stuck request")
if [ "$STUCK_COUNT" -gt 0 ]; then
    echo "Found $STUCK_COUNT stuck request(s), resetting..."
    echo "yes" | php /var/www/bin/console wacz:reset-stuck --timeout=5
else
    echo "No stuck requests found"
fi

# Start messenger worker
exec php /var/www/bin/console messenger:consume wacz_processing --time-limit=3600 --memory-limit=256M --limit=10