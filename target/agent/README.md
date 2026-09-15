# CTF Target Agent

Python service running on each student Target VM. Bridges between the Target
Portal (PHP on the same VM) and the central CTF Server.

## What it does

- **Activation**: registers this VM with the CTF Server using a one-time
  activation code from the student.
- **Sync**: every 5 minutes (systemd timer), pulls the list of challenges the
  device should have, downloads new/updated ZIPs, installs them locally.
- **Local API** (`127.0.0.1:8787`): serves the Target Portal — status,
  activate, sync, task, reset, heartbeat.
- **Reset**: drops and recreates a challenge's DB and files when the student
  wants to retry.

## Layout

```
target/agent/
├── src/                  # Python source
│   ├── config.py         — JSON config loader
│   ├── credential.py     — device.json read/write (0600 on Linux)
│   ├── server_api.py     — HTTPS client to CTF Server
│   ├── local_db.py       — MariaDB helpers for ctf_target + ctf_<id>
│   ├── zip_safe.py       — safe ZIP extraction (Zip Slip prevention)
│   ├── manifest.py       — manifest.json parser + schema
│   ├── installer.py      — download → sha256 → unzip → setup.sql
│   ├── resetter.py       — drop DB + restore files
│   ├── syncer.py         — periodic pull from Server
│   ├── local_api.py      — Flask app, bind 127.0.0.1:8787
│   └── cli.py            — `ctf-agent` CLI entry
├── systemd/              # unit files (installed by install.sh)
├── tests/                # pytest
├── requirements.txt
└── README.md
```

## Install on Ubuntu 24.04

```bash
sudo ./target/install.sh
```

That script:
1. Installs `python3-venv` + `mariadb-client`.
2. Copies source to `/opt/ctf-agent`.
3. Creates a venv + installs dependencies.
4. Drops `/usr/local/bin/ctf-agent` and `/usr/local/bin/ctf-agent-serve`.
5. Creates `/etc/ctf-agent/config.json` (server_url placeholder).
6. Creates the limited `ctf_agent`@`127.0.0.1` MariaDB user.
7. Installs + enables `ctf-agent.service` and `ctf-agent.timer`.

## Activate + use

```bash
# 1) Edit /etc/ctf-agent/config.json — set server_url.
sudo vi /etc/ctf-agent/config.json
sudo systemctl restart ctf-agent.service

# 2) Self-check.
sudo ctf-agent doctor

# 3) Activate. Paste the code from the student's CTF Server dashboard.
sudo ctf-agent activate

# 4) Manual sync.
sudo ctf-agent sync

# 5) See local state.
ctf-agent status
ctf-agent list

# 6) Reset a challenge.
sudo ctf-agent reset DEMO-001
```

## Trust boundary

- Agent binds `127.0.0.1:8787` only — never `0.0.0.0`. The Portal is the
  only local caller.
- Plain `device_token` is stored at `/var/lib/ctf-agent/device.json` with
  mode `0600` (root-only).
- `FLAG_MASTER_SECRET` never lives on the Target VM. Server-only.

## Testing

On any platform (Windows / Linux / macOS):

```bash
cd target/agent
python3 -m pytest tests/ -v
```

## What is NOT yet implemented

These are tracked in WORKPLAN.md §5:

- UI: Portal's activate / sync / task / reset pages (separate repo: `target/portal`)
- Server-side endpoints that Agent calls but Server doesn't expose yet:
  - `GET /api/v1/device/challenges` (list)
  - `GET /api/v1/device/challenges/{id}/download`
  - `POST /api/v1/device/task/validate`
  - `POST /api/v1/device/task/complete`
  These will be implemented in WORKPLAN §6 / Phase 5.
