#!/bin/bash
# Run on server via SSH or cPanel Terminal after FTP upload.
# Usage: bash scripts/server-fix.sh [--skip-demo-verify]

set -e

SKIP_DEMO=0
for arg in "$@"; do
  case "$arg" in
    --skip-demo-verify) SKIP_DEMO=1 ;;
  esac
done

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=== PHP ==="
php -v
php -m | grep -i pdo || true

if ! php -m | grep -qi '^PDO$'; then
  echo ""
  echo "ERROR: PDO still missing. Try CloudLinux selector (as your cPanel user):"
  echo '  cloudlinux-selector set --json --interpreter php --version 8.3 --extensions '"'"'{"pdo":1,"pdo_mysql":1,"mysqli":1}'"'"
  echo "Or enable pdo + pdo_mysql in cPanel → Select PHP Version for hotelsystemback.osus.network"
  exit 1
fi

echo "=== Composer (if vendor missing) ==="
if [ ! -f vendor/autoload.php ]; then
  if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    echo "WARN: vendor/ missing and composer not in PATH — run composer install manually."
  fi
fi

echo "=== Passport keys ==="
if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
  php artisan passport:keys --force
fi

echo "=== Storage link ==="
php artisan storage:link 2>/dev/null || true

echo "=== Production .env checks ==="
if [ -f .env ]; then
  if grep -q '^APP_DEBUG=true' .env 2>/dev/null; then
    echo "WARN: Set APP_DEBUG=false in .env for production."
  fi
  if ! grep -q '^FRONTEND_URL=' .env 2>/dev/null; then
    echo "WARN: Set FRONTEND_URL=https://hotelsystem.osus.network before config:cache."
  fi
else
  echo "WARN: .env not found — create from .env.example before continuing."
fi

echo "=== Laravel ==="
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan migrate --force
php artisan db:seed --class=RefundPolicySeeder --force
php artisan hotel:ensure-admin
php artisan accounting:backfill-journal
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan reports:verify

if [ "$SKIP_DEMO" -eq 0 ]; then
  php artisan demo:verify || echo "WARN: demo:verify failed (ok on production data with --skip-demo-verify)."
else
  echo "SKIP: demo:verify (--skip-demo-verify)"
fi

echo "=== Refund policies ==="
POLICY_COUNT=$(php artisan tinker --execute="echo \\App\\Models\\RefundPolicy::count();" 2>/dev/null | tail -1 || echo "0")
echo "Refund policies in DB: $POLICY_COUNT"
if [ "${POLICY_COUNT:-0}" -lt 1 ] 2>/dev/null; then
  echo "WARN: No refund policies — re-run RefundPolicySeeder."
fi

echo "=== Done ==="
echo "Verify: curl -s https://hotelsystemback.osus.network/api/users/setup-check | head -c 500"
echo "Login: job 001 (change password in production: php artisan hotel:ensure-admin --password='YourSecurePass')"
