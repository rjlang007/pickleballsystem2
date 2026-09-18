#!/bin/bash
# ============================================================
#  FILE: scripts/deploy.sh
#
#  Deployment script for Padol Pickleball Court.
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
if command -v psql >/dev/null 2>&1; then
    psql "$DATABASE_URL" -f migrations/v2_multi_court.sql
    psql "$DATABASE_URL" -f migrations/020_open_play_tournament.sql
    psql "$DATABASE_URL" -f migrations/021_avatar_columns.sql
else
    echo "⚠️ psql not found — skipping SQL migration step"
fi

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