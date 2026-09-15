# ARCHITECTURE.md

# Distributed CTF Training Platform — System Architecture

## 1. 文件目的

本文件定義整個 CTF 分散式資安攻防演練平台的系統架構、部署邊界、信任邊界、主要資料流與元件責任。

本平台採用：

```text
CTF Server = Control Plane
Target VM  = Execution Plane
```

兩者位於同一個 Git Monorepo 中開發，但必須是 **兩個完全獨立的部署單元**。

---

# 2. 高階系統架構

```text
                           ┌──────────────────────────────┐
                           │          CTF Server          │
                           │        Control Plane         │
                           │                              │
                           │  Native PHP + Apache         │
                           │  MariaDB                     │
                           │                              │
                           │  User / Teacher / Admin      │
                           │  Challenge Management        │
                           │  Package Repository          │
                           │  Device Management           │
                           │  Task Session / Task Token   │
                           │  Flag Verification           │
                           │  Score / Leaderboard         │
                           │  Audit Log                   │
                           └──────────────┬───────────────┘
                                          │
                                          │ HTTPS REST API
                                          │
              ┌───────────────────────────┼───────────────────────────┐
              │                           │                           │
              ▼                           ▼                           ▼
      ┌─────────────────┐         ┌─────────────────┐         ┌─────────────────┐
      │   Target VM A   │         │   Target VM B   │         │   Target VM C   │
      │ Execution Plane │         │ Execution Plane │         │ Execution Plane │
      │                 │         │                 │         │                 │
      │ PHP Portal      │         │ PHP Portal      │         │ PHP Portal      │
      │ Python Agent    │         │ Python Agent    │         │ Python Agent    │
      │ MariaDB         │         │ MariaDB         │         │ MariaDB         │
      │ Challenges      │         │ Challenges      │         │ Challenges      │
      └─────────────────┘         └─────────────────┘         └─────────────────┘
```

---

# 3. Monorepo 與部署邊界

Repository：

```text
ctf-platform/
├── ctf-server/
├── target/
│   ├── agent/
│   ├── portal/
│   └── database/
├── challenge-example/
├── database/
│   └── server_database.sql
├── scripts/
│   ├── server/
│   └── target/
├── docs/
├── ARCHITECTURE.md
├── 專案agent.md
├── CTF_Server_SPEC.md
├── Target_VM_SPEC.md
├── Challenge_Package_SPEC.md
└── style.md
```

## CTF Server Deployment Unit

部署：

```text
ctf-server/
database/server_database.sql
scripts/server/
```

不得依賴：

```text
target/
```

實際部署位置：

```text
/var/www/ctf-server/
/var/lib/ctf-server/
/var/log/ctf-server/
```

## Target VM Deployment Unit

部署：

```text
target/
scripts/target/
```

不得依賴：

```text
ctf-server/
database/server_database.sql
```

實際部署位置：

```text
/opt/ctf-agent/
/usr/local/bin/ctf-agent
/var/www/ctf-target-portal/
/srv/ctf/challenges/
/var/lib/ctf-agent/
/var/log/ctf-agent/
```

---

# 4. Trust Boundary

最重要的安全原則：

```text
CTF Server = Trusted
Target VM  = Untrusted
Student    = Untrusted
Network    = Untrusted
```

因此：

```text
Target VM 永遠不能直接決定分數
Target VM 永遠不能直接修改 solve
Target VM 永遠不能持有 Flag Master Secret
Target VM 永遠不能持有 CTF Server DB Credential
```

CTF Server 才是唯一 Authority。

---

# 5. Database Architecture

CTF Server 與 Target VM 使用完全分離的 MariaDB。

## CTF Server Database

```text
ctf_server
├── users
├── devices
├── device_activation_codes
├── challenges
├── challenge_packages
├── task_sessions
├── submissions
├── solves
├── nonces
├── settings
├── rate_limits
└── audit_logs
```

用途：

```text
中央帳號
題目
裝置
Task
Flag Submission
Solve
Score
Leaderboard
Audit
```

## Target VM Database

```text
ctf_target
├── installed_challenges
├── local_tasks
├── sync_history
├── agent_events
└── local_settings
```

另外每一道 Challenge 可建立自己的 DB：

```text
ctf_web_sql_001
ctf_web_xss_001
ctf_web_cmd_001
...
```

兩邊資料庫 **不直接連線**。

禁止：

```text
Target VM → MySQL 3306 → CTF Server Database
```

正確：

```text
Target VM → HTTPS REST API → CTF Server
```

---

# 6. CTF Server Internal Architecture

