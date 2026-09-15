# CTF 分散式資安攻防演練平台 — AI Coding Agent 開發總指令

## 1. 專案目標

建立一套供學校學生使用的 CTF 資安攻防演練平台。

整體 repository 採用 **monorepo**，但實際部署必須分成兩個完全獨立的部署單元：

1. **CTF Server**
2. **Target VM**

兩端程式碼必須在 repository 中明確分離，不得在 runtime 互相依賴彼此的 source directory。

---

## 2. 最高優先部署原則

### 2.1 Monorepo，但兩個獨立部署單元

Repository 結構：

```text
ctf-platform/
├── ctf-server/
├── target/
│   ├── agent/
│   └── portal/
├── challenge-example/
├── database/
├── scripts/
├── docs/
├── style.md
├── 專案agent.md
├── CTF_Server_SPEC.md
├── Target_VM_SPEC.md
├── Challenge_Package_SPEC.md
└── README.md
```

其中：

```text
ctf-server/
```

只能部署到中央 CTF Server。

```text
target/
```

只能部署到學生 Target VM。

### 2.2 禁止 Runtime Cross-Dependency

CTF Server 與 Target VM 必須是兩個完全獨立的 runtime deployment unit。

禁止：

```text
ctf-server require/include target source
target require/include ctf-server source
target 讀取 ctf-server .env
target 使用 CTF Server DB credential
target 存放 FLAG_MASTER_SECRET
target 存放 teacher/admin credential
```

Target VM 不得包含：

- `ctf-server/` 原始碼
- CTF Server `.env`
- CTF Server DB account/password
- `FLAG_MASTER_SECRET`
- Teacher credential
- Admin credential
- Package signing private key
- 可直接修改分數的 credential

CTF Server 不需要安裝：

- Target Agent
- Target Portal
- Challenge runtime

---

## 3. 建議最終專案目錄

```text
ctf-platform/
│
├── ctf-server/
│   ├── public/
│   │   ├── index.php
│   │   └── assets/
│   ├── src/
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Repositories/
│   │   ├── Services/
│   │   ├── Middleware/
│   │   ├── Security/
│   │   ├── Database/
│   │   ├── Http/
│   │   └── Support/
│   ├── views/
│   │   ├── layouts/
│   │   ├── auth/
│   │   ├── student/
│   │   ├── teacher/
│   │   └── admin/
│   ├── routes/
│   │   ├── web.php
│   │   └── api.php
│   ├── config/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeds/
│   ├── storage/
│   │   ├── logs/
│   │   ├── challenges/
│   │   └── tmp/
│   ├── bin/
│   ├── tests/
│   ├── bootstrap.php
│   ├── composer.json
│   ├── install.sh
│   └── README.md
│
├── target/
│   ├── agent/
│   │   ├── src/
│   │   ├── tests/
│   │   ├── systemd/
│   │   ├── requirements.txt
│   │   └── README.md
│   ├── portal/
│   │   ├── public/
│   │   ├── src/
│   │   ├── views/
│   │   ├── config/
│   │   └── README.md
│   ├── database/
│   │   └── target_database.sql
│   ├── install.sh
│   └── README.md
│
├── challenge-example/
│
├── database/
│   └── server_database.sql
│
├── scripts/
│   ├── server/
│   │   └── install-server.sh
│   └── target/
│       └── install-target.sh
│
├── docs/
├── style.md
├── 專案agent.md
├── CTF_Server_SPEC.md
├── Target_VM_SPEC.md
├── Challenge_Package_SPEC.md
└── README.md
```

---

## 4. 兩端部署對應

### CTF Server 主機部署

只部署：

```text
ctf-server/
database/server_database.sql
scripts/server/
```

實際路徑：

```text
/var/www/ctf-server/
/var/lib/ctf-server/
/var/log/ctf-server/
```

### Target VM 部署

只部署：

```text
target/
scripts/target/
target/database/target_database.sql
```

實際路徑：

```text
/opt/ctf-agent/
/usr/local/bin/ctf-agent
/var/www/ctf-target-portal/
/srv/ctf/challenges/
/var/lib/ctf-agent/
/var/log/ctf-agent/
```

---

## 5. 核心安全模型

1. **CTF Server 是唯一可信任的系統。**
2. **Target VM 一律視為不可信任端。**
3. Server 必須自行決定：
   - Task 是否有效
   - Flag 是否正確
   - 是否得分
   - 得分多少
   - Solve 是否已存在
4. Target VM 不得持有 Server master secret。
5. Target VM 只能持有：
   - device_id
   - device_token
   - local challenge state
6. Automatic verification 仍不可讓 Target 任意指定：
   - student_id
   - score
   - points

---

## 6. 技術限制

### 6.1 CTF Server

必須使用：

- Ubuntu Server 24.04 LTS
- Apache 2.4
- PHP 8.3+
- MariaDB 10.11+
- 原生 PHP
- PDO
- Bootstrap 5
- PHP Session
- REST JSON API

禁止使用：

