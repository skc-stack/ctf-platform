# CTF LAB — REST API Reference

All endpoints are mounted under `/api/v1/`. JSON in, JSON out.
Authentication: see each section.

## Conventions

- All `POST`/`PUT`/`DELETE` browser endpoints require:
  - `Cookie: ctf_session=...` (session-based)
  - `_csrf=<token>` in form body (double-submit)
- All `/api/v1/device/**` endpoints use:
  - `Authorization: Bearer <device_token>`
  - `X-Device-ID: <device_uuid>`
- Errors use the shape `{"success": false, "error": "<msg>", "code": "<stable_code>"}`.
- Rate limit returns 429 with `{"error": "Too many requests"}`.

## Public

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET    | `/health` | none | health check |
| GET    | `/captcha` | none | CAPTCHA image (returns `X-Captcha-Debug: <answer>` only in dev) |
| GET    | `/leaderboard` | none | top students by score |

## Auth (browser)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET    | `/login` | guest | login form |
| POST   | `/login` | guest | submit credentials + CAPTCHA |
| POST   | `/logout` | session | destroy session |
| GET    | `/register` | guest | registration form (student / teacher tabs) |
| POST   | `/register` | guest | create account |
| GET    | `/verify-email?token=...` | none | consume email verification token |
| GET    | `/password/reset` | none | request reset form |
| POST   | `/password/reset` | none | submit identifier (username/email) |
| GET    | `/password/reset/confirm?token=...` | none | confirm form |
| POST   | `/password/reset/confirm` | none | submit new password |

## Student (browser, role=student)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET    | `/student` | session | dashboard (score / rank / challenges / active tasks) |
| GET    | `/student/groups` | session | my groups |
| GET    | `/student/groups/join` | session | join form |
| POST   | `/student/groups/join` | session | submit join_code |
| POST   | `/student/groups/{id}/leave` | session | leave group |
| POST   | `/api/v1/student/task/start` | session | start a task, returns task_uuid |
| GET    | `/student/task/{id}` | session | task detail page |
| POST   | `/student/task/{id}/cancel` | session | cancel task |
| POST   | `/api/v1/student/submit` | session | submit flag |

### `POST /api/v1/student/task/start`

Form body: `challenge_id=N` + `_csrf=...`

Response: `302 → /student/task/{id}` with flash message.

### `POST /api/v1/student/submit`

Form body: `task_id=N`, `flag=flag{...}` + `_csrf=...`

Response: `302 → /student/task/{id}` with success or failure flash.

## Teacher (browser, role=teacher)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET    | `/teacher` | session | dashboard (challenge counts by status) |
| GET    | `/teacher/groups` | session | my groups |
| GET    | `/teacher/groups/new` | session | create form |
| POST   | `/teacher/groups` | session | submit (name, description, max_members) |
| GET    | `/teacher/groups/{id}` | session | group detail (members, join_code, regenerate, delete) |
| POST   | `/teacher/groups/{id}/regenerate-code` | session | new join_code |
| POST   | `/teacher/groups/{id}/remove/{userId}` | session | kick student |
| POST   | `/teacher/groups/{id}/delete` | session | delete group |

> Note: Teacher challenge upload UI (§2.4) is not yet implemented. Use
> `bin/seed-challenge.php` CLI as a stand-in for the MVP (§9).

## Admin (browser, role=admin)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET    | `/admin` | session | dashboard |
| GET    | `/admin/users` | session | pending + disabled list |
| POST   | `/admin/users/{id}/approve` | session | approve teacher |
| POST   | `/admin/users/{id}/disable` | session | disable user |

## Device API (`/api/v1/device/**`)

All require `Authorization: Bearer <device_token>` + `X-Device-ID: <device_uuid>`.

