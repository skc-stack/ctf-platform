# DEMO-001 — Hidden in HTML Comments

A trivial intro challenge. The student opens the challenge entrypoint
(`/challenge/DEMO-001/`), views page source, and submits the flag the
Server computes for them.

## Layout

```
DEMO-001/
├── manifest.json   — required: challenge metadata + verification spec
├── web/
│   └── index.php    — the page students actually visit
└── setup.sql        — runs on Target VM when installed
```

## What it teaches

- How to read HTML source for clues.
- The full task lifecycle: dashboard → token → Portal → entrypoint → flag.
- That **static flags in ZIPs are not what gets accepted** — the Server
  wraps the verification in HMAC(student, challenge, task, MASTER).
- How `bin/seed-challenge.php` (in ctf-server/) lets you publish a ZIP
  without needing the upload UI (which is part of Phase 2 / §2.4).

## Teacher: how to publish

```bash
# 1. Build the ZIP (from the challenge-example/ dir)
bash build.sh

# 2. Use the seed CLI to insert into the Server DB + write the manifest.
# (The CLI is a stand-in for the full upload UI from §2.4.)
cd ../ctf-server
php bin/seed-challenge.php \
    --teacher=<teacher-username> \
    --zip=../challenge-example/dist/DEMO-001.zip
```

## Student: how to solve

1. Log in, go to Dashboard, click **啟動 Task** on DEMO-001.
2. Copy the Task Token from the task page.
3. Open Target Portal at `http://127.0.0.1/`, paste the token → **驗證並開啟挑戰**.
4. Click the entrypoint link. View source (`Ctrl+U`).
5. Go back to the task page on the Server, paste the flag into the submit form.
6. +200 points, leaderboard updated.

> **Note:** the flag the Server accepts is NOT the static `flag{read_the_source}`
> value in `manifest.json`. The Server computes a per-task flag via
> `HMAC-SHA256(student_id + challenge_uuid + task_uuid, FLAG_MASTER_SECRET)`.
> For this demo, with `FLAG_MASTER_SECRET=da674e0bcf928531` (the default
> dev value), the student must run the Server's flow to learn their flag —
> or compute it locally with the same formula.

## Local flag computation (for testing)

```php
$flag = 'flag{' . hash_hmac('sha256',
    $studentId . ':' . $challengeUuid . ':' . $taskUuid,
    'da674e0bcf928531'
) . '}';
```
