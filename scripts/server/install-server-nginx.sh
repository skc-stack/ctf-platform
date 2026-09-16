#!/usr/bin/env bash
# ============================================================================
# CTF Server 安裝腳本（Nginx 版本）
# Ubuntu 24.04 LTS + Nginx + PHP 8.3 FPM + MariaDB
# ============================================================================
# 用法：
#   sudo ./scripts/server/install-server-nginx.sh
#
# 功能：
#   - 安裝 Nginx + PHP 8.3 FPM + MariaDB + composer
#   - 建立 ctf_server DB + ctf_server_app user
#   - 載入 schema
#   - 部署 ctf-server 到 /var/www/ctf-server
#   - 建立 storage / log 目錄
#   - 寫入 .env（含隨機 APP_KEY / FLAG_MASTER_SECRET）
#   - 設定 Nginx vhost
#   - 設定 cron：每天清理過期 activation code / task
#
# 環境變數：
#   CTF_DOMAIN      - 網域名稱（預設：ctf.example.edu.tw）
#   CTF_DB_ROOT_PASSWORD - MariaDB root 密碼
#   CTF_USE_HTTPS  - 是否啟用 HTTPS（預設：false）
# ============================================================================

set -euo pipefail

# 檢查是否為 root
if [[ "$EUID" -ne 0 ]]; then
  echo "[ERR] 請以 sudo 執行此腳本"
  exit 1
fi

# 設定預設值（已針對高中資安演練平台調整）
CTF_DOMAIN="${CTF_DOMAIN:-ctf.kghs.kh.edu.tw}"
CTF_USE_HTTPS="${CTF_USE_HTTPS:-true}"
CTF_USER="${SUDO_USER:-www-data}"
CTF_GROUP="www-data"
WEB_ROOT="/var/www/html/ctf.kghs.kh.edu.tw"
STORAGE_ROOT="/var/lib/ctf-server"
LOG_ROOT="/var/log/ctf-server"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# 網域格式驗證（防止路徑穿越）
valid_domain_pattern='^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$'
if [[ ! "$CTF_DOMAIN" =~ $valid_domain_pattern ]]; then
  echo "[ERR] CTF_DOMAIN 格式無效: '$CTF_DOMAIN'"
  echo "  有效格式: 字母、數字、連字號與點，不得包含 /、\\、.. 等路徑字元"
  exit 1
fi

echo "============================================"
echo "  CTF Server 安裝（Nginx 版本）"
echo "  高中資安攻防演練平台"
echo "============================================"
echo "  網域: $CTF_DOMAIN"
echo "  HTTPS: $CTF_USE_HTTPS (Let's Encrypt)"
echo "  Web Root: $WEB_ROOT"
echo "  使用者: $CTF_USER"
echo "============================================"
echo ""

# --------------------------------------------------
# [1/10] 安裝系統套件
# --------------------------------------------------
echo "[1/10] 安裝系統套件"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    nginx \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-mysql \
    php8.3-zip \
    php8.3-opcache \
    php8.3-intl \
    mariadb-server \
    unzip \
    git \
    cron \
    ca-certificates \
    openssl \
    curl

# 啟用 PHP FPM 服務
systemctl enable php8.3-fpm
systemctl start php8.3-fpm

# --------------------------------------------------
# [2/10] 安裝 Composer
# --------------------------------------------------
echo "[2/10] 安裝 Composer"
if ! command -v composer >/dev/null 2>&1; then
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
        echo '[ERR] Composer 安裝檔 checksum 驗證失敗'
        rm -f composer-setup.php
        exit 1
    fi
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
    echo "  Composer 已安裝"
else
    echo "  Composer 已存在: $(composer --version)"
fi

# --------------------------------------------------
# [3/10] 建立目錄結構
# --------------------------------------------------
echo "[3/10] 建立目錄結構"
mkdir -p "$WEB_ROOT" "$STORAGE_ROOT"/{challenges,keys} "$LOG_ROOT"/{nginx,php-fpm}
mkdir -p /etc/letsencrypt/live/$CTF_DOMAIN
chown -R "$CTF_USER:$CTF_GROUP" "$WEB_ROOT" "$STORAGE_ROOT" "$LOG_ROOT"
chmod 750 "$STORAGE_ROOT/keys"

