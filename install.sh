#!/usr/bin/env bash
#
# Mifrog 一键安装脚本 / Mifrog one-shot installer
#
# 使用 / Usage:
#   git clone <repo> /var/www/mifrog
#   cd /var/www/mifrog
#   sudo bash install.sh
#
# 前置 / Prerequisites:
#   PHP 8.2+ (扩展 pdo_mysql, redis, mbstring, bcmath, curl, gd, zip, openssl, xml, json)
#   Composer 2.x, MySQL 5.7+/8.0, Redis 6+, Nginx, Supervisor

set -euo pipefail

# ───────────────────── 颜色 / colors ─────────────────────
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[0;33m'; CYN='\033[0;36m'; NC='\033[0m'
info()  { echo -e "${CYN}[INFO]${NC} $*"; }
ok()    { echo -e "${GRN}[OK]${NC} $*"; }
warn()  { echo -e "${YLW}[WARN]${NC} $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }
ask()   { local prompt="$1" default="${2:-}"; local val
          if [ -n "$default" ]; then read -r -p "$prompt [$default]: " val; echo "${val:-$default}"
          else read -r -p "$prompt: " val; echo "$val"; fi; }
ask_secret() { local prompt="$1" val; read -r -s -p "$prompt: " val; echo >&2; echo "$val"; }

# ───────────────────── 0. 必须 root / require root ─────────────────────
[ "$EUID" -ne 0 ] && { err "请用 sudo 或 root 运行 / Please run as root via sudo"; exit 1; }
INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$INSTALL_DIR"
[ -f "artisan" ] || { err "未在 Mifrog 仓库根目录 / Not in Mifrog repo root: $INSTALL_DIR"; exit 1; }

echo
echo "════════════════════════════════════════════════════"
echo "    Mifrog Installer — 一键部署 / One-shot install"
echo "════════════════════════════════════════════════════"
info "安装目录 / Install dir: $INSTALL_DIR"
echo

# ───────────────────── 1. OS / 包管理器探测 ─────────────────────
OS_ID=""; PKG_HINT=""
if [ -f /etc/os-release ]; then
    . /etc/os-release; OS_ID="${ID:-unknown}"
    case "$OS_ID" in
        ubuntu|debian) PKG_HINT="apt install -y" ;;
        centos|rhel|rocky|almalinux) PKG_HINT="yum install -y" ;;
        *) PKG_HINT="<your package manager>" ;;
    esac
fi
info "系统 / OS: $OS_ID"

# ───────────────────── 2. 依赖检查 / dependency check ─────────────────────
info "检查依赖 / checking dependencies..."
MISSING=()
for cmd in php composer mysql redis-cli openssl; do
    command -v "$cmd" >/dev/null 2>&1 || MISSING+=("$cmd")
done
command -v nginx >/dev/null 2>&1 || warn "未检测到 nginx / nginx not found（仅生成模板，仍可继续）"
command -v supervisorctl >/dev/null 2>&1 || warn "未检测到 supervisor / supervisor not found（仅生成模板，仍可继续）"

if [ ${#MISSING[@]} -gt 0 ]; then
    err "缺少必需命令 / missing required commands: ${MISSING[*]}"
    echo "  请先安装 / install first:  $PKG_HINT ${MISSING[*]}"
    exit 1
fi

# PHP 版本
PHP_VER="$(php -r 'echo PHP_VERSION;')"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    err "PHP 版本过低 / PHP too old: $PHP_VER（需要 / require >= 8.2）"; exit 1
fi
ok "PHP $PHP_VER"

# PHP 扩展
EXT_REQUIRED=(pdo_mysql redis mbstring bcmath curl gd zip openssl xml json fileinfo tokenizer)
EXT_MISSING=()
for ext in "${EXT_REQUIRED[@]}"; do
    php -m | grep -qi "^${ext}$" || EXT_MISSING+=("$ext")
done
if [ ${#EXT_MISSING[@]} -gt 0 ]; then
    err "缺少 PHP 扩展 / missing PHP extensions: ${EXT_MISSING[*]}"
    case "$OS_ID" in
        ubuntu|debian)
            HINT=""
            for e in "${EXT_MISSING[@]}"; do HINT+=" php8.2-$(echo "$e" | sed 's/pdo_mysql/mysql/;s/_//')"; done
            echo "  尝试 / try:  apt install -y$HINT" ;;
        *) echo "  请用包管理器安装这些 PHP 扩展 / install via your package manager" ;;
    esac
    exit 1
fi
ok "PHP 扩展齐全 / all extensions present"

PHP_BIN="$(command -v php)"

# ───────────────────── 3. lark-cli ─────────────────────
ARCH="$(uname -m)"
LARK_CLI_TARGET="/usr/local/bin/lark-cli"
if [ "$ARCH" != "x86_64" ]; then
    warn "当前架构 $ARCH 与仓库内 lark-cli (x86_64) 不匹配 / arch mismatch"
    warn "请到飞书开放平台下载 ARM 版本覆盖 bin/lark-cli / download ARM build manually"
fi
if [ -f "bin/lark-cli" ]; then
    if [ -f "$LARK_CLI_TARGET" ]; then
        info "$LARK_CLI_TARGET 已存在 / exists"
        if [ "$(ask "是否覆盖 / overwrite? (y/N)" "N")" =~ ^[Yy]$ ]; then
            cp bin/lark-cli "$LARK_CLI_TARGET" && chmod +x "$LARK_CLI_TARGET"
            ok "lark-cli 已更新 / updated"
        fi
    else
        cp bin/lark-cli "$LARK_CLI_TARGET" && chmod +x "$LARK_CLI_TARGET"
        ok "lark-cli 已安装到 / installed to $LARK_CLI_TARGET"
    fi
else
    warn "bin/lark-cli 不存在，跳过 / missing, skip"
fi

# ───────────────────── 4. 交互收集 / collect inputs ─────────────────────
echo
info "请填写部署参数 / enter deployment parameters"
echo "  （回车使用方括号内默认值 / Enter accepts default in brackets）"
echo

APP_URL="$(ask "应用域名 / App URL（含协议，例 https://mifrog.example.com）")"
[ -z "$APP_URL" ] && { err "APP_URL 必填 / required"; exit 1; }

WEB_USER="$(ask "Web 进程用户 / Web user (nginx/php-fpm)" "www-data")"
id "$WEB_USER" >/dev/null 2>&1 || { err "用户 $WEB_USER 不存在 / user not found"; exit 1; }

DB_HOST="$(ask "MySQL 主机 / host" "127.0.0.1")"
DB_PORT="$(ask "MySQL 端口 / port" "3306")"
MYSQL_ROOT_USER="$(ask "MySQL root 用户 / root username（用于建库）" "root")"
MYSQL_ROOT_PASSWORD="$(ask_secret "MySQL root 密码 / root password")"

DB_DATABASE="$(ask "应用 DB 名 / app database name" "mifrog")"
DB_USERNAME="$(ask "应用 DB 用户名 / app DB user" "mifrog")"
DB_PASSWORD="$(ask_secret "应用 DB 密码 / app DB password（留空自动生成 / blank = auto）")"
if [ -z "$DB_PASSWORD" ]; then
    DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"
    info "已生成 DB 密码 / generated: $DB_PASSWORD（请妥善保存 / keep it safe）"
fi

REDIS_HOST="$(ask "Redis 主机 / host" "127.0.0.1")"
REDIS_PORT="$(ask "Redis 端口 / port" "6379")"

# ───────────────────── 5. MySQL 连通 + 建库 ─────────────────────
info "测试 MySQL 连接 / testing MySQL..."
if ! mysql -h "$DB_HOST" -P "$DB_PORT" -u "$MYSQL_ROOT_USER" -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; then
    err "MySQL 连接失败 / connection failed"; exit 1
fi
ok "MySQL 连接成功 / connected"

info "建数据库 + 用户 / creating DB + user..."
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$MYSQL_ROOT_USER" -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'%' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USERNAME'@'%' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'%';
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'localhost';
FLUSH PRIVILEGES;
SQL
unset MYSQL_ROOT_PASSWORD
ok "数据库就绪 / database ready"

# Redis 连通
info "测试 Redis 连接 / testing Redis..."
if ! redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping | grep -q PONG; then
    warn "Redis 连接失败 / not responsive（脚本继续，请稍后修 / continue, fix later）"
else
    ok "Redis OK"
fi

# ───────────────────── 6. 写 .env ─────────────────────
info "写入 .env / writing .env..."
[ -f .env ] && cp .env ".env.bak.$(date +%s)" && warn "已备份原 .env / backed up old .env"
cp .env.example .env

set_env() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}
set_env APP_NAME Mifrog
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$APP_URL"
set_env LOG_LEVEL info
set_env DB_CONNECTION mysql
set_env DB_HOST "$DB_HOST"
set_env DB_PORT "$DB_PORT"
set_env DB_DATABASE "$DB_DATABASE"
set_env DB_USERNAME "$DB_USERNAME"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env REDIS_HOST "$REDIS_HOST"
set_env REDIS_PORT "$REDIS_PORT"
set_env REDIS_CLIENT predis
set_env CACHE_DRIVER file
set_env QUEUE_CONNECTION database
set_env SESSION_DRIVER file
set_env FEISHU_CLI_ENABLED true
set_env FEISHU_CLI_BIN "$LARK_CLI_TARGET"
set_env FEISHU_CLI_TIMEOUT_SECONDS 35
set_env FEISHU_CLI_MAX_CONCURRENT 10
set_env MIFROG_DEFAULT_MONTHLY_QUOTA 0
ok ".env 已写入 / written"

# ───────────────────── 7. composer ─────────────────────
info "composer install --no-dev --optimize-autoloader（可能需要数分钟 / may take minutes）..."
sudo -u "$WEB_USER" composer install --no-dev --optimize-autoloader --no-interaction
ok "Composer 完成 / done"

# ───────────────────── 8. APP_KEY ─────────────────────
info "生成 APP_KEY / generating APP_KEY..."
sudo -u "$WEB_USER" "$PHP_BIN" artisan key:generate --force
ok "APP_KEY 已生成 / generated"

# ───────────────────── 9. storage:link ─────────────────────
info "建立 public/storage 软链 / linking storage..."
sudo -u "$WEB_USER" "$PHP_BIN" artisan storage:link 2>&1 | grep -v "already exists" || true
ok "storage:link 完成 / done"

# ───────────────────── 10. 生成 3 个 artifact ─────────────────────
info "生成部署模板 / generating deployment artifacts..."
ART_DIR="$INSTALL_DIR/install_artifacts/output"
mkdir -p "$ART_DIR"

DOMAIN="$(echo "$APP_URL" | sed -E 's|https?://||;s|/.*||')"
PHP_FPM_SOCK_HINT="/run/php/php8.2-fpm.sock  # Ubuntu/Debian; CentOS 通常 / typical: /run/php-fpm/www.sock"

render() {
    sed -e "s|{{INSTALL_DIR}}|$INSTALL_DIR|g" \
        -e "s|{{DOMAIN}}|$DOMAIN|g" \
        -e "s|{{PHP_BIN}}|$PHP_BIN|g" \
        -e "s|{{PHP_FPM_SOCK}}|$PHP_FPM_SOCK_HINT|g" \
        -e "s|{{WEB_USER}}|$WEB_USER|g" "$1"
}

render "install_artifacts/nginx-mifrog.conf.template"      > "$ART_DIR/nginx-mifrog.conf"
render "install_artifacts/supervisor-mifrog.ini.template"  > "$ART_DIR/supervisor-mifrog.ini"
render "install_artifacts/mifrog-crontab.txt.template"     > "$ART_DIR/mifrog-crontab.txt"
ok "模板已生成到 / generated under: $ART_DIR/"

# ───────────────────── 11. 权限 ─────────────────────
info "修复文件权限 / fixing permissions..."
chown -R "$WEB_USER":"$WEB_USER" "$INSTALL_DIR"
chmod -R 775 storage bootstrap/cache
ok "权限修复完成 / permissions fixed"

# ───────────────────── 12. 收尾 / final notes ─────────────────────
echo
echo "════════════════════════════════════════════════════"
ok "基础设施安装完成 / infrastructure installed"
echo "════════════════════════════════════════════════════"
echo
echo "下一步 / Next steps:"
echo
echo "1. Nginx vhost — 复制 / copy:"
echo "     sudo cp $ART_DIR/nginx-mifrog.conf /etc/nginx/conf.d/"
echo "     sudo nginx -t && sudo nginx -s reload"
echo "     # 编辑文件填写 SSL 证书路径 / edit to fill SSL cert paths"
echo
echo "2. Supervisor worker — 复制 / copy:"
echo "     sudo cp $ART_DIR/supervisor-mifrog.ini /etc/supervisor/conf.d/   # Ubuntu/Debian"
echo "     # 或 / or:    /etc/supervisord.d/                               # CentOS"
echo "     sudo supervisorctl reread && sudo supervisorctl update"
echo
echo "3. Crontab — 追加 / append:"
echo "     sudo crontab -e"
echo "     # 把 $ART_DIR/mifrog-crontab.txt 内容粘贴进去 / paste content into editor"
echo
echo "4. 浏览器访问 / open in browser:"
echo "     $APP_URL/setup"
echo
echo "  在 Web 向导里完成飞书 App / 管理员账号 / 模型 API 等业务配置"
echo "  Complete Feishu App / admin / model API in the web wizard"
echo
echo "════════════════════════════════════════════════════"