- Laravel
- Symfony Framework
- CodeIgniter
- Slim
- Yii
- CakePHP
- Laminas MVC
- 任何其他 PHP Web Framework

Composer 僅可用於 standalone library，例如：

- `vlucas/phpdotenv`
- `monolog/monolog`

### 6.2 Target VM

- Ubuntu Server 24.04 LTS
- Apache
- PHP 8.3+
- MariaDB
- Python 3.12+
- systemd
- Native PHP Portal
- Python Agent

---

## 7. CTF Server 架構

```text
Browser / API Client
        ↓
Apache
        ↓
public/index.php
        ↓
Router
        ↓
Middleware
        ↓
Controller
        ↓
Service
        ↓
Repository
        ↓
PDO
        ↓
MariaDB
```

---

## 8. Target 架構

```text
Browser
  ↓
Target Portal (PHP)
  ↓
Local Agent API (127.0.0.1:8787)
  ↓
Python Agent
  ↓
CTF Server API / Local MariaDB / Challenge Files
```

Target Portal 不可直接使用：

- `shell_exec()`
- `system()`
- `exec()`

系統操作交由 Python Agent。

---

## 9. Server Coding Rules

- 所有 DB 操作使用 PDO prepared statement。
- Browser authentication 使用 PHP Session。
- Login 後 `session_regenerate_id(true)`。
- Password 使用 `PASSWORD_ARGON2ID`。
- Browser state-changing request 必須 CSRF。
- Device API 使用 Bearer Token。
- Device token 以 `random_bytes(32)` 產生。
- DB 只保存 token hash。
- 所有 API 統一 JSON format。
- 所有 sensitive value 使用 `.env`。
- 不得 hard-code secret。

---

## 10. Target Agent

至少支援：

```bash
ctf-agent status
ctf-agent sync
ctf-agent heartbeat
ctf-agent list
ctf-agent reset <challenge_id>
```

Local API：

```text
GET  /status
POST /activate
POST /sync
POST /task
POST /reset
POST /heartbeat
```

Bind：

```text
127.0.0.1:8787
```

---

## 11. Challenge Package

每個 ZIP 必須包含：

```text
manifest.json
```

可包含：

```text
web/
setup.sql
assets/
verifier/
```

Target 安裝流程：

```text
download
→ SHA-256 verify
→ safe unzip
→ parse manifest
→ install
→ setup.sql
→ register local challenge
```

必須防止 Zip Slip。

---

## 12. 開發階段

### Phase 1 — Server 基礎

- Native PHP Front Controller
- Router
- PDO
- Session Authentication
- Student Registration
- Teacher Registration
- Admin approval
- Role Middleware
- CSRF

### Phase 2 — Challenge Management

- CRUD
- ZIP upload
- manifest parser
- SHA-256
- Publish / Disable
- version

### Phase 3 — Device

- Activation Code
- Activation API
- Device Token
- Heartbeat
- Revoke

### Phase 4 — Target Agent

- Activation
- Sync
- Download
- Safe extraction
- setup.sql
- systemd

### Phase 5 — Task Session

- Start Challenge
- Task Token
- TTL
- Device ownership validation

### Phase 6 — Flag / Score

- Dynamic Flag
- Submission
- Solve
- Score
- Leaderboard

### Phase 7 — Target Portal

- Activation UI
- Sync
- Task Token
- Challenge Start
- Reset

### Phase 8 — Security / Tests

- Rate limit
- Audit log
- Validation
- Security review
- Automated tests
- Deployment scripts

---

## 13. MVP 驗收流程

```text
Admin 建立
→ Teacher 註冊
→ Admin 核准
→ Teacher 建立題目
→ Upload ZIP
→ Publish
→ Student 註冊
→ Activation Code
→ Target VM Activate
→ Target VM Sync
→ Challenge Install
→ Student Start Challenge
→ Task Token
→ Target Validate
→ Challenge Start
→ Flag
→ Submit
→ Solve
→ Score
→ Leaderboard
```

全部通過才可標記：

```text
MVP COMPLETE
```

---

## 14. AI Coding Agent 工作規則

1. 不得自行改變兩端部署邊界。
2. 不得建立 runtime cross-dependency。
3. 不得把 Server Secret 放進 Target。
4. 不得使用禁止 Framework。
5. 每完成 Phase 必須測試。
6. Migration 必須可重複部署。
7. API 必須 validation。
8. DB 必須 prepared statement。
9. Security-sensitive 邏輯必須測試。
10. README 隨實作更新。
11. 先完成 end-to-end MVP。
12. 不得省略：
   - Authentication
   - Authorization
   - CSRF
   - Rate Limit
   - ZIP Path Validation
   - Token Expiration
   - Device Ownership Check
   - Solve uniqueness

---

## 15. 最終交付

必須包含：

- `ctf-server/`
- `target/agent/`
- `target/portal/`
- `database/server_database.sql`
- `target/database/target_database.sql`
- `scripts/server/install-server.sh`
- `scripts/target/install-target.sh`
- Demo Challenge
- API documentation
- Deployment documentation
- Automated tests
- README
