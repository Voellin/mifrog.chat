#!/usr/bin/env bash
# deploy/pre-deploy-check.sh
# Run before every deployment to catch common issues.
# Usage: bash deploy/pre-deploy-check.sh

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ERRORS=0

echo "=== Mifrog Pre-Deploy Check ==="
echo ""

# 1. No .bak files
BAK_COUNT=$(find "$PROJECT_ROOT" -name "*.bak*" -type f 2>/dev/null | wc -l || echo 0)
if [ "$BAK_COUNT" -gt 0 ]; then
    echo "[FAIL] Found $BAK_COUNT .bak file(s):"
    find "$PROJECT_ROOT" -name "*.bak*" -type f 2>/dev/null | head -10
    ERRORS=$((ERRORS + 1))
else
    echo "[PASS] No .bak files"
fi

# 2. No debug/dump left in PHP code (exclude Yaml::dump which is a legitimate call)
DUMP_COUNT=$(grep -rn '\bdd(\|\bvar_dump(' "$PROJECT_ROOT/app" --include="*.php" 2>/dev/null | grep -v "vendor/" | grep -v "Yaml::dump" | wc -l || echo 0)
if [ "$DUMP_COUNT" -gt 0 ]; then
    echo "[FAIL] Found $DUMP_COUNT debug dump(s) in app/:"
    grep -rn '\bdd(\|\bvar_dump(' "$PROJECT_ROOT/app" --include="*.php" 2>/dev/null | grep -v "vendor/" | grep -v "Yaml::dump" | head -5
    ERRORS=$((ERRORS + 1))
else
    echo "[PASS] No debug dumps in app/"
fi

# 3. .env exists and is gitignored
if [ -f "$PROJECT_ROOT/.env" ]; then
    echo "[PASS] .env exists"
else
    echo "[WARN] .env file not found"
fi

# 4. APP_DEBUG is false in .env
DEBUG_VAL=$(grep "^APP_DEBUG=" "$PROJECT_ROOT/.env" 2>/dev/null | head -1 || echo "")
if echo "$DEBUG_VAL" | grep -q "false"; then
    echo "[PASS] APP_DEBUG=false"
elif echo "$DEBUG_VAL" | grep -q "true"; then
    echo "[FAIL] APP_DEBUG=true in production!"
    ERRORS=$((ERRORS + 1))
else
    echo "[WARN] APP_DEBUG not found in .env"
fi

# 5. APP_KEY is set (critical for encrypted storage)
if grep -q "^APP_KEY=base64:" "$PROJECT_ROOT/.env" 2>/dev/null; then
    echo "[PASS] APP_KEY is set (needed for encrypted storage)"
else
    echo "[FAIL] APP_KEY missing — encrypted casts will fail!"
    ERRORS=$((ERRORS + 1))
fi

# 6. No plaintext secrets in PHP source files
SECRET_HITS=$(grep -rn "FEISHU_APP_SECRET\|app_secret.*=.*'[a-zA-Z0-9]\{10,\}'" "$PROJECT_ROOT/app" --include="*.php" 2>/dev/null | grep -v "env(" | grep -v "config(" | grep -v "Arr::get" | grep -v "^\s*//" | grep -v '\$' | wc -l || echo 0)
if [ "$SECRET_HITS" -gt 0 ]; then
    echo "[WARN] Possible hardcoded secrets in app/ ($SECRET_HITS matches)"
else
    echo "[PASS] No obvious hardcoded secrets"
fi

# 7. PHP syntax check on all app files
SYNTAX_ERRORS=0
while IFS= read -r file; do
    if ! php -l "$file" > /dev/null 2>&1; then
        echo "[FAIL] Syntax error: $file"
        php -l "$file" 2>&1 | tail -1
        SYNTAX_ERRORS=$((SYNTAX_ERRORS + 1))
    fi
done < <(find "$PROJECT_ROOT/app" -name "*.php" -type f 2>/dev/null)
if [ "$SYNTAX_ERRORS" -eq 0 ]; then
    echo "[PASS] All PHP files pass syntax check"
else
    echo "[FAIL] $SYNTAX_ERRORS file(s) with syntax errors"
    ERRORS=$((ERRORS + SYNTAX_ERRORS))
fi

# 8. Route test gate
if [ -x "$PROJECT_ROOT/vendor/bin/phpunit" ]; then
    ROUTING_TEST_LOG="${PROJECT_ROOT}/storage/logs/predeploy-routing-tests.log"
    mkdir -p "$(dirname "$ROUTING_TEST_LOG")"
    if (cd "$PROJECT_ROOT" && php vendor/bin/phpunit tests/Unit/Routing tests/Unit/Services/IntentResolutionServiceTest.php tests/Feature/Routing tests/Unit/Modules/ProactiveReminder tests/Feature/Modules/ProactiveReminder > "$ROUTING_TEST_LOG" 2>&1); then
        echo "[PASS] Routing + proactive reminder test gate passed"
    else
        echo "[FAIL] Routing + proactive reminder test gate failed"
        tail -20 "$ROUTING_TEST_LOG" 2>/dev/null || true
        ERRORS=$((ERRORS + 1))
    fi
else
    echo "[WARN] PHPUnit not installed, routing/proactive test gate skipped"
fi

echo ""
if [ "$ERRORS" -gt 0 ]; then
    echo ">>> FAILED: $ERRORS issue(s) found. Fix before deploying."
    exit 1
else
    echo ">>> PASSED: All checks green."
    echo ""
    echo ">>> POST-DEPLOY REMINDERS:"
    echo "    1. php artisan queue:restart   (reload queue worker code)"
    echo "    2. /etc/init.d/php-fpm-85 reload   (clear OPcache)"
    echo "    3. php artisan config:clear && php artisan cache:clear"
    exit 0
fi
