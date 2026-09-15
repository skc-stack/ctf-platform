# CTF LAB — Deployment Guide

Production deployment on Ubuntu 24.04 LTS. Assumes a clean VM image
with `sudo` access for the deploy user.

## Architecture (Lab environment)

```
Internet
   │
   ▼ HTTPS (port 443)
┌─────────────────────┐
│  Reverse Proxy       │ (Apache + mod_ssl, or nginx)
│  ctf.lab.example.com │
└─────────┬───────────┘
          │
          ▼ HTTP (loopback)
┌─────────────────────┐
│  CTF Server          │
│  ctf-server/         │
│  Apache + PHP 8.3    │
│  MariaDB             │
└─────────────────────┘

(Student laptops connect to the Server directly over HTTPS)

Per student Target VM (one per student):
┌─────────────────────┐
│  Target VM           │
│  Python Agent        │ ◀── systemd ctf-agent.service
│  PHP Portal          │ ◀── Apache on 127.0.0.1:80
│  Challenge web/      │
│  ctf_target DB       │
└─────────────────────┘
```

## Prerequisites

- Ubuntu Server 24.04 LTS (both Server and Target VMs)
- Public DNS: `ctf.lab.example.com` → Server public IP
- Each Target VM reachable from the student (via SSH or local console)

## Server deployment

```bash
# 1. Install OS packages
sudo apt update
sudo apt install -y apache2 php8.3 php8.3-{cli,mysql,xml,mbstring,curl} \
                    mariadb-server composer git

# 2. Create DB user (limited privs)
sudo mysql <<SQL
CREATE USER 'ctf_server_app'@'localhost' IDENTIFIED BY '...';
CREATE DATABASE ctf_server;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX
  ON ctf_server.* TO 'ctf_server_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# 3. Copy source
sudo mkdir -p /var/www/ctf-server
sudo chown www-data:www-data /var/www/ctf-server
sudo -u www-data git clone https://github.com/your-org/ctf-platform.git /var/www/ctf-server

# 4. Configure
cd /var/www/ctf-server/ctf-server
sudo -u www-data cp .env.example .env
sudo -u www-data vi .env   # set DB_*, APP_KEY, FLAG_MASTER_SECRET, NYLAS_*
sudo -u www-data composer install --no-dev

# 5. Migrate
sudo -u www-data php bin/migrate.php

# 6. Create initial admin
sudo -u www-data php bin/create-admin.php

# 7. Apache vhost
# /etc/apache2/sites-available/ctf-server.conf
<VirtualHost *:443>
    ServerName ctf.lab.example.com
    DocumentRoot /var/www/ctf-server/ctf-server/public
    SSLEngine on
    SSLCertificateFile      /etc/ssl/certs/ctf.lab.crt
    SSLCertificateKeyFile   /etc/ssl/private/ctf.lab.key
    <Directory /var/www/ctf-server/ctf-server/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/ctf-server-error.log
    CustomLog ${APACHE_LOG_DIR}/ctf-server-access.log combined
</VirtualHost>

sudo a2enmod ssl rewrite headers
sudo a2ensite ctf-server
sudo apache2ctl configtest
sudo systemctl reload apache2

# 8. Daily cleanup cron
echo "0 3 * * * www-data php /var/www/ctf-server/ctf-server/bin/cleanup.php" \
    | sudo tee /etc/cron.d/ctf-server-cleanup

# 9. Backups (daily)
echo "0 2 * * * /usr/bin/mysqldump ctf_server > /var/backups/ctf_server-\$(date +\%F).sql" \
    | sudo tee /etc/cron.d/ctf-server-backup
```

## Target VM deployment (one per student)

```bash
# 1. As root, install OS packages
sudo apt update
sudo apt install -y python3-venv python3-pip mariadb-client apache2 \
                    php8.3 libapache2-mod-php

# 2. Copy source
sudo mkdir -p /opt
sudo git clone https://github.com/your-org/ctf-platform.git /opt/ctf-platform

# 3. Install Agent (creates systemd service + MariaDB user)
cd /opt/ctf-platform/target
sudo ./install.sh

# 4. Install Portal (creates Apache vhost on 127.0.0.1:80)
sudo ./install-portal.sh

# 5. Configure Agent
sudo vi /etc/ctf-agent/config.json   # set server_url

# 6. Activate
# Student opens http://127.0.0.1/ on the VM itself (loopback only)
# Pastes their activation code from the Server dashboard
```

## Network requirements

| From | To | Port | Purpose |
|------|----|------|---------|
| Student laptop | CTF Server | 443 | HTTPS web UI |
| Target VM | CTF Server | 443 | Device API (HTTPS) |
| Target Portal | Target Agent | 8787 | loopback (127.0.0.1) |
| Target browser | Target Portal | 80 | loopback (127.0.0.1) |
| CTF Server | MariaDB | 3306 | loopback (localhost) |
| Target Agent | MariaDB | 3306 | loopback (localhost) |

Nothing else needs to be open. The Target VM does NOT accept inbound
HTTP from the network; the Portal binds 127.0.0.1 only.

## TLS

- Server uses Let's Encrypt or institutional CA cert.
- Target Agent connects to `https://...` — verify with `openssl s_client -connect ctf.lab:443` from the Target VM.
- `server_url` in `/etc/ctf-agent/config.json` MUST use `https://` (not `http://`).

## Firewall rules (UFW example)

```bash
# Server
sudo ufw allow 443/tcp
sudo ufw allow OpenSSH
sudo ufw enable

# Target VM
sudo ufw allow OpenSSH
# No need to open 80/8787 — Portal + Agent are loopback-only
sudo ufw enable
```

## Monitoring

- Server logs: `/var/log/apache2/ctf-server-{access,error}.log` + `storage/logs/ctf-server.log`
- Target Agent logs: `journalctl -u ctf-agent.service -f`
- Target Portal logs: `/var/log/apache2/ctf-portal-{access,error}.log`

## Security review before going live

Run `php ctf-server/tests/security_checklist.php` on the Server. All 15
invariants should pass. The script greps for known violations.

## Recovery

- DB: restore from `mysqldump` backup.
- Code: re-clone from git; the install scripts are idempotent.
- `FLAG_MASTER_SECRET` rotation: invalidate every active flag — cancel all active tasks, force students to re-issue.
