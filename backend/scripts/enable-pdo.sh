#!/bin/bash
# Enable PDO on CloudLinux/cPanel (run once via SSH in ~/back.osus.network = hotelsystemback API).
set -e

echo "=== PHP CLI (SSH) ==="
php -v
php -m | grep -i pdo || true

echo ""
echo "NOTE: CLI may show PDO while LiteSpeed/web does NOT."
echo "      Fix web PHP via .user.ini in document root (same folder as index.php)"
echo "      or cPanel → Select PHP Version → hotelsystemback.osus.network → Extensions."

if command -v cloudlinux-selector >/dev/null 2>&1; then
  echo "=== cloudlinux-selector: enable pdo + pdo_mysql (PHP 8.2) ==="
  cloudlinux-selector set --json --interpreter php --version 8.2 \
    --extensions '{"pdo":1,"pdo_mysql":1,"mysqli":1,"openssl":1,"mbstring":1,"tokenizer":1,"xml":1,"ctype":1,"json":1,"bcmath":1,"fileinfo":1}' \
    || true
  echo "=== cloudlinux-selector: enable pdo + pdo_mysql (PHP 8.3) ==="
  cloudlinux-selector set --json --interpreter php --version 8.3 \
    --extensions '{"pdo":1,"pdo_mysql":1,"mysqli":1,"openssl":1,"mbstring":1,"tokenizer":1,"xml":1,"ctype":1,"json":1,"bcmath":1,"fileinfo":1}' \
    || true
else
  echo "cloudlinux-selector not found — enable pdo + pdo_mysql in cPanel → Select PHP Version."
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for ini in "$ROOT/.user.ini" "$ROOT/public/.user.ini"; do
  if [ -f "$ini" ]; then
    echo "=== .user.ini present: $ini ==="
    cat "$ini"
  fi
done

echo ""
echo "Wait 2–5 minutes, then verify:"
echo "  curl -s https://hotelsystemback.osus.network/api/users/setup-check"
echo "  php -m | grep -i pdo"
echo ""
echo "If ready=true, run: bash scripts/server-fix.sh --skip-demo-verify"
