# CTF LAB — Distributed Cybersecurity Training Platform

A monorepo for a Capture-The-Flag (CTF) cybersecurity training platform
designed for classroom use. Two physically separate deployment units:

1. **CTF Server** (`ctf-server/`) — the trusted control plane (PHP 8.3 +
   MariaDB). Holds user accounts, challenge catalog, scoring, and the
   master flag secret.
2. **Target VM** (`target/`) — one VM per student (Python Agent + PHP
   Portal). Each VM hosts challenges locally; the Agent talks to the
   Server over HTTPS, the Portal talks to the Agent over loopback.

**Status**: §0–§10 MVP complete. See `WORKPLAN.md` for full breakdown.

## Quick start (development on Windows)

```bash
# 1. Install dependencies
composer install --working-dir=ctf-server
pip install -r target/agent/requirements.txt
pip install -r - <<EOF
pytest
EOF

# 2. Initialize DB
cd ctf-server
php bin/migrate.php
php bin/create-admin.php   # interactive

# 3. Publish the demo challenge
cd ..
python3 challenge-example/build.py
cd ctf-server
php bin/seed-challenge.php --teacher=admin --zip=../challenge-example/dist/DEMO-001.zip

# 4. Run all e2e tests
for t in phase1 password_reset login_security groups devices tasks flags mvp; do
  php tests/e2e_$t.php
done
cd ../target/agent && pytest tests/
cd ../target/portal && pytest tests/

# 5. Verify the 15 security invariants
cd ../../ctf-server
php tests/security_checklist.php
```

For the demo user journey, see `challenge-example/DEMO-001/README.md`.

## Repository layout

```
.
├── README.md                   (this file)
├── WORKPLAN.md                 (full progress + design)
├── CLAUDE.md                   (project instructions + 15 invariants)
├── docs/
│   ├── API.md                  (REST API reference)
│   ├── DEPLOYMENT.md           (Ubuntu 24.04 deploy guide)
│   └── SECURITY.md             (trust model + invariants)
├── ctf-server/                 (PHP — Control Plane)
│   ├── README.md
│   ├── public/                 (Apache DocumentRoot)
│   ├── src/                    (Controllers / Services / Repositories / Security)
│   ├── views/                  (PHP templates)
│   ├── routes/web.php          (all routes)
│   ├── bin/                    (CLI: migrate, create-admin, seed-challenge, cleanup)
│   ├── database/migrations/    (4 .sql files, idempotent)
│   ├── storage/                (logs / challenges / .env)
│   └── tests/                  (8 e2e scripts + security_checklist.php)
├── target/
│   ├── agent/                  (Python — runs on Target VM)
│   │   ├── src/                (config, credential, zip_safe, manifest, ...)
│   │   ├── systemd/            (ctf-agent.service + .timer)
│   │   └── tests/              (60 pytest cases)
│   ├── portal/                 (PHP — runs on Target VM, loopback-only)
│   │   ├── public/             (Apache DocumentRoot on 127.0.0.1)
│   │   ├── src/                (Router, AgentClient, PortalController)
│   │   ├── views/
│   │   ├── apache/ctf-portal.conf
│   │   └── tests/              (18 pytest cases — 8 static + 10 e2e)
│   ├── install.sh              (installs Agent + creates systemd + MariaDB user)
│   ├── install-portal.sh       (installs Portal + Apache vhost)
│   └── database/target_database.sql  (run on each Target VM)
└── challenge-example/          (DEMO-001 + build.py)
```

## Roles

- **admin**: approve teachers, manage users, view audit logs.
- **teacher**: create groups, build challenges (upload ZIP), publish, view student progress.
- **student**: join groups (via join_code), start tasks, paste Task Token to Target Portal, submit flags, climb the leaderboard.

## Key design decisions

- **Groups instead of student↔teacher links** (added in §3): teachers
  create groups; students join via `join_code`. A challenge is either
  public, or bound to specific groups → only those students see it.
- **Dynamic flags via HMAC**: each task's expected flag is
  `HMAC-SHA256(student_id + challenge_uuid + task_uuid, FLAG_MASTER_SECRET)`.
  Static flags in ZIPs are red herrings.
- **Loopback-only Portal**: Target Portal binds `127.0.0.1:80`. Even if
  a web vuln exists, no external attacker can reach it.
- **Disabled functions on Portal**: `shell_exec`/`system`/`exec`/etc.
  blocked in Apache config + `open_basedir` + grep test guard.
- **No FLAG_MASTER_SECRET on Target**: only on Server, only via env var,
  never in any file under `target/`. Verified by `security_checklist.php`.

See `docs/SECURITY.md` for the full 15-invariant model.

## Tests

| Layer | Count | Run with |
|-------|-------|----------|
| Server e2e (PHP) | 8 scripts, ~70 steps | `for t in phase1 password_reset login_security groups devices tasks flags mvp; do php tests/e2e_$t.php; done` |
| Server security checklist | 16 checks | `php tests/security_checklist.php` |
| Agent pytest | 60 (1 POSIX-only skipped on Windows) | `cd target/agent && pytest tests/` |
| Portal pytest | 18 (8 static + 10 e2e) | `cd target/portal && pytest tests/` |

CI should run all four suites on every PR. All currently PASS.

## Documentation

- `docs/API.md` — every REST endpoint
- `docs/DEPLOYMENT.md` — Ubuntu 24.04 install
- `docs/SECURITY.md` — trust boundary + 15 invariants
- `WORKPLAN.md` — design + progress
- `CLAUDE.md` — project conventions
- `challenge-example/DEMO-001/README.md` — student + teacher flow

## License

Internal classroom tool. Not for redistribution.
