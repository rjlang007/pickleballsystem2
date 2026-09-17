#!/usr/bin/env bash
# Zips the project for sharing/deployment WITHOUT secrets.
# Usage: ./scripts/package.sh
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="pickleball-$(date +%Y%m%d-%H%M%S).zip"
zip -r "$OUT" . \
  -x ".env" \
  -x ".git/*" \
  -x "storage/logs/*" \
  -x "*.log"
echo "Wrote $OUT (no .env included — verify with: unzip -l $OUT | grep -i env)"
