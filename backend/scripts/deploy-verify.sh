#!/bin/bash
# Post-deploy smoke for Saveo/OSUS. Run locally or on server.
# Usage: bash scripts/deploy-verify.sh [api_base]

set -e

API_BASE="${1:-https://hotelsystemback.osus.network/api}"
SPA_BASE="${SPA_BASE:-https://hotelsystem.osus.network}"
SPA_ORIGIN="${SPA_ORIGIN:-$SPA_BASE}"

pass=0
fail=0

check() {
  local name="$1"
  local ok="$2"
  if [ "$ok" = "1" ]; then
    echo "PASS: $name"
    pass=$((pass + 1))
  else
    echo "FAIL: $name"
    fail=$((fail + 1))
  fi
}

echo "=== API setup-check ==="
SETUP=$(curl -sf -H "Origin: $SPA_ORIGIN" "$API_BASE/users/setup-check" 2>/dev/null || echo '')
if echo "$SETUP" | grep -q '"success":true'; then
  check "setup-check" 1
else
  check "setup-check" 0
  echo "$SETUP" | head -c 300
  echo ""
fi

echo "=== Login ==="
LOGIN=$(curl -sf -X POST "$API_BASE/users/login" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"job_number":"001","password":"admin123"}' 2>/dev/null || echo '')
TOKEN=$(echo "$LOGIN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["token"]??"";' 2>/dev/null || true)
if [ -n "$TOKEN" ]; then
  check "login 001/admin123" 1
else
  check "login 001/admin123" 0
fi

if [ -n "$TOKEN" ]; then
  echo "=== Dashboard summary ==="
  DASH=$(curl -sf "$API_BASE/users/dashboard/summary" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' 2>/dev/null || echo '')
  if echo "$DASH" | grep -q '"success":true'; then
    check "dashboard/summary" 1
  else
    check "dashboard/summary" 0
  fi

  echo "=== Reports catalog ==="
  CAT=$(curl -sf "$API_BASE/users/reports/catalog" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' 2>/dev/null || echo '')
  if echo "$CAT" | grep -q '"success":true'; then
    check "reports/catalog" 1
    COUNT=$(echo "$CAT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); $r=$j["data"]["reports"]??$j["data"]??[]; echo is_array($r)?count($r):0;' 2>/dev/null || echo 0)
    if [ "${COUNT:-0}" -ge 24 ]; then
      check "reports catalog count >= 24" 1
    else
      check "reports catalog count >= 24 (found $COUNT)" 0
    fi
  else
    check "reports/catalog" 0
  fi

  echo "=== Refund policies ==="
  REF=$(curl -sf "$API_BASE/users/refund-policies" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' 2>/dev/null || echo '')
  REF_COUNT=$(echo "$REF" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo is_array($j["data"]??null)?count($j["data"]):0;' 2>/dev/null || echo 0)
  if [ "${REF_COUNT:-0}" -ge 1 ]; then
    check "refund policies ($REF_COUNT)" 1
  else
    check "refund policies" 0
  fi
fi

echo "=== SPA reachable ==="
SPA_CODE=$(curl -sf -o /dev/null -w '%{http_code}' "$SPA_BASE/" 2>/dev/null || echo '000')
if [ "$SPA_CODE" = "200" ] || [ "$SPA_CODE" = "304" ]; then
  check "SPA $SPA_BASE" 1
else
  check "SPA $SPA_BASE (HTTP $SPA_CODE)" 0
fi

echo "=== SPA bundle hash ==="
SPA_HTML=$(curl -sf "$SPA_BASE/" 2>/dev/null || echo '')
if echo "$SPA_HTML" | grep -qE 'main\.[a-f0-9]+\.js'; then
  HASH=$(echo "$SPA_HTML" | php -r 'if(preg_match("/main\.([a-f0-9]+)\.js/",file_get_contents("php://stdin"),$m)) echo $m[1];' 2>/dev/null || true)
  check "SPA main.js hash present" 1
  echo "INFO: main.$HASH.js"
else
  check "SPA main.js hash present" 0
fi

echo ""
echo "Summary: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
