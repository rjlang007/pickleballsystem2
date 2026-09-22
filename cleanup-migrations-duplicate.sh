#!/bin/bash
# ============================================================
# cleanup-migrations-duplicate.sh
#
# Removes an accidental full copy of the entire project that ended up
# nested inside migrations/ (Uploads, assets, court/, includes/, player/,
# etc. — ~25MB, ~220 files). It looks like a stray `cp -r . migrations/`
# or similar at some point in the project's history.
#
# What this KEEPS in migrations/:
#   - every *.sql file directly under migrations/ (the real, in-use
#     migration chain)
#   - migrations/migrations/run_migrations.php — this one file is
#     genuinely load-bearing: start.sh and scripts/deploy.sh both call
#     `php migrations/migrations/run_migrations.php`, and that script
#     reads its *.sql files from the parent (migrations/*.sql), not from
#     its own folder. It looks like it was meant to live at
#     migrations/run_migrations.php and ended up one level too deep, but
#     since deploy already points at the deeper path, this script leaves
#     it exactly where it is rather than risk breaking deploys.
#
# What this REMOVES:
#   - migrations/Uploads/      (duplicate of the real Uploads/ — includes
#                                real player avatars and payment-proof
#                                screenshots, so this also reduces where
#                                that data sits on disk)
#   - migrations/assets/, migrations/court/, migrations/docs/,
#     migrations/includes/, migrations/leaderboard/, migrations/logs/,
#     migrations/mobile/, migrations/player/, migrations/referee/,
#     migrations/scripts/, migrations/staff/, migrations/storage/,
#     migrations/superadmin/, migrations/tournament/, migrations/.github/
#   - migrations/GUIDE.md (identical to the real GUIDE.md)
#   - migrations/.env  <-- see the warning below
#   - migrations/.env.example, migrations/.dockerignore, migrations/Dockerfile,
#     migrations/php.ini, migrations/railway.json, migrations/docker-compose.yml,
#     migrations/.gitignore, migrations/nixpacks.toml, migrations/.htaccess,
#     migrations/entrypoint.sh, migrations/nginx.conf, migrations/.user.ini,
#     migrations/start.sh
#   - the 27 duplicate *.sql files sitting inside migrations/migrations/
#     alongside run_migrations.php (that script never reads them — it
#     reads ../*.sql — so they're dead weight, not a second source of
#     truth)
#
# IMPORTANT — read before running:
#   migrations/.env still contains the OLD database password
#   (the one from before the rotation in SECRETS_ROTATION_PLAN.md).
#   That password should already be dead if you rotated it as planned,
#   but this is a second copy of a credential that was already flagged
#   as exposed — worth a quick double-check that nothing else (a second
#   environment, a teammate's local .env, a backup) is still relying on
#   the old value before you're fully done with that rotation.
#
# Run this from the project root (same folder as config/, index.php).
# ============================================================

set -e

if [ ! -f "config/app.php" ] || [ ! -d "migrations" ]; then
    echo "❌ Run this from the project root (config/app.php and migrations/ must exist here)."
    exit 1
fi

echo "This will permanently delete the duplicate files listed in the header"
echo "comment above. It will NOT touch migrations/*.sql or"
echo "migrations/migrations/run_migrations.php."
read -p "Continue? [y/N] " confirm
if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "Aborted — nothing was changed."
    exit 0
fi

cd migrations

# Whole duplicate subtrees
rm -rf Uploads assets court docs includes leaderboard logs mobile player \
       referee scripts staff storage superadmin tournament .github

# Duplicate top-level files
rm -f GUIDE.md .env .env.example .dockerignore Dockerfile php.ini \
      railway.json docker-compose.yml .gitignore nixpacks.toml .htaccess \
      entrypoint.sh nginx.conf .user.ini start.sh

# Dead duplicate .sql files inside migrations/migrations/ — keep run_migrations.php only
find migrations -maxdepth 1 -iname "*.sql" -delete

cd ..

echo ""
echo "✅ Done. migrations/ now contains only the real *.sql migration"
echo "   chain and migrations/migrations/run_migrations.php."
echo ""
echo "Remaining top-level migrations/ contents:"
ls migrations
