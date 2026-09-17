#!/bin/bash
# ============================================================
#  FILE: scripts/deploy.sh
#
#  Deployment script for Falcon Pickleball Court.
# ============================================================

set -e

echo "🚀 Starting deployment..."

# Check if we're in the right directory
if [ ! -f "config/db.php" ]; then
    echo "❌ Error: Run this script from the project root"
    exit 1
fi

# Run database migrations
echo "📦 Running migrations..."
php migrations/v2_multi_court.sql

# Clear caches
echo "🧹 Clearing caches..."
if command -v redis-cli &> /dev/null; then
    redis-cli FLUSHALL
fi

# Install/update dependencies (if using composer)
if [ -f "composer.json" ]; then
    echo "📦 Installing PHP dependencies..."
    composer install --no-dev --optimize-autoloader
fi

# Set permissions
echo "🔒 Setting permissions..."
chmod 755 .
chmod 644 *.php
chmod 755 scripts/*.sh

# Health check
echo "🏥 Running health check..."
curl -f http://localhost/api/health.php || echo "⚠️ Health check failed"

echo "✅ Deployment complete!"