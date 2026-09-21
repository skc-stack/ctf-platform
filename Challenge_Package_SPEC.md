# Challenge Package SPEC

## 1. 部署關係

Challenge Package 由 CTF Server 管理並發布，下載到 Target VM 安裝。

Challenge source / package 不應成為 `ctf-server/` 或 `target/` 的 runtime cross-dependency。

範例題目放：

```text
challenge-example/
```

Server 保存 ZIP：

```text
/var/lib/ctf-server/challenges/
```

Target 安裝：

```text
/srv/ctf/challenges/
```

---

## 2. ZIP 格式（扁平化結構）

最小：

```text
challenge.zip
└── manifest.json
```

所有題目（扁平化，無子目錄）：

```text
challenge.zip
├── manifest.json
├── index.php       # 挑戰進入點
├── check.php       # 任務驗證（老師實作 check() 函式）
├── setup.sql       # 資料庫設定（可選）
└── 其他資源檔案     # CSS, JS, images 等
```

**重要：所有檔案必須放在 ZIP 根目錄，不使用子目錄。**

---

## 3. manifest.json

必填：

```json
{
  "schema_version": 1,
  "challenge_id": "SQL-001",
  "name": "SQL Injection 基礎",
  "version": 1,
  "type": "web",
  "difficulty": "easy",
  "entrypoint": "/challenge/start/SQL-001/",
  "verification": {
    "type": "flag"
  }
}
```

---

## 4. challenge_id

建議：

```text
CATEGORY-NAME-NNN
```

限制：

- uppercase ASCII
- A-Z
- 0-9
- hyphen
- <=64
- no path separator

---

## 5. version

整數遞增：

```text
1, 2, 3...
```

Target 只在 server version 大於 local version 時更新。

---

## 6. type

```text
web
crypto
reverse
pwn
forensic
misc
network
```

---

## 7. verification

Flag：

```json
{
  "type": "flag"
}
```

Automatic：

```json
{
  "type": "automatic",
  "verifier": "verifier/verify.py"
}
```

---

## 8. Database

無：

```json
{
  "enabled": false
}
```

有：

```json
{
  "enabled": true,
  "setup_file": "setup.sql"
}
```

---

## 9. 完整範例

```json
{
  "schema_version": 1,
  "challenge_id": "SQL-001",
  "name": "SQL Injection 基礎",
  "description": "找出管理員資料並取得 Flag。",
  "version": 1,
  "type": "web",
  "difficulty": "easy",
  "entrypoint": "/challenge/start/SQL-001/",
  "runtime": {
    "type": "apache_php"
  },
  "database": {
    "enabled": true,
    "setup_file": "setup.sql"
  },
  "verification": {
    "type": "flag"
  },
  "reset": {
    "database": true,
    "files": true
  }
}
```

---

## 10. setup.sql

只能作用於該 Challenge DB。

不得：

- 修改 mysql system DB
- DROP 其他 challenge DB
- 建立 global privileged user
- 包含 CTF Server credential

---

## 11. Dynamic Flag

Package 不得保存全域固定 Flag secret。

Server 負責 Task-specific Flag。

Target 可接收該 Task 的結果資料，但不得取得 master secret。

---

## 12. verifier

Automatic verifier：

- deterministic
- 有 timeout
- exit 0 success
- 不直接修改 Server score
- 不使用 Admin API

---

## 13. Reset

manifest：

```json
{
  "database": true,
  "files": true
}
```

Reset 可還原 DB 與檔案，但不修改 Server solve。

---

## 14. Package Validation

Server 與 Target 都要驗證：

- ZIP valid
- manifest exists
- JSON valid
- schema supported
- challenge_id
- version
- type
- difficulty
- safe entrypoint
- referenced file exists
- no traversal
- no unsafe symlink

---

## 15. ZIP Slip

禁止：

```text
../../etc/passwd
../portal/index.php
/etc/shadow
C:\Windows\...
```

所有 path resolve 後必須仍位於 challenge root。

---

## 16. Hash

Server 計算：

```text
SHA-256
```

Target 下載後再次計算。

不符：

```text
reject
delete
log
```

---

## 17. Demo Challenge

專案必須提供：

```text
challenge-example/DEMO-001/
```

扁平結構：

```text
challenge-example/DEMO-001/
├── manifest.json
├── index.php
└── check.php
```

用途：

- upload
- sync
- install
- task token
- flag
- score

不包含真實漏洞。
