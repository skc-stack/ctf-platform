# CTF Server SPEC

## 1. 部署邊界

本文件只描述中央 CTF Server。

此程式碼必須位於：

```text
ctf-server/
```

只部署到中央 Server。

不得依賴：

```text
target/agent/
target/portal/
```

不得 require/include Target source。

Server 專屬資料：

```text
database/server_database.sql
scripts/server/
```

部署路徑：

```text
/var/www/ctf-server/
/var/lib/ctf-server/
/var/log/ctf-server/
```

CTF Server 不需要安裝 Target Agent、Target Portal 或 Challenge runtime。

---

## 2. 系統角色

### Admin
- 管理帳號
- 核准 Teacher
- Disable user
- 管理 Challenge
- 管理 Device
- 管理設定
- Audit Log

### Teacher
- 建立 Challenge
- 修改自己的 Challenge
- 上傳 ZIP
- Publish / Disable
- 查看學生完成狀況
- Leaderboard

Teacher 預設 `pending`。

### Student
- 註冊登入
- 查看 Challenge
- Start Challenge
- 取得 Task Token
- 產生 Activation Code
- 管理 Device
- Submit Flag
- 查看 Score / Leaderboard

---

## 3. 技術

- Ubuntu Server 24.04 LTS
- Apache 2.4
- PHP 8.3+
- MariaDB 10.11+
- Native PHP
- PDO
- Bootstrap 5
- PHP Session

禁止 PHP Framework。

---

## 4. Server 專案目錄

```text
ctf-server/
├── public/
├── src/
│   ├── Controllers/
│   ├── Models/
│   ├── Repositories/
│   ├── Services/
│   ├── Middleware/
│   ├── Security/
│   ├── Database/
│   ├── Http/
│   └── Support/
├── views/
├── routes/
├── config/
├── database/
├── storage/
├── bin/
├── tests/
├── bootstrap.php
├── composer.json
├── install.sh
└── README.md
```

---

## 5. 使用者欄位

`users`：

- id
- username
- email
- password_hash
- display_name
- role
- status
- last_login_at
- timestamps

**Note**: 早期版本曾有 `student_number` / `class_name` / `school_name` 三個欄位，已於 §3.0 移除。
學生 ↔ 老師的關係透過群組（§6）管理，不在 users 表存 institution 資訊。

role：

```text
admin
teacher
student
```

status：

```text
pending
active
disabled
```

---

## 6. 群組（Groups）

學生 ↔ 老師的關係**透過群組**（teacher 建立群組、學生以 `join_code` 加入），不再有直接的 student↔teacher 連結。

### 6.1 設計決策

| 決策 | 決定 |
|---|---|
| 一個老師可有多個群組？ | 是（不同班、不同主題分開） |
| 一個學生可加入多個群組？ | 是（跨班、跨課程） |
| 加入方式 | join_code（8 字元英數隨機）+ 直接 URL `/student/groups/join?code=ABC123` |
| 學生能否主動離開？ | 是（`POST /student/groups/{id}/leave`） |
| 老師能否踢學生？ | 是（`POST /teacher/groups/{id}/remove/{userId}`） |
| join_code 可重用？ | 是（除非老師按 regenerate-code 換新） |
| 一群組可綁定多個 Challenge？ | 是（`challenge_groups` 多對多表） |
| 沒加入任何群組的學生 | 看不到任何老師發布的 challenge（公開題除外） |

### 6.2 `groups` 表

```text
id
uuid
teacher_id         (FK users.id)
name               (VARCHAR 100)
description        (TEXT, nullable)
join_code          (CHAR 8, UNIQUE) — random base32 without 0/O/1/I/L
max_members        (INT UNSIGNED, nullable — NULL 表示無限)
status             (active / archived)
timestamps
```

Indexes：`(teacher_id, status)`、`(status, created_at)`、`UNIQUE(uuid)`、`UNIQUE(join_code)`

### 6.3 `group_members` 表

```text
id
group_id           (FK groups.id, CASCADE)
student_id         (FK users.id, CASCADE)
status             (active / left / banned)
joined_at
left_at            (nullable)
timestamps
```

Indexes：`(group_id, student_id)` UNIQUE、`(student_id, status)`、`(group_id, status)`

`status=left/banned` 保留 audit trail，不真刪除。

### 6.4 join_code 規則

- 8 字元，字符集 `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`（與 CAPTCHA 同集，避免易混淆字元）
- 產生後查 DB，若碰撞重試（最多 5 次）

### 6.5 Routes

| Method | Path | Who |
|---|---|---|
| GET | `/teacher/groups` | 老師 | 列出我建立的群組 |
| GET | `/teacher/groups/new` | 老師 | 建立表單 |
| POST | `/teacher/groups` | 老師 | 處理建立 |
| GET | `/teacher/groups/{id}` | 老師 | 群組詳情 + 成員 |
| POST | `/teacher/groups/{id}/regenerate-code` | 老師 | 換新 join_code |
| POST | `/teacher/groups/{id}/remove/{userId}` | 老師 | 踢出學生 |
| POST | `/teacher/groups/{id}/delete` | 老師 | 刪除群組（CASCADE 刪成員） |
| GET | `/student/groups` | 學生 | 我加入的群組 |
| GET | `/student/groups/join` | 學生 | 加入表單 |
| POST | `/student/groups/join` | 學生 | 以 code 加入 |
| POST | `/student/groups/{id}/leave` | 學生 | 離開 |