| Method | Path | Rate | Purpose |
|--------|------|------|---------|
| POST   | `/api/v1/device/activate` | 5/min (IP) | exchange activation_code → device_token |
| GET    | `/api/v1/device/info` | — | device status + owner |
| POST   | `/api/v1/device/heartbeat` | — | update last_seen_at |
| GET    | `/api/v1/device/challenges` | — | list of challenges for this device (Phase 5+) |
| GET    | `/api/v1/device/challenges/{id}/download` | — | ZIP download (Phase 5+) |
| POST   | `/api/v1/device/task/validate` | 10/min | bind device to task, return entrypoint |
| POST   | `/api/v1/device/task/complete` | 10/min | report automatic verification result |

### `POST /api/v1/device/activate`

Request body (JSON or form):
```json
{
  "activation_code": "ACT-XXXX-XXXX-XXXX",
  "device_uuid": "00000000-0000-0000-0000-000000000000",
  "device_name": "ubuntu-target"
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "device_id": 42,
    "device_uuid": "<server-assigned>",
    "device_token": "<plain-text, only shown once>"
  }
}
```

### `POST /api/v1/device/task/validate`

Request body:
```json
{"task_token": "TASK-XXXX-XXXX-XXXX-XXXX"}
```

Response 200 (success):
```json
{
  "success": true,
  "data": {
    "task_id": 1,
    "task_uuid": "...",
    "challenge_id": 5,
    "challenge_uuid": "...",
    "entrypoint": "/challenge/DEMO-001/",
    "challenge_version": 1,
    "expires_at": "2026-09-14 20:00:00"
  }
}
```

Error codes (in `body.code`):
- `invalid_token` (400) — bad format
- `unknown_token` (400) — token hash not in DB
- `inactive` (400) — task not active
- `expired` (400) — `expires_at < NOW()`
- `device_inactive` (401) — device revoked
- `owner_mismatch` (403) — device's user != task's student
- `device_mismatch` (403) — task already bound to another device
- `challenge_unavailable` (410) — challenge unpublished

### `POST /api/v1/device/task/complete`

Request body:
```json
{
  "task_id": 1,
  "flag": "flag{64-hex-chars}",
  "nonce": "random-string-8-to-128-chars"
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "correct": true,
    "reason": "correct",
    "points_awarded": 200,
    "total_score": 200,
    "new_solve": true
  }
}
```

Error codes:
- `nonce_replayed` (409) — same nonce used before
- `not_owner` (403) — device.user_id != task.student_id
- `device_mismatch` (403) — task bound to a different device
- `flag_mismatch` (400) — HMAC compare failed

## Internal (CLI)

| Script | Purpose |
|--------|---------|
| `bin/migrate.php` | run all migrations in `database/migrations/` |
| `bin/seed-challenge.php` | publish a challenge ZIP without the upload UI |
| `bin/create-admin.php` | interactive admin creation |
| `bin/cleanup.php` | daily cron: drop expired tasks/tokens, clear rate-limit buckets |

## Error codes — full table

| HTTP | Code | Meaning |
|------|------|---------|
| 400 | invalid_token / unknown_token / inactive / expired / flag_mismatch / challenge_unavailable / invalid | generic 400 |
| 401 | (no code) | unauthorized (no session / Bearer) |
| 403 | csrf_mismatch / device_mismatch / owner_mismatch | forbidden |
| 403 | unauth_role | role middleware rejected |
| 409 | nonce_replayed | replay attempt |
| 410 | challenge_unavailable | challenge unpublished |
| 429 | (no code) | rate limited |
| 500 | (none) | server crash (logged) |

## Audit log events

Every state-changing action writes to `audit_logs`:

- `login`, `login_failed`, `login_locked`, `logout`
- `register_student`, `register_teacher`
- `email_verified`
- `teacher_approved`, `user_disabled`
- `password_reset_request`, `password_reset`
- `group_create`, `group_delete`, `group_join`, `group_leave`, `group_remove`, `group_regenerate_code`
- `device_activate`, `device_revoke`
- `task_start`, `task_validate`, `task_cancel`, `task_complete`
- `flag_submit`
- `challenge_create`
