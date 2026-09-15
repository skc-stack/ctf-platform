#!/usr/bin/env bash
# ============================================================================
# CTF Server 安裝腳本（Ubuntu 24.04 LTS）
# ============================================================================
# 用法：
#   sudo ./scripts/server/install-server.sh
#
# 功能：
#   - 安裝 Apache + PHP 8.3 + MariaDB + composer
#   - 建立 ctf_server DB + ctf_server_app user
#   - 載入 schema
#   - 部署 ctf-server 到 /var/www/ctf-server
#   - 建立 storage / log 目錄
#   - 寫入 .env（含隨機 APP_KEY / FLAG_MASTER_SECRET）
#   - 設定 Apache vhost
#   - 設定 cron：每天清理過期 activation code / task
#
# 注意：此腳本必須以 root 或 sudo 執行。
# ============================================================================

set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "[ERR] 請以 sudo 執行此腳本"
  exit 1
fi

CTF_USER="${SUDO_USER:-ctf}"
CTF_GROUP="www-data"
WEB_ROOT="/var/www/ctf-server"
STORAGE_ROOT="/var/lib/ctf-server"
LOG_ROOT="/var/log/ctf-server"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "[1/9] 安裝系統套件"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    libapache2-mod-php8.3 \
    php8.3-cli \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-mysql \
    php8.3-zip \
    php8.3-opcache \
    mariadb-server \
    unzip \
    git \
    cron \
    ca-certificates

echo "[2/9] 安裝 composer"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
  if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
    echo '[ERR] composer 安裝檔 checksum 驗證失敗'
    rm -f composer-setup.php
    exit 1
  fi
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f composer-setup.php
fi

echo "[3/9] 建立目錄結構"
mkdir -p "$WEB_ROOT" "$STORAGE_ROOT"/{challenges,keys} "$LOG_ROOT"
chown -R "$CTF_USER:$CTF_GROUP" "$WEB_ROOT" "$STORAGE_ROOT"
chown -R "$CTF_USER:$CTF_GROUP" "$LOG_ROOT"
chmod 750 "$STORAGE_ROOT/keys"

echo "[4/9] 複製程式碼"
rsync -a --delete \
    --exclude='.env' \
    --exclude='vendor/' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/tmp/*' \
    "$SCRIPT_DIR/ctf-server/" "$WEB_ROOT/"

cd "$WEB_ROOT"
composer install --no-dev --optimize-autoloader --no-interaction

echo "[5/9] 設定 MariaDB"
DB_ROOT_PASSWORD="${CTF_DB_ROOT_PASSWORD:-}"
if [[ -n "$DB_ROOT_PASSWORD" ]]; then
  mariadb -u root -p"$DB_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS ctf_server
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ctf_server_app'@'localhost' IDENTIFIED BY 'ctf_srv_pwd_change_me';
GRANT ALL PRIVILEGES ON ctf_server.* TO 'ctf_server_app'@'localhost';
FLUSH PRIVILEGES;
SQL
  mariadb -u root -p"$DB_ROOT_PASSWORD" ctf_server < "$WEB_ROOT/database/migrations/000_init.sql"
else
  echo "[WARN] 未提供 CTF_DB_ROOT_PASSWORD；跳過 DB schema 建立（請手動執行）"
fi

echo "[6/9] 寫入 .env"
APP_KEY="$(openssl rand -hex 24)"
FLAG_SECRET="$(openssl rand -hex 32)"
DB_PASSWORD="${CTF_DB_APP_PASSWORD:-ctf_srv_pwd_change_me}"
cat > "$WEB_ROOT/.env" <<ENV
APP_NAME="CTF LAB"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ctf.example.edu.tw
APP_TIMEZONE=Asia/Taipei
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

echo "[7/9] 設定 Apache vhost"
cat > /etc/apache2/sites-available/ctf-server.conf <<VHOST
<VirtualHost *:80>
    ServerName ctf.example.edu.tw
    DocumentRoot $WEB_ROOT/public
    <Directory $WEB_ROOT/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog $LOG_ROOT/apache-error.log
    CustomLog $LOG_ROOT/apache-access.log combined
</VirtualHost>
VHOST
a2ensite ctf-server.conf
a2enmod rewrite headers
systemctl reload apache2

echo "[8/9] 建立 cron：清理過期 activation code / task"
cat > /etc/cron.daily/ctf-server-cleanup <<CRON
#!/bin/sh
/usr/bin/php $WEB_ROOT/bin/cleanup.php
CRON
chmod +x /etc/cron.daily/ctf-server-cleanup

echo "[9/9] 建立第一位 admin"
echo ""
echo "請執行以下指令建立第一位 admin 帳號："
echo "  sudo -u $CTF_USER php $WEB_ROOT/bin/create-admin.php"
echo ""
echo "[完成] CTF Server 安裝完成"
echo "  - Web:  http://ctf.example.edu.tw/"
echo "  - Logs: $LOG_ROOT/"