### 6.6 Audit Log

`group_create` / `group_delete` / `group_join` / `group_leave` / `group_remove` / `group_regenerate_code`

### 6.7 與 Challenge 的銜接

`challenge_groups` 多對多表：`(challenge_id, group_id)`。若 challenge 沒綁定任何 group → 公開題；若綁定 → 只在這些 group 內的學生可見。詳細在 §8.1 說明。

---

## 7. Device Activation

學生可產生 Activation Code。

預設：

- 10 minutes
- single-use

API：

```http
POST /api/v1/device/activate
```

Request：

```json
{
  "activation_code": "ACT-...",
  "device_uuid": "...",
  "device_name": "Ubuntu Target"
}
```

Response：

```json
{
  "success": true,
  "data": {
    "device_id": "...",
    "device_token": "..."
  }
}
```

Server 只保存 `SHA-256(device_token)`。

---

## 8. Device API

```text
POST /api/v1/device/activate
GET  /api/v1/device/info
POST /api/v1/device/heartbeat
GET  /api/v1/device/challenges
GET  /api/v1/device/challenges/{id}/download
POST /api/v1/device/task/validate
POST /api/v1/device/task/complete
```

Authentication：

```http
Authorization: Bearer DEVICE_TOKEN
X-Device-ID: DEVICE_UUID
```

---

## 9. Challenge

欄位：

- id
- uuid
- teacher_id
- title
- slug
- category
- difficulty
- description
- points
- version
- status
- verification_type
- timestamps

category：

```text
web
crypto
reverse
pwn
forensic
misc
network
```

difficulty：

```text
easy
medium
hard
expert
```

status：

```text
draft
published
disabled
```

verification：

```text
flag
automatic
```

---

## 10. Challenge Package

儲存：

```text
/var/lib/ctf-server/challenges/{challenge_uuid}/{version}/challenge.zip
```

不得放 public webroot。

保存：

- file_path
- version
- file_size
- sha256
- manifest_json

---

## 11. Teacher API

```text
POST /api/v1/teacher/challenges
PUT  /api/v1/teacher/challenges/{id}
POST /api/v1/teacher/challenges/{id}/package
POST /api/v1/teacher/challenges/{id}/publish
POST /api/v1/teacher/challenges/{id}/disable
```

---

## 12. ZIP Validation

必須：

- size <= 100 MB
- extension / MIME validation
- manifest exists
- schema valid
- challenge_id match
- version valid
- Zip Slip prevention
- SHA-256

---

## 13. Task Session

欄位：

- uuid
- student_id
- device_id
- challenge_id
- token_hash
- status
- started_at
- expires_at
- completed_at

status：

```text
active
completed
expired
cancelled
```

預設 TTL：

```text
120 minutes
```

---

## 14. Task Token

要求：

- cryptographically random
- unpredictable
- expires
- single session
- hash-only storage

---

## 15. Task Validate

Target 呼叫：

```http
POST /api/v1/device/task/validate
```

Server 檢查：

- Task exists
- active
- not expired
- challenge published
- Device active
- Device owner = student
- first validate 可 bind device
- subsequent validate 必須同 device

---

## 16. Dynamic Flag

概念：

```text
HMAC-SHA256(
  student_id + ":" + challenge_uuid + ":" + task_uuid,
  FLAG_MASTER_SECRET
)
```

`FLAG_MASTER_SECRET` 只存在 CTF Server。

禁止放到 Target VM。

---

## 17. Submission

```http
POST /api/v1/student/submit
```

Server：

- verify owner
- verify task
- recompute expected flag
- constant-time compare
- create submission
- create solve if first correct
- update task completed

---

## 18. Solve

unique：

```text
(student_id, challenge_id)
```

避免重複得分。

---

## 19. Automatic Verification

Target 可回：

```http
POST /api/v1/device/task/complete
```

Server 必須檢查：

- device auth
- ownership
- task status
- challenge match
- timestamp
- nonce
- duplicate solve

Target 不得傳入 points 決定得分。

---

## 20. Leaderboard

排序：

1. score DESC
2. last_solve ASC

---

## 21. Session / CSRF / Password

- PHP Session
- `session_regenerate_id(true)`
- HttpOnly
- Secure in production
- SameSite=Lax
- CSRF on browser mutations
- `PASSWORD_ARGON2ID`
- PDO prepared statements only

---

## 22. Rate Limit

至少：

```text
Login: 5/min
Activation: 5/min
Task Validate: 10/min
Flag Submit: 10/min
```

---

## 23. Audit Log

至少：

- login
- logout
- teacher_approved
- challenge_create
- challenge_publish
- challenge_disable
- device_activate
- device_revoke
- task_start
- task_validate
- flag_submit
- challenge_complete

---

## 24. 禁止事項

Server 不得：

- trust Target score
- trust Target student_id
- 使用 SQL string concatenation
- 儲存 plaintext password
- 儲存 plaintext device token
- 把 Challenge ZIP 放 public
- hard-code secret

---

## 25. CLI

```bash
php bin/migrate.php
php bin/seed.php
php bin/create-admin.php
php bin/cleanup.php
```

---

## 26. 測試

至少：

- Registration
- Teacher approval
- Authorization
- CSRF
- Device activation
- Device revoke
- Challenge upload
- ZIP validation
- Task token expiration
- Device ownership
- Correct/wrong flag
- Duplicate solve
- Leaderboard
- Rate limit
