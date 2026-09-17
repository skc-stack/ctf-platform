#!/usr/bin/env bash
# target/install-portal.sh — Install Target Portal (PHP) on a Target VM.
#
# Run as root on Ubuntu 24.04 after install.sh (which provisions the Agent).
# Idempotent: re-running on an already-provisioned VM is safe.
#
# Layout produced:
#   /var/www/ctf-target-portal/public/   — DocumentRoot
#   /etc/apache2/sites-available/ctf-portal.conf
#   /var/log/apache2/ctf-portal-{access,error}.log

set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ERROR: must run as root" >&2
  exit 1
fi

PORTAL_SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/portal" && pwd)"
PORTAL_DEST="/var/www/ctf-target-portal"

# 1. Install PHP + Apache if not present
if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: PHP not installed — run install.sh first to set up the Agent" >&2
  exit 1
fi
if ! command -v apache2ctl >/dev/null 2>&1; then
  apt-get install -y -qq apache2 libapache2-mod-php
fi

# 2. Copy source
install -d "$PORTAL_DEST/public" "$PORTAL_DEST/src" "$PORTAL_DEST/views"
cp -r "$PORTAL_SRC_DIR/src" "$PORTAL_DEST/src"
cp -r "$PORTAL_SRC_DIR/views" "$PORTAL_DEST/views"
cp "$PORTAL_SRC_DIR/public/index.php" "$PORTAL_DEST/public/index.php"
chmod -R a+rX "$PORTAL_DEST"

# 3. Apache vhost
install -m 0644 "$PORTAL_SRC_DIR/apache/ctf-portal.conf" /etc/apache2/sites-available/ctf-portal.conf
a2ensite ctf-portal
# Make sure the default site (port 80 on 0.0.0.0) doesn't shadow our loopback-only one.
if a2query -s 000-default >/dev/null 2>&1; then
  a2dissite 000-default
fi

# 4. Enable mod_rewrite (Portal's .htaccess-style fallback is in the vhost).
if ! a2query -m rewrite >/dev/null 2>&1; then
  a2enmod rewrite
fi

# 5. Validate Apache config before reloading
apache2ctl configtest
systemctl reload apache2

echo "CTF Target Portal installed."
echo "Open in browser on this VM: http://127.0.0.1/"
echo "Agent must already be running: systemctl status ctf-agent.service"
