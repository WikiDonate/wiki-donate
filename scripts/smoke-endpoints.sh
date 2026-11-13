#!/usr/bin/env bash
#
# Endpoint smoke test (API-level E2E) for a running WikiDonate instance.
#
#   ./scripts/smoke-endpoints.sh
#   BASE_URL=https://staging.example.com ./scripts/smoke-endpoints.sh
#
# Needs: curl, php artisan (run from the project root), and seeded users
# (php artisan db:seed) for the token checks. Temporary Sanctum tokens are
# removed on exit. It never calls PayPal/Stripe — those flows are covered by
# tests/Feature with fakes.
set -uo pipefail

BASE="${BASE_URL:-http://localhost:8000}"
ADMIN_EMAIL="${ADMIN_EMAIL:-gshahaj+admin@gmail.com}"
EDITOR_EMAIL="${EDITOR_EMAIL:-editor@demo.local}"
DONOR_EMAIL="${DONOR_EMAIL:-donor@demo.local}"

PASS=0
FAIL=0
BODY="$(mktemp)"
CREATED_TOKENS=()

cleanup() {
    for entry in "${CREATED_TOKENS[@]:-}"; do
        [[ -z "$entry" ]] && continue
        local email="${entry%%|*}"
        local name="${entry#*|}"
        tinker "App\\Models\\User::where('email','${email}')->first()?->tokens()->where('name','${name}')->delete();" >/dev/null
    done
    rm -f "$BODY"
}
trap cleanup EXIT

tinker() {
    php artisan tinker --execute="$1" 2>/dev/null | tail -1 | tr -d '\r'
}

mint() {
    local email="$1" name="$2" token
    token=$(tinker "echo App\\Models\\User::where('email','${email}')->first()?->createToken('${name}')->plainTextToken;")
    if [[ -z "$token" || "$token" == "null" ]]; then
        echo "!! cannot mint a token for ${email} — run: php artisan db:seed" >&2
        exit 1
    fi
    CREATED_TOKENS+=("${email}|${name}")
    printf '%s' "$token"
}

check() {
    local desc="$1" expected="$2" url="$3" token="${4:-}" want_ct="${5:-}"
    local args=(-s -o "$BODY" -w '%{http_code}|%{content_type}' -H 'Accept: application/json')
    [[ -n "$token" ]] && args+=(-H "Authorization: Bearer ${token}")
    local out code ct ok=0
    out=$(curl "${args[@]}" "${BASE}${url}")
    code="${out%%|*}"
    ct="${out#*|}"
    [[ "$code" == "$expected" ]] && ok=1
    if [[ -n "$want_ct" && "$ct" != *"$want_ct"* ]]; then ok=0; fi
    if [[ $ok -eq 1 ]]; then
        PASS=$((PASS + 1))
        printf '  ok   %-58s %s\n' "$desc" "$code"
    else
        FAIL=$((FAIL + 1))
        printf '  FAIL %-58s got %s (want %s)\n' "$desc" "$code" "$expected"
        head -c 300 "$BODY" | sed 's/^/       /'
        echo
    fi
}

echo "Smoke testing ${BASE}"
echo

SLUG=$(tinker "echo App\\Models\\Article::query()->value('slug');")
FORMULA_UUID=$(tinker "echo App\\Models\\DonationFormula::query()->value('uuid');")
FORMULA_ID=$(tinker "echo App\\Models\\DonationFormula::query()->value('id');")
ORG=$(tinker "echo App\\Models\\Organization::query()->value('name');")
ENC_ORG=$(python3 -c "import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))" "${ORG:-Org}")

ADMIN_TOKEN=$(mint "$ADMIN_EMAIL" 'smoke-admin')
EDITOR_TOKEN=$(mint "$EDITOR_EMAIL" 'smoke-editor')
DONOR_TOKEN=$(mint "$DONOR_EMAIL" 'smoke-donor')

echo "Public"
check "SPA shell" 200 "/" "" "text/html"
check "GET payment-methods" 200 "/api/v1/payment-methods"
check "GET articles" 200 "/api/v1/articles"
check "GET article by slug" 200 "/api/v1/articles/${SLUG}"
check "GET search" 200 "/api/v1/search?query=water"
check "GET payment public key" 200 "/api/v1/payment-methods/paypal/public-key"
check "GET article formulas" 200 "/api/v1/donation-formulas/article/${SLUG}"
check "GET page content" 200 "/api/v1/page-contents/how-it-works"

echo
echo "Authenticated (editor — role:Admin|Editor)"
check "GET my profile" 200 "/api/v1/user/get" "$EDITOR_TOKEN"
check "GET notifications" 200 "/api/v1/user/notifications" "$EDITOR_TOKEN"
check "GET my articles" 200 "/api/v1/articles/my" "$EDITOR_TOKEN"
check "GET formula by uuid" 200 "/api/v1/donation-formulas/${FORMULA_UUID}" "$EDITOR_TOKEN"

echo
echo "Authenticated (donor)"
check "GET donation report" 200 "/api/v1/report/donations" "$DONOR_TOKEN"

echo
echo "Admin"
check "GET dashboard" 200 "/api/v1/admin/dashboard" "$ADMIN_TOKEN"
check "GET donations" 200 "/api/v1/admin/donations" "$ADMIN_TOKEN"
check "GET transactions" 200 "/api/v1/admin/transactions" "$ADMIN_TOKEN"
check "GET transactions summary" 200 "/api/v1/admin/transactions/summary" "$ADMIN_TOKEN"
check "GET transactions CSV" 200 "/api/v1/admin/transactions/export" "$ADMIN_TOKEN" "text/csv"
check "GET payouts" 200 "/api/v1/admin/payouts" "$ADMIN_TOKEN"
check "GET payout allocations" 200 "/api/v1/admin/payouts/allocations" "$ADMIN_TOKEN"
check "GET payout history" 200 "/api/v1/admin/payouts/history?donation_formula_id=${FORMULA_ID}&organization=${ENC_ORG}" "$ADMIN_TOKEN"
check "GET organizations" 200 "/api/v1/admin/organizations" "$ADMIN_TOKEN"
check "GET organization registry search" 200 "/api/v1/admin/organizations/search?q=red" "$ADMIN_TOKEN"
check "GET payment-methods (admin)" 200 "/api/v1/admin/payment-methods" "$ADMIN_TOKEN"
check "GET articles (admin)" 200 "/api/v1/admin/articles" "$ADMIN_TOKEN"
check "GET article (admin)" 200 "/api/v1/admin/articles/${SLUG}" "$ADMIN_TOKEN"
check "GET transaction logs" 200 "/api/v1/admin/transaction-logs" "$ADMIN_TOKEN"
check "GET transaction-log filters" 200 "/api/v1/admin/transaction-logs/filters" "$ADMIN_TOKEN"
check "GET transaction-log CSV" 200 "/api/v1/admin/transaction-logs/export" "$ADMIN_TOKEN" "text/csv"

echo
echo "Permission gates"
check "donation report without token" 401 "/api/v1/report/donations"
check "admin dashboard with donor token" 403 "/api/v1/admin/dashboard" "$DONOR_TOKEN"

echo
echo "Passed: ${PASS}  Failed: ${FAIL}"
[[ $FAIL -eq 0 ]]
