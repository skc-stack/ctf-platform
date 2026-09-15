# CTF LAB — Security Model

This document explains the **trust boundary** design of the CTF LAB
platform and the rationale behind every decision. It maps directly to
the 15 invariants in `CLAUDE.md` §5 and is verified by
`ctf-server/tests/security_checklist.php`.

## Threat model

The platform has two physically separate deploy units:

```
┌──────────────────────────┐          ┌──────────────────────────────┐
│       CTF Server          │          │       Target VM (per student) │
│                          │          │                                │
│  - User accounts          │   HTTPS  │  - Python Agent (127.0.0.1)   │
│  - Challenge catalog      │ ◀──────▶ │  - PHP Portal (127.0.0.1)      │
│  - Tasks, solves, scores  │  Bearer  │  - Challenge web/ + DB        │
│  - FLAG_MASTER_SECRET     │  Token   │                                │
└──────────────────────────┘          └──────────────────────────────┘
        (trusted)                              (untrusted)
```

A student's Target VM is **untrusted** — the student has root on it and
can read any file. The CTF Server is the only authority for: who is a
student, what is a challenge, what's the score, what flag is correct.

## 15 invariants

| # | Invariant | Where enforced |
|---|-----------|----------------|
| A1 | CTF Server 與 Target VM 為兩個獨立部署單元 | Repo layout + install scripts |
| A2 | Target 不直接連 Server MariaDB | Agent only uses 127.0.0.1 + `ctf_agent`@`127.0.0.1` user |
| A3 | Server 不 require Target source | Repo layout |
| A4 | Target 不 require Server source | Repo layout |
| A5 | Target 不含 `FLAG_MASTER_SECRET` | Env var only on Server, never in any target/* file |
| A6 | Score / Solve 只能由 CTF Server 決定 | `SubmissionService::verifyAndAward()` ignores `$_POST['points']` |
| A7 | Task Token ≠ Flag | Token = base32 random; Flag = HMAC(student, challenge, task, MASTER) |
| A8 | Device Token ≠ Student password | Device token = `random_bytes(32)`; password = `PASSWORD_ARGON2ID` |
| A9 | Challenge Package 必須 versioned | `manifest.json` schema requires `"version": N` |
| A10 | ZIP 必須驗證 hash + extraction path | Server `ZipValidator.php` + Agent `zip_safe.py` |
| A11 | Target DB 與 Server DB 分離 | Agent uses `ctf_target` + `ctf_<id>`; Server uses `ctf_server` |
| A12 | Challenge DB 與 Target 管理 DB 分離 | Per-challenge `ctf_<id>` DB created at install time |
| A13 | Portal 禁用 system operation 函式 | Apache `php_admin_value disable_functions` + grep guard in tests |
| A14 | 瀏覽器 state-changing 必須 CSRF | `CSRF::class` on every non-API POST route |
| A15 | Device API 使用 Bearer Token | `Authorization: Bearer <token>` parsed by `DeviceAuth` middleware |

## Defense in depth

Each invariant has **multiple layers** of enforcement:

### A5: FLAG_MASTER_SECRET never on Target

1. **Env var location**: only set in `ctf-server/.env`, not in any `target/` file.
2. **Code grep**: `tests/security_checklist.php` greps all `target/**/*.{php,py,sh,conf}` for the literal `FLAG_MASTER_SECRET` and fails on any hit.
3. **README documentation**: `target/agent/README.md` and `target/portal/README.md` both explicitly say the secret never crosses that boundary.
4. **Network boundary**: even if a Target VM were compromised, the only HTTP path to Server uses Bearer tokens — there is no API endpoint that accepts FLAG_MASTER_SECRET as input.

### A13: Portal can't run shell commands

1. **Apache config**: `php_admin_value disable_functions "exec,passthru,popen,proc_open,shell_exec,system"`.
2. **open_basedir**: Portal can only read `/var/www/ctf-target-portal:/tmp`.
3. **Code grep**: `tests/test_static.py::test_no_banned_function_calls_in_portal_source` fails on any call site.
4. **Loopback bind**: Portal only listens on `127.0.0.1:80` — even if a web vuln is found, no external attacker can reach it.

### A6: Score is Server's decision

1. **SubmissionService** reads `points` from `$challenge['points']` in the DB, never from `$_POST`.
2. **Device API** for automatic verification rejects `student_id` and `points` parameters — Server computes everything from task metadata.
3. **Solves table** has `UNIQUE (student_id, challenge_id)` — race conditions can't double-award.
4. **Audit log** records every submission (correct or wrong) with the expected vs submitted hash.

### A14: CSRF on browser POSTs

1. **CSRF middleware** is required on every browser POST in `routes/web.php`. The security checklist greps to confirm.
2. **Device API routes** are exempt because they use Bearer auth instead — CSRF doesn't apply to header-based auth.
3. **SameSite=Lax** cookie + `HttpOnly` session prevents JS-side CSRF on top of the token check.

## What an attacker who compromises a Target VM can do

- ✅ Read their own device.json (their own token).
- ✅ Submit flags they've obtained (legitimately or by attacking their own challenge).
- ✅ Spam the Agent's `/task` endpoint (rate-limited to 10/min).
- ❌ Steal other students' device tokens.
- ❌ Read other students' flags (HMAC keyed by student_id).
- ❌ Modify their own score (Server recomputes expected flag from task_uuid).
- ❌ Run arbitrary commands on the Portal (disabled_functions + open_basedir).
- ❌ Reach the Server's MariaDB (different DB, separate credentials, no direct network path).

## What an attacker who compromises the Server can do

Everything. The Server is the trust root. Mitigations:

- `.env` is mode `0600`, owned by the app user.
- Database credentials are scoped: `ctf_server_app` has only DML on `ctf_server` (no DROP, no GRANT).
- All write actions are audit-logged.
- Rate limits make brute-force impossible at scale.

## Operational notes

- **Rotating FLAG_MASTER_SECRET**: invalidate every issued flag at once. Plan a maintenance window where all active tasks are cancelled and re-issued.
- **Rotating DB credentials**: rotate `ctf_server_app` password in MariaDB, update `.env`, restart Apache.
- **Audit log retention**: `bin/cleanup.php` deletes used email/password tokens after 7 days. Audit log rows are kept indefinitely (operator decision).
- **Backups**: take daily `mysqldump` of `ctf_server`; back up `storage/challenges/` (the ZIPs).

## Verification

Run `php ctf-server/tests/security_checklist.php` to verify all 15
invariants are still holding. CI should run this on every PR.
