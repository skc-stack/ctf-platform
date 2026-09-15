# CTF Server (Control Plane)

中央 CTF Server — 帳號、題目、裝置、Task、Flag 驗證、Solve、Leaderboard。

## 技術

- PHP 8.3+（原生，無 Framework）
- Apache 2.4
- MariaDB 10.11+ / MySQL 8
- Composer（僅 vlucas/phpdotenv 與 monolog/monolog）

## 本機開發

需要：

- Apache 2.4
- PHP 8.3（啟用 `pdo_mysql`、`mbstring`、`openssl`）
- MariaDB
- Composer

啟動順序：

1. `mariadbd`
2. `httpd`
3. 訪問 `http://localhost/ctf-server/public/`

詳細環境說明見根目錄的 `SERVER-ENVIRONMENT.md`。

## 設定

```bash
cp .env.example .env
# 編輯 .env，至少改 DB_PASSWORD 與 FLAG_MASTER_SECRET
```

執行 migrations：

```bash
php bin/migrate.php
```

## 目錄

```text
public/             front controller（Apache DocumentRoot）
src/                application code
  Controllers/      HTTP controllers
  Http/             Router, Request, Response, View
  Middleware/       Auth, CSRF, Role, RateLimit
  Database/         PDO Connection
  Security/         Password, FlagGenerator, CSRF
  Support/          Config, Logger
views/              PHP templates
routes/             web.php, api.php
database/migrations SQL migrations
bin/                CLI tools（migrate / seed / create-admin / cleanup）
storage/            challenges, logs, tmp（runtime, not in git）
```

## 安全

- `FLAG_MASTER_SECRET` 不得 commit，不得放進 Target VM
- Device token 只存 SHA-256 hash
- 所有 DB 走 PDO prepared statement
- 所有 browser state-changing 走 CSRF
- 所有 device API 走 Bearer Token

完整安全模型見根目錄 `ARCHITECTURE.md`。