# --------------------------------------------------
# [4/10] 複製程式碼
# --------------------------------------------------
echo "[4/10] 複製程式碼"
rsync -a --delete \
    --exclude='.env' \
    --exclude='.env.local' \
    --exclude='vendor/' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/tmp/*' \
    --exclude='*.zip' \
    "$SCRIPT_DIR/ctf-server/" "$WEB_ROOT/"

cd "$WEB_ROOT"
composer install --no-dev --optimize-autoloader --no-interaction

# --------------------------------------------------
# [5/10] 設定 MariaDB
# --------------------------------------------------
echo "[5/10] 設定 MariaDB"
DB_ROOT_PASSWORD="${CTF_DB_ROOT_PASSWORD:-}"
if [[ -z "$DB_ROOT_PASSWORD" ]]; then
    echo "[WARN] 未提供 CTF_DB_ROOT_PASSWORD，將使用空白密碼（僅供測試）"
    DB_ROOT_PASSWORD=""
fi

if [[ -n "$DB_ROOT_PASSWORD" ]]; then
    mysql -u root -p"$DB_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS ctf_server
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ctf_server_app'@'localhost' IDENTIFIED BY 'ctf_srv_pwd_change_me';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX
  ON ctf_server.* TO 'ctf_server_app'@'localhost';
FLUSH PRIVILEGES;
SQL
    echo "  MariaDB 設定完成"
else
    # 嘗試使用無密碼登入
    if mysql -u root <<SQL; then
CREATE DATABASE IF NOT EXISTS ctf_server
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ctf_server_app'@'localhost' IDENTIFIED BY 'ctf_srv_pwd_change_me';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX
  ON ctf_server.* TO 'ctf_server_app'@'localhost';
FLUSH PRIVILEGES;
SQL
        echo "  MariaDB 設定完成（無密碼模式）"
    else
        echo "[ERR] 無法連接 MariaDB，請檢查服務狀態"
        exit 1
    fi
fi

# 載入資料庫 schema
echo "  載入資料庫 schema..."
mysql -u root -p"$DB_ROOT_PASSWORD" ctf_server < "$WEB_ROOT/database/migrations/000_init.sql" 2>/dev/null || \
mysql -u root ctf_server < "$WEB_ROOT/database/migrations/000_init.sql" 2>/dev/null || \
echo "[WARN] schema 載入失敗，請手動執行"

# --------------------------------------------------
# [6/10] 寫入 .env
# --------------------------------------------------
echo "[6/10] 寫入 .env"
APP_KEY="$(openssl rand -hex 24)"
FLAG_SECRET="$(openssl rand -hex 32)"
DB_PASSWORD="ctf_srv_pwd_change_me"

# 根據 HTTPS 設定決定 APP_URL
if [[ "$CTF_USE_HTTPS" == "true" ]]; then
    APP_URL="https://$CTF_DOMAIN"
else
    APP_URL="http://$CTF_DOMAIN"
fi

cat > "$WEB_ROOT/.env" <<ENV
APP_NAME="CTF LAB"
APP_ENV=production
APP_DEBUG=false
APP_URL=$APP_URL
APP_TIMEZONE=Asia/Taipei
APP_VERSION=0.4
APP_KEY=$APP_KEY

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ctf_server
DB_USERNAME=ctf_server_app
DB_PASSWORD=$DB_PASSWORD

SESSION_NAME=ctf_session
SESSION_LIFETIME=7200

FLAG_MASTER_SECRET=$FLAG_SECRET
PACKAGE_SIGNING_PRIVATE_KEY_PATH=$STORAGE_ROOT/keys/package-signing.pem

STORAGE_CHALLENGE_PATH=$STORAGE_ROOT/challenges
STORAGE_LOG_PATH=$LOG_ROOT

RATE_LIMIT_LOGIN=5
RATE_LIMIT_ACTIVATION=5
RATE_LIMIT_TASK_VALIDATE=10
RATE_LIMIT_FLAG_SUBMIT=10

DEFAULT_TASK_TTL=120
MAX_DEVICES_PER_STUDENT=3
ENV

chown "$CTF_USER:$CTF_GROUP" "$WEB_ROOT/.env"
chmod 640 "$WEB_ROOT/.env"

# 生成 package signing key（如果不存在）
if [[ ! -f "$STORAGE_ROOT/keys/package-signing.pem" ]]; then
    openssl genrsa -out "$STORAGE_ROOT/keys/package-signing.pem" 2048 2>/dev/null
    chmod 600 "$STORAGE_ROOT/keys/package-signing.pem"
fi

echo "  .env 已寫入"

# --------------------------------------------------
# [7/10] 設定 Nginx vhost（針對 Let's Encrypt）
# --------------------------------------------------
echo "[7/10] 設定 Nginx vhost"

# 複製設定檔
NGINX_CONF="$(dirname "$SCRIPT_DIR")/nginx/ctf-server.conf"
if [[ ! -f "$NGINX_CONF" ]]; then
    echo "[ERR] Nginx 設定檔不存在: $NGINX_CONF"
    exit 1
fi
cp "$NGINX_CONF" /etc/nginx/sites-available/ctf-server

# 修改設定檔
sed -i "s|ctf\.example\.edu\.tw|$CTF_DOMAIN|g" /etc/nginx/sites-available/ctf-server
sed -i "s|/var/www/ctf-server/ctf-server/public|$WEB_ROOT/public|g" /etc/nginx/sites-available/ctf-server

# 針對 Let's Encrypt 設定 SSL 路徑
SSL_CERT="/etc/letsencrypt/live/$CTF_DOMAIN/fullchain.pem"
SSL_KEY="/etc/letsencrypt/live/$CTF_DOMAIN/privkey.pem"

# 啟用 SSL 設定
sed -i "s|# listen 443 ssl;|listen 443 ssl http2;|" /etc/nginx/sites-available/ctf-server
sed -i "s|# ssl_certificate     /etc/ssl/certs/ctf.lab.crt;|ssl_certificate     $SSL_CERT;|" /etc/nginx/sites-available/ctf-server
sed -i "s|# ssl_certificate_key /etc/ssl/private/ctf.lab.key;|ssl_certificate_key $SSL_KEY;|" /etc/nginx/sites-available/ctf-server
sed -i "s|# ssl_protocols       TLSv1.2 TLSv1.3;|ssl_protocols       TLSv1.2 TLSv1.3;|" /etc/nginx/sites-available/ctf-server
sed -i "s|# ssl_ciphers         HIGH:!aNULL:!MD5;|ssl_ciphers         HIGH:!aNULL:!MD5;|" /etc/nginx/sites-available/ctf-server

# 啟用 HTTP to HTTPS 重導向
sed -i "s|# server {|server {|" /etc/nginx/sites-available/ctf-server
sed -i "s|#     listen 80;|    listen 80;|" /etc/nginx/sites-available/ctf-server
sed -i "s|#     server_name ctf.example.edu.tw;|    server_name $CTF_DOMAIN;|" /etc/nginx/sites-available/ctf-server
sed -i "s|#     return 301 https://\$server_name\$request_uri;|    return 301 https://\$server_name\$request_uri;|" /etc/nginx/sites-available/ctf-server
sed -i "s|# }|}|" /etc/nginx/sites-available/ctf-server

# 移除預設 site
rm -f /etc/nginx/sites-enabled/default

# 啟用 site
ln -sf /etc/nginx/sites-available/ctf-server /etc/nginx/sites-enabled/

# 測試 Nginx 設定
nginx -t
systemctl reload nginx

echo "  Nginx vhost 已設定"
echo "  SSL 憑證路徑: $SSL_CERT"

# --------------------------------------------------
# [8/10] 設定 PHP-FPM
# --------------------------------------------------
echo "[8/10] 設定 PHP-FPM"

# 確保 PHP-FPM 正在運行
systemctl enable php8.3-fpm
systemctl restart php8.3-fpm

# 建立 PHP-FPM 日誌目錄
mkdir -p /var/log/php-fpm
chown www-data:adm /var/log/php-fpm

echo "  PHP-FPM 已設定"

# --------------------------------------------------
# [9/10] 設定 Cron
# --------------------------------------------------
echo "[9/10] 設定 Cron"

# 清理過期 activation code 和 task
cat > /etc/cron.d/ctf-server-cleanup <<CRON
# 每天凌晨 3 點執行清理
0 3 * * * $CTF_USER php $WEB_ROOT/bin/cleanup.php >> $LOG_ROOT/cron.log 2>&1
CRON

chmod 644 /etc/cron.d/ctf-server-cleanup
echo "  Cron 已設定"

# --------------------------------------------------
# [10/10] 完成
# --------------------------------------------------
echo ""
echo "============================================"
echo "  CTF Server 安裝完成！"
echo "============================================"
echo ""
echo "  Web:  $APP_URL/"
echo "  Logs: $LOG_ROOT/"
echo ""
echo "  下一步："
echo "  1. 如果啟用 HTTPS，請設定 SSL 憑證："
echo "     sudo certbot --nginx -d $CTF_DOMAIN"
echo ""
echo "  2. 建立第一位 admin 帳號："
echo "     sudo -u $CTF_USER php $WEB_ROOT/bin/create-admin.php"
echo ""
echo "  3. 建立第一個題目："
echo "     cd $WEB_ROOT && php bin/seed-challenge.php"
echo ""
echo "  4. 測試連線："
echo "     curl -I $APP_URL/"
echo ""
echo "============================================"