```text
Browser / Device / Agent
          │
          ▼
       Apache
          │
          ▼
 public/index.php
          │
          ▼
       Router
          │
          ▼
     Middleware
          │
          ▼
     Controller
          │
          ▼
      Service
          │
          ▼
    Repository
          │
          ▼
         PDO
          │
          ▼
      MariaDB
```

責任：

## Router

- Route matching
- HTTP method
- Route parameters

## Middleware

- Authentication
- Authorization
- CSRF
- Device Auth
- Rate Limit

## Controller

- HTTP request / response
- Validation entry point
- Call Service

## Service

- Business logic
- Task lifecycle
- Flag verification
- Device activation
- Challenge publishing

## Repository

- PDO
- Prepared statements
- Database persistence

---

# 7. Target VM Internal Architecture

```text
Student Browser
      │
      ▼
 Apache + PHP Portal
      │
      │ localhost HTTP
      ▼
 Python Target Agent
      │
      ├── HTTPS → CTF Server
      ├── MariaDB → ctf_target
      ├── Filesystem → /srv/ctf/challenges
      └── Challenge Runtime
```

Portal 不負責：

```text
system()
exec()
shell_exec()
root filesystem operation
MariaDB root operation
```

以上交由 Agent。

---

# 8. Device Activation Flow

```text
Student
   │
   │ Login
   ▼
CTF Server
   │
   │ Generate Activation Code
   ▼
ACT-XXXX-XXXX-XXXX
   │
   ▼
Student enters code into Target Portal
   │
   ▼
Target Agent
   │
   │ POST /api/v1/device/activate
   ▼
CTF Server
   │
   ├── validate code
   ├── validate expiry
   ├── single-use check
   └── create device
   │
   ▼
Return:
device_id
device_token
   │
   ▼
Target VM saves credential
```

Target 儲存：

```text
/var/lib/ctf-agent/device.json
```

Server DB 只保存：

```text
hash(device_token)
```

---

# 9. Challenge Publish Flow

```text
Teacher
   │
   ▼
CTF Server UI
   │
   ├── title
   ├── type
   ├── difficulty
   ├── description
   ├── points
   └── ZIP
   │
   ▼
CTF Server
   │
   ├── validate ZIP
   ├── validate manifest.json
   ├── Zip Slip check
   ├── calculate SHA-256
   ├── save metadata
   └── save package
   │
   ▼
Challenge = published
```

Server package storage：

```text
/var/lib/ctf-server/challenges/{uuid}/{version}/challenge.zip
```

---

# 10. Challenge Sync Flow

```text
systemd timer / Portal Sync
          │
          ▼
     Target Agent
          │
          │ GET /api/v1/device/challenges
          ▼
       CTF Server
          │
          ▼
Challenge metadata list
          │
          ▼
     Target Agent
          │
          ├── compare local version
          ├── find missing/outdated
          │
          ▼
Download challenge.zip
          │
          ├── SHA-256 verify
          ├── safe unzip
          ├── manifest validate
          ├── install files
          ├── setup.sql
          └── update local DB
          │
          ▼
Challenge ready
```

主同步機制：

```text
systemd timer
```

Portal Sync：

```text
manual / recovery
```

---

# 11. Task Start Flow

```text
Student
   │
   │ Login to CTF Server
   ▼
Challenge List
   │
   │ Start Challenge
   ▼
CTF Server
   │
   ├── create task_sessions row
   ├── generate random Task Token
   ├── save token hash
   └── set expires_at
   │
   ▼
Task Token
TASK-XXXX-XXXX-XXXX
```

Task Token 代表：

```text
Student is authorized to start this challenge session
```

Task Token 不是 Flag，也不是得分憑證。

---

# 12. Task Validate Flow

```text
Student
   │
   │ paste Task Token
   ▼
Target Portal
   │
   ▼
Target Agent
   │
   │ POST /api/v1/device/task/validate
   ▼
CTF Server
   │
   ├── token exists
   ├── task active
   ├── task not expired
   ├── device active
   ├── device owner == task student
   ├── challenge published
   └── bind device if first validation
   │
   ▼
Valid Task Context
   │
   ▼
Target Agent
   │
   ├── create local_tasks
   ├── check challenge installed
   └── start challenge
   │
   ▼
Challenge Entry
```

---

# 13. Flag Verification Flow

建議標準 CTF 流程：

```text
Student attacks challenge
        │
        ▼
Obtains Flag
        │
        ▼
Returns to CTF Server
        │
        │ Submit Flag
        ▼
CTF Server
        │
        ├── verify logged-in user
        ├── verify task owner
        ├── verify task active
        ├── recompute expected dynamic flag
        ├── constant-time compare
        ├── write submission
        ├── create solve if first
        └── assign points
        │
        ▼
Leaderboard update
```

