#!/usr/bin/env bash
# target/install.sh — Provision a Target VM with the CTF Agent.
#
# Run as root on Ubuntu 24.04 LTS. Idempotent — re-running on an already
# provisioned VM is safe and will only refresh config / restart services.
#
# Layout produced:
#   /opt/ctf-agent/                  — Python source tree
#   /usr/local/bin/ctf-agent         — CLI entry
#   /usr/local/bin/ctf-agent-serve   — local API entry (systemd)
#   /etc/ctf-agent/config.json       — agent config (server_url, paths)
#   /var/lib/ctf-agent/              — device.json + cache (mode 0700)
#   /srv/ctf/challenges/             — installed challenge roots
#   /var/log/ctf-agent/              — agent.log
#   /etc/systemd/system/ctf-agent.service
#   /etc/systemd/system/ctf-agent.timer
#   /etc/systemd/system/ctf-agent-sync.service

set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ERROR: must run as root" >&2
  exit 1
fi

AGENT_SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/agent" && pwd)"
OPT_DIR="/opt/ctf-agent"
ETC_DIR="/etc/ctf-agent"
LIB_DIR="/var/lib/ctf-agent"
CHALLENGE_ROOT="/srv/ctf/challenges"
LOG_DIR="/var/log/ctf-agent"

# 1. System packages
apt-get update -qq
apt-get install -y -qq python3 python3-venv python3-pip mariadb-client

# 2. Copy source
install -d "$OPT_DIR"
cp -r "$AGENT_SRC_DIR/src" "$OPT_DIR/src"
cp "$AGENT_SRC_DIR/requirements.txt" "$OPT_DIR/requirements.txt"
chmod -R a+rX "$OPT_DIR"

# 3. Python deps in a venv
# Remove existing venv to ensure clean install
if [[ -d "$OPT_DIR/venv" ]]; then
    rm -rf "$OPT_DIR/venv"
fi
python3 -m venv "$OPT_DIR/venv"
"$OPT_DIR/venv/bin/pip" install --upgrade pip -q
"$OPT_DIR/venv/bin/pip" install -r "$OPT_DIR/requirements.txt" -q

# 4. CLI + service launchers
install -d /usr/local/bin
cat > /usr/local/bin/ctf-agent <<EOF
#!/usr/bin/env bash
cd "$OPT_DIR"
exec "$OPT_DIR/venv/bin/python" -m src.cli "\$@"
EOF
chmod 0755 /usr/local/bin/ctf-agent

cat > /usr/local/bin/ctf-agent-serve <<EOF
#!/usr/bin/env bash
cd "$OPT_DIR"
exec "$OPT_DIR/venv/bin/python" -m src.local_api
EOF
chmod 0755 /usr/local/bin/ctf-agent-serve

# 5. Config dir + default config
install -d -m 0755 "$ETC_DIR" "$LIB_DIR" "$CHALLENGE_ROOT" "$LOG_DIR"
if [[ ! -f "$ETC_DIR/config.json" ]]; then
  cat > "$ETC_DIR/config.json" <<CFG
{
  "server_url": "https://ctf.lab.local",
  "agent_version": "0.1.0",
  "target_version": "ubuntu-24.04",
  "request_timeout_sec": 30,
  "sync_interval_min": 5
}
CFG
  chmod 0644 "$ETC_DIR/config.json"
fi
chmod 0700 "$LIB_DIR"

# 6. MariaDB: create the Agent user with limited privs
# Check if the user exists for ANY host pattern to determine if we need to create or alter
AGENT_EXISTS=$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='ctf_agent'" 2>/dev/null || echo "0")
if [[ "$AGENT_EXISTS" -eq "0" ]]; then
  AGENT_DB_PASS="$(openssl rand -hex 16)"
  mysql <<SQL
CREATE USER 'ctf_agent'@'127.0.0.1' IDENTIFIED BY '$AGENT_DB_PASS';
GRANT CREATE, DROP, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE
  ON \`ctf_target\`.* TO 'ctf_agent'@'127.0.0.1';
GRANT CREATE, DROP, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE
  ON \`ctf_%\`.* TO 'ctf_agent'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  echo "Created MariaDB user 'ctf_agent'@'127.0.0.1'"
else
  # User exists — drop and recreate to ensure clean state and new password
  AGENT_DB_PASS="$(openssl rand -hex 16)"
  mysql <<SQL
DROP USER IF EXISTS 'ctf_agent'@'127.0.0.1';
DROP USER IF EXISTS 'ctf_agent'@'%';
CREATE USER 'ctf_agent'@'127.0.0.1' IDENTIFIED BY '$AGENT_DB_PASS';
GRANT CREATE, DROP, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE
  ON \`ctf_target\`.* TO 'ctf_agent'@'127.0.0.1';
GRANT CREATE, DROP, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE
  ON \`ctf_%\`.* TO 'ctf_agent'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  echo "Reset MariaDB user 'ctf_agent' credentials"
fi
# Always save password for the Agent to read.
install -m 0644 /dev/null "$ETC_DIR/agent_db.json"
cat > "$ETC_DIR/agent_db.json" <<DB
{
  "host": "127.0.0.1",
  "port": 3306,
  "user": "ctf_agent",
  "password": "$AGENT_DB_PASS"
}
DB
chmod 0644 "$ETC_DIR/agent_db.json"
echo "Saved DB credential to $ETC_DIR/agent_db.json"

# 7. Systemd units
install -m 0644 "$AGENT_SRC_DIR/systemd/ctf-agent.service" /etc/systemd/system/ctf-agent.service
install -m 0644 "$AGENT_SRC_DIR/systemd/ctf-agent-sync.service" /etc/systemd/system/ctf-agent-sync.service
install -m 0644 "$AGENT_SRC_DIR/systemd/ctf-agent.timer" /etc/systemd/system/ctf-agent.timer
systemctl daemon-reload
systemctl enable --now ctf-agent.service
systemctl enable --now ctf-agent.timer

echo "CTF Target Agent installed."
echo "Next: edit $ETC_DIR/config.json (set server_url), then run:"
echo "  ctf-agent activate"
echo "  ctf-agent doctor"
