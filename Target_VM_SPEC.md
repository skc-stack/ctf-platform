# Target VM SPEC

## 1. 部署邊界

本文件只描述 Target VM。

所有 Target 程式碼必須位於：

```text
target/
```

包含：

```text
target/agent/
target/portal/
target/database/
```

Target VM 不得部署：

```text
ctf-server/
database/server_database.sql
```

Target 在 runtime 不得 require/include CTF Server source。

---

## 2. Target Repository 結構

```text
target/
├── agent/
│   ├── src/
│   ├── tests/
│   ├── systemd/
│   ├── requirements.txt
│   └── README.md
├── portal/
│   ├── public/
│   ├── src/
│   ├── views/
│   ├── config/
│   └── README.md
├── database/
│   └── target_database.sql
├── install.sh
└── README.md
```

---

## 3. 實際部署路徑

```text
/opt/ctf-agent/
/usr/local/bin/ctf-agent
/var/www/ctf-target-portal/
/srv/ctf/challenges/
/var/lib/ctf-agent/
/var/log/ctf-agent/
```

---

## 4. 技術

- Ubuntu Server 24.04 LTS
- Apache 2.4
- PHP 8.3+
- MariaDB
- Python 3.12+
- systemd

---

## 5. Target VM 安全邊界

Target VM 一律視為 Untrusted。

不得保存：

- CTF Server source
- CTF Server `.env`
- Server DB password
- FLAG_MASTER_SECRET
- Teacher/Admin credential
- Package signing private key
- 可直接修改分數的 credential

Target 只持有：

- device_id
- device_token
- local task state
- installed challenge state

---

## 6. 元件

```text
Target VM
├── PHP Portal
├── Python Agent
├── MariaDB
├── Challenge Storage
└── systemd
```

---

## 7. Device Activation

Portal：

```text
Device Not Activated
Activation Code: [________]
[Activate]
```

Agent → Server：

```http
POST /api/v1/device/activate
```

保存：

```text
/var/lib/ctf-agent/device.json
```

Permission：

```bash
chown root:root /var/lib/ctf-agent/device.json
chmod 600 /var/lib/ctf-agent/device.json
```

---

## 8. Portal → Agent

Portal 不可直接使用：

- shell_exec
- system
- exec

所有 system operation 交由 Agent。

Local API：

```text
127.0.0.1:8787
```

API：

```text
GET  /status
POST /activate
POST /sync
POST /task
POST /reset
POST /heartbeat
```

---

## 9. Agent CLI

```bash
ctf-agent status
ctf-agent sync
ctf-agent heartbeat
ctf-agent list
ctf-agent reset <challenge_id>
ctf-agent doctor
```

---

## 10. Sync

觸發：

- VM boot
- systemd timer
- Portal manual sync

預設：

```text
5 minutes
```

流程：

```text
fetch server challenge list
→ compare local version
→ download missing/outdated
→ verify SHA-256
→ safe unzip
→ parse manifest
→ install
→ setup.sql
→ update local DB
```

---

## 11. Challenge Download

暫存：

```text
/var/lib/ctf-agent/packages/
```

驗證：

```text
SHA-256
```

錯誤：

- delete
- log
- retry max 3
- do not install

---

## 12. Safe Extraction

安裝到：

```text
/srv/ctf/challenges/{challenge_id}/
```

拒絕：

- `../`
- absolute path
- unsafe symlink
- path escape

---

## 13. Local DB

Target 使用：

```text
ctf_target
```

表：

- installed_challenges
- local_tasks
- sync_history
- agent_events
- local_settings

---

## 14. Challenge Database

若 manifest database enabled：

```text
ctf_<normalized_challenge_id>
```

每題獨立 DB account。

Challenge 不得使用 MariaDB root。

---

## 15. setup.sql

流程：

```text
create DB
→ create limited DB user
→ grant only challenge DB
→ import setup.sql
→ health check
→ mark installed
```

失敗：

- clean incomplete DB
- mark failed
- log

---

## 16. Task Token

Portal：

```text
Task Token:
[________________]
[Start]
```

Agent → Server：

```http
POST /api/v1/device/task/validate
```

成功後：

- 確認 Challenge 已安裝
- 建立 local_tasks
- 啟動 Challenge
- 提供 entrypoint

---

## 17. Challenge Entry

Web Challenge：

```text
/challenge/{challenge_id}/
```

只在合法 Task session 期間提供。

---

## 18. Reset

流程：

```text
stop challenge
→ restore files
→ drop challenge DB
→ recreate DB
→ import setup.sql
→ clear runtime state
→ ready
```

Server solve 不受 reset 影響。

---

## 19. Automatic Verification

Verifier 可判斷：

- file state
- DB state
- challenge state
- script exit status

Agent 完成後回 Server。

Target 不得指定：

- student_id
- score
- points

---

## 20. Heartbeat

定期：

```http
POST /api/v1/device/heartbeat
```

送：

- agent version
- target version
- installed count

不得送 sensitive secret。

---

## 21. Logging

```text
/var/log/ctf-agent/agent.log
/var/log/ctf-agent/sync.log
/var/log/ctf-agent/install.log
```

禁止記錄完整 device_token。

---

## 22. systemd

提供：

```text
ctf-agent.service
ctf-agent.timer
```

Portal 的 Apache user 不給 root。

---

## 23. Configuration

```text
/etc/ctf-agent/config.json
```

範例：

```json
{
  "server_url": "https://ctf.example.edu.tw",
  "sync_interval": 300,
  "connect_timeout": 10,
  "download_timeout": 120
}
```

---

## 24. Target 安裝

部署只需要：

```text
target/
scripts/target/
```

安裝：

```bash
cd target
sudo ./install.sh
```

或：

```bash
sudo scripts/target/install-target.sh
```

不得要求 `ctf-server/` 存在。

---

## 25. Acceptance Tests

1. Target 可獨立部署，不需要 Server source。
2. Activation 正常。
3. Revoked Device API 失效。
4. Sync 新題。
5. Hash 錯誤拒絕。
6. ZIP traversal 拒絕。
7. setup.sql 正常。
8. Task validate 正常。
9. 他人 Device 不可 validate。
10. Reset 可還原。