Target VM 不直接加分。

---

# 14. Dynamic Flag Model

概念：

```text
HMAC-SHA256(
    student_id
    + challenge_uuid
    + task_uuid,
    FLAG_MASTER_SECRET
)
```

例如：

```text
flag{9f2d...}
```

`FLAG_MASTER_SECRET`：

```text
只存在 CTF Server
```

不進入 Target VM。

---

# 15. Automatic Verification Flow

部分 Challenge 可使用：

```text
verification.type = automatic
```

流程：

```text
Challenge state
    │
    ▼
Local Verifier
    │
    ▼
Target Agent
    │
    │ completion evidence
    ▼
CTF Server
    │
    ├── verify device
    ├── verify ownership
    ├── verify task
    ├── verify timestamp
    ├── verify nonce
    └── prevent duplicate solve
```

Target 不得送：

```text
student_id to override owner
points to decide score
score to update leaderboard
```

---

# 16. Challenge Reset Flow

```text
Student
   │
   │ Reset
   ▼
Target Portal
   │
   ▼
Target Agent
   │
   ├── stop challenge
   ├── restore package files
   ├── drop challenge DB
   ├── recreate DB
   ├── import setup.sql
   └── reset runtime state
   │
   ▼
Challenge Ready
```

Reset 不修改：

```text
CTF Server solves
CTF Server score
Leaderboard
```

---

# 17. Heartbeat Flow

```text
Target Agent
    │
    │ POST /api/v1/device/heartbeat
    ▼
CTF Server
```

可包含：

- agent_version
- target_version
- installed_challenge_count
- current status

Server 更新：

```text
devices.last_seen_at
```

---

# 18. Deployment Architecture

## CTF Server

```text
Ubuntu Server
├── Apache
├── PHP
├── MariaDB
├── /var/www/ctf-server
├── /var/lib/ctf-server
└── /var/log/ctf-server
```

## Target VM

```text
Ubuntu Server
├── Apache
├── PHP Portal
├── Python Agent
├── MariaDB
├── /srv/ctf/challenges
├── /var/lib/ctf-agent
└── /var/log/ctf-agent
```

---

# 19. Network Architecture

基本模式：

```text
Target VM
   │
   │ HTTPS
   ▼
CTF Server
```

Target 不直接存取 Server DB。

推薦 Lab VM：

```text
Adapter 1: NAT / Internet / CTF Server
Adapter 2: Host-Only / Internal attack network
```

依校園實際網路環境調整。

---

# 20. Security Boundaries Summary

## Server Only

以下只能存在 CTF Server：

```text
FLAG_MASTER_SECRET
Server DB credential
Teacher/Admin session
Package signing private key
Score calculation logic
Solve authority
```

## Target Allowed

Target 可持有：

```text
device_id
device_token
task context
challenge package
local DB credentials
challenge DB credentials
```

---

# 21. Failure Handling

## CTF Server unavailable

Target：

- 顯示 Offline
- 保留已安裝 Challenge
- 不建立新的 Task
- 不自行判斷 Server solve

## Package hash mismatch

Target：

```text
delete
log
retry
do not install
```

## setup.sql failed

Target：

```text
cleanup partial DB
mark failed
log error
```

## Device revoked

Server：

```text
401 / 403
```

Target：

```text
show revoked
stop server sync actions
```

---

# 22. Architecture Invariants

以下條件任何實作都不可破壞：

1. CTF Server 與 Target VM 為兩個獨立部署單元。
2. Target 不直接連線 CTF Server MariaDB。
3. Server 不 require Target source。
4. Target 不 require Server source。
5. Target 不得持有 Flag Master Secret。
6. Score / Solve 只能由 CTF Server決定。
7. Task Token 不等於 Flag。
8. Device Token 不等於 Student password。
9. Challenge Package 必須 versioned。
10. ZIP 必須驗證 hash 與 extraction path。
11. Target Database 與 Server Database 分離。
12. Challenge Database 與 Target 管理 DB 分離。
13. Portal system operation 交由 Agent。
14. Browser state-changing request 必須 CSRF。
15. Device API 使用 Device Bearer Token。

---

# 23. Coding Agent Architecture Rule

AI Coding Agent 必須把本文件視為架構最高層規格。

若其他文件存在歧義，以本文件下列優先順序判斷：

```text
1. Trust Boundary
2. Deployment Boundary
3. Database Separation
4. CTF Server Authority
5. API Contract
6. Implementation Convenience
```

不得為了簡化程式碼而合併 CTF Server 與 Target VM。
