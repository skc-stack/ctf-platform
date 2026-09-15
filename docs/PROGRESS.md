# CTF LAB — §0–§10 工作成果報告

> 從 Foundation 一路到 Security / Tests / Deploy 的完整開發紀錄。
> 最後更新：2026-09-14，狀態：**MVP COMPLETE**。

---

## 1. 專案概述

CTF LAB 是一套分散式資安攻防演練平台，採用 **monorepo** 結構，分成兩個完全獨立的部署單元：

| 部署單元 | 程式 | 角色 | Trust Level |
|---|---|---|---|
| **CTF Server** | `ctf-server/` (PHP 8.3 + MariaDB) | 帳號、題目、Task、Flag 驗證、Leaderboard | **Trust root** |
| **Target VM** | `target/` (Python Agent + PHP Portal) | 學生的本地解題環境 | **Untrusted** |

設計原則詳見 `CLAUDE.md` 與 `docs/SECURITY.md`（15 條 invariants）。

---

## 2. 各階段工作成果

### §0 Foundation（環境整備）

**目標**：建好 monorepo 骨架、Apache / PHP / MariaDB 環境、bootstrap + Router + Connection + View 的最小可運行骨架。

**交付檔案**：
- `ctf-server/{public,src,views,routes,config,database,storage,bin,tests}/` 完整目錄
- `target/{agent/{src,tests,systemd},portal/{public,src,views,config},database}/`
- `challenge-example/`、`scripts/{server,target}/`、`docs/`
- `ctf-server/composer.json`（僅 vlucas/phpdotenv + monolog/monolog）
- `ctf-server/.env.example`
- `ctf-server/bootstrap.php`（autoload + dotenv + Config + Logger + Session + Error handler）
- `ctf-server/public/index.php`（front controller）
- `ctf-server/routes/web.php`（Router 雛形）
- `ctf-server/database/migrations/000_init.sql`（13 張表 + leaderboard view）
- `target/database/target_database.sql`（5 張表）

**驗證**：landing page 與 `/health` 端點回 200。

---

### §1 Phase 1 — Auth + 角色

**目標**：學生 / 老師 / 管理員三種角色都能註冊、登入、被管理。

**交付檔案**：
- `src/Http/Router.php`（regex routing + middleware chain）
- `src/Http/Request.php` / `Response.php`
- `src/Security/PasswordHasher.php`（Argon2id + policy ≥8 含大小寫+數字）
- `src/Security/CSRF.php`（session-bound 雙重提交，登入後 rotate）
- `src/Middleware/{Base,Auth,Guest,CSRF,RequireRole,RateLimit,RateLimitLogin,RequireAdmin,RequireTeacher,RequireStudent}.php`
- `src/Repositories/UserRepository.php`
- `src/Services/{AuditLog,AuthService}.php`
- `src/Controllers/{AuthController,HomeController,Student/Teacher/Admin/DashboardController,Admin/UserApprovalController}.php`
- `views/{auth/login,auth/register,student/dashboard,teacher/dashboard,admin/dashboard,admin/users,leaderboard}.php`
- `bin/{migrate,create-admin,cleanup}.php`

**驗證**：`tests/e2e_phase1.php` 19 步驟 ALL PASS（register/login/CSRF/RateLimit/approve/leaderboard/logout）。

---

### §2 Phase 2 — 群組管理

**目標**：用「群組」取代直接的 student↔teacher 連結。老師建群組、學生以 join_code 加入。

**交付檔案**：
- `database/migrations/003_groups.sql`（groups + group_members）
- `src/Models/Group.php`
- `src/Repositories/{GroupRepository,GroupMemberRepository}.php`
- `src/Services/GroupService.php`（含 `generateUniqueJoinCode`：base32 + 5 次重試碰撞）
- `src/Controllers/{Teacher,Student}/GroupController.php`
- `views/{teacher,student}/groups/{index,new,show,join}.php`

**修改**：`views/layouts/base.php` nav 加「群組」連結、`src/Controllers/BaseController.php` 升級 `flashSuccess` / `flashError` 為共用。

**設計重點**：
- `join_code` 字符集：`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`（去除 I/O/0/1/L 易混淆字元）
- `status=left/banned` 保留 audit trail，不真刪除

**驗證**：`tests/e2e_groups.php` 10 步驟 ALL PASS（建立 / 加入 / 錯碼 / 詳情 / 踢人 / 封禁 / 換碼 / 刪除 + CASCADE）。

---

### §3 Phase 3 — Device API（啟用 + 連線管理）

**目標**：學生產生啟用碼，Target VM 用啟用碼換 Device Token。

**交付檔案**：
- `src/Services/ActivationCodeService.php`（`ACT-XXXX-XXXX-XXXX`，10 min 單次）
- `src/Services/DeviceService.php`（含 max_devices 限制）
- `src/Repositories/DeviceRepository.php`
- `src/Middleware/DeviceAuth.php`（Bearer Token + X-Device-ID）
- `src/Controllers/{DeviceController,DeviceApiController}.php`

**修改**：`routes/web.php` 加 3 條路由。

**過程修的 3 個 bug**：
1. `public/.htaccess` 加 `CGIPassAuth On`（Apache 預設吞掉 `Authorization` header）
2. `Request::header()` 改為 case-insensitive（修 `X-Device-ID` vs `X-Device-Id`）
3. `Request` 增加 `Content-Type` 從 `$_SERVER['CONTENT_TYPE']` 讀（mod_php 不會加 `HTTP_` 前綴）

**驗證**：`tests/e2e_devices.php` 8 步驟 ALL PASS。

---

### §4 Phase 4 — Target Agent (Python)

**目標**：在 Target VM 上跑的 Python 服務（127.0.0.1:8787），串接 Portal 與 Server。

**交付檔案**：
- `target/agent/src/{config,credential,server_api,local_db,zip_safe,manifest,installer,resetter,syncer,local_api,cli}.py`
- `target/agent/systemd/{ctf-agent.service,ctf-agent.timer,ctf-agent-sync.service}`
- `target/agent/requirements.txt`（requests + flask + pymysql + pytest）
- `target/install.sh`（建立 `/opt/ctf-agent`、`/etc/ctf-agent/config.json`、systemd 啟用、`ctf_agent`@`127.0.0.1` MariaDB user）

**核心防護**：
- `zip_safe.py`：Zip Slip / 絕對路徑 / symlink / oversize 全防
- `manifest.py`：schema_version=1，verification.type=flag 需 flag_static，type=automatic 需 automatic.script

**驗證**：`target/agent` pytest — **60 passed, 1 skipped**（POSIX-only chmod 測試在 Windows skip）。

---

### §5 Phase 5 — Task Session

**目標**：學生拿到 Task Token → 貼到 Target Portal → Server 驗證並綁定裝置。

**交付檔案**：
- `database/migrations/004_challenge_groups.sql`（補 §2 漏的 M:N 表）
- `src/Repositories/ChallengeRepository.php`（含 `listForStudent` 群組可見性查詢）
- `src/Repositories/TaskSessionRepository.php`（含 `bindDevice` idempotent + `expireOverdue`）
- `src/Services/TaskService.php`（`TASK-XXXX-XXXX-XXXX-XXXX`，base32 4 段 4 字元）
- `src/Services/TaskValidationException.php`（自訂 Exception 帶 code + HTTP status）
- `src/Controllers/TaskController.php`
- `src/Middleware/RateLimitTaskValidate.php`（10/min）
- `views/student/task/show.php`（4 張 stat card + 取消按鈕）

**修改**：`routes/web.php`、views/dashboard、bin/cleanup.php（加 task_sessions 過期標記）

**8 種錯誤碼**：`invalid_token` / `unknown_token` / `inactive` / `expired` / `device_inactive` / `owner_mismatch` / `device_mismatch` / `challenge_unavailable`（HTTP 400/401/403/410）

**驗證**：`tests/e2e_tasks.php` 9 步驟 ALL PASS。

---

### §6 Phase 6 — Flag 驗證 + Solve + Leaderboard

**目標**：學生交 Flag → Server 重算 HMAC → 第一次正確就記錄 solve、給分。

**交付檔案**：
- `src/Security/FlagGenerator.php`（`HMAC-SHA256(student_id + ":" + challenge_uuid + ":" + task_uuid, FLAG_MASTER_SECRET)`）
- `src/Security/ConstantTimeCompare.php`（`hash_equals` 包裝）
- `src/Repositories/{SubmissionRepository,SolveRepository,NonceRepository}.php`
- `src/Services/SubmissionService.php`（`verifyAndAward()` 共用於 browser + device）
- `src/Controllers/SubmissionController.php`

**修改**：`views/student/dashboard.php`（題目列表 + 進行中 Task + 真實 stats）、`views/student/task/show.php`（Flag 提交表單）、`BaseController::jsonOk` 加 status 參數。

**安全保證**：
- HMAC keyed by student_id（學生無法互用 flag）
- constant-time 比較
- DB unique key + tryCreate 捕 23000（race condition 防護）
- Nonce 一次性（10 min TTL，replay → 409）
- Device 不可決定 points（只送 task_id/flag/nonce）

**驗證**：`tests/e2e_flags.php` 9 步驟 ALL PASS。

---

### §7 Phase 7 — Target Portal (PHP UI)

**目標**：學生在 Target VM 開瀏覽器即可啟用、同步、貼 Token、reset。

**交付檔案**：
- `target/portal/public/index.php`（拒絕非 loopback + dispatch）
- `target/portal/src/{Router,View,AgentClient,PortalController}.php`
- `target/portal/views/{layout,home,activate,task}.php`（terminal 風格 + ink/brass/drafting）
- `target/portal/apache/ctf-portal.conf`（Listen 127.0.0.1:80 + disable_functions + open_basedir + Require ip 127.0.0.0/8）
- `target/install-portal.sh`
- `target/portal/README.md`

**Trust boundary**：
- Bind `127.0.0.1:80` only
- Apache `disable_functions = exec,passthru,popen,proc_open,shell_exec,system`
- `open_basedir = /var/www/ctf-target-portal:/tmp`
- 程式碼 grep guard（無 shell_exec / eval / backtick）

**驗證**：pytest — **18 passed**（8 個 static security tests + 10 個 runtime e2e with mock Agent + PHP built-in server）。

---

### §8 Demo Challenge + MVP

**目標**：跑通 CLAUDE.md §13 的 MVP 完整流程。

**交付檔案**：
- `challenge-example/DEMO-001/{manifest.json, web/index.php, setup.sql, README.md}`
- `challenge-example/build.py`（Python 跨平台 ZIP packager）
- `ctf-server/src/Security/ZipValidator.php`（Zip Slip / 絕對路徑 / symlink 防護）
- `ctf-server/src/Services/ChallengeManifestParser.php`（schema 驗證 + DB insert）
- `ctf-server/bin/seed-challenge.php`（CLI 取代 §2.4 upload UI）

**MVP 10 步驟 lifecycle**：
1. Admin 存在
2. Teacher 註冊 → admin 核准 → 登入
3. `bin/seed-challenge.php` 發布 DEMO-001
4. Student 註冊 + verify email + 登入
5. Dashboard 列出 DEMO-001 + Start 按鈕
6. Student 啟動 task → 拿 task_uuid
7. Server 算 HMAC flag → Student submit → +200
8. 錯 flag → submissions +1, no extra solve
9. Leaderboard 顯示 200 / 1 solves
10. Dashboard 反映 200 points

**驗證**：`tests/e2e_mvp.php` 10 步驟 — **🎉 MVP COMPLETE 🎉**

---

### §9 (在 WORKPLAN 為 §10) 安全 / 測試 / 部署

**目標**：15 條 invariants 自動驗證 + 完整文檔。

**交付檔案**：
- `src/Middleware/RateLimitFlagSubmit.php`（10/min）
- `tests/security_checklist.php`（自動跑 16 個 checks：A1 雙向 + A2–A15）
- `docs/SECURITY.md`（trust boundary + 15 invariants × defense-in-depth）
- `docs/API.md`（全部 REST endpoint 規格 + 錯誤碼）
- `docs/DEPLOYMENT.md`（Ubuntu 24.04 安裝指南）
- `README.md`（根目錄總覽）

**修改**：`routes/web.php` 為 flag submit 加 rate limit middleware。

**驗證**：`security_checklist.php` 顯示 **`16 PASS, 0 FAIL`**。

---

## 3. 全部交付檔案總數

| 類別 | 數量 | 位置 |
|---|---|---|
| PHP 檔案（Server） | ~50 | `ctf-server/src/`, `ctf-server/views/`, `ctf-server/bin/` |
| Python 檔案（Agent） | 11 | `target/agent/src/` |
| PHP 檔案（Portal） | 8 | `target/portal/{public,src,views}/` |
| SQL 遷移 | 4 | `ctf-server/database/migrations/` |
| Markdown 文檔 | 9 | `README.md`, `WORKPLAN.md`, `CLAUDE.md`, `docs/{API,SECURITY,DEPLOYMENT,PROGRESS}.md`, `challenge-example/DEMO-001/README.md`, `target/{agent,portal}/README.md` |
| Shell 安裝腳本 | 2 | `target/install.sh`, `target/install-portal.sh` |
| systemd units | 3 | `target/agent/systemd/` |
| Demo challenge | 1 | `challenge-example/DEMO-001/` |
| Apache vhost | 1 | `target/portal/apache/ctf-portal.conf` |
| 測試腳本 | 17 | `ctf-server/tests/e2e_*.php` (8) + `security_checklist.php` (1) + `target/agent/tests/test_*.py` (6) + `target/portal/tests/test_*.py` (2) |

---

## 4. 測試統計

| 套件 | 工具 | 數量 | 結果 |
|---|---|---|---|
| Server e2e（PHP + curl） | 自製 HTTP 客戶端 | 8 個 scripts，約 70 個步驟 | ALL PASS |
| Server security checklist | 純 PHP regex/grep | 16 checks（15 invariants + reverse） | ALL PASS |
| Agent pytest | pytest 9.x | 60 tests（1 POSIX-only skipped） | ALL PASS |
| Portal pytest | pytest 9.x + PHP built-in server | 18 tests（8 static + 10 e2e） | ALL PASS |
| **總計** | | **96 個測試** | **ALL PASS** |

### 各 e2e 覆蓋場景

| Script | 步驟 |
|---|---|
| `e2e_phase1.php` | register / login / CSRF / RateLimit / approve / leaderboard / logout |
| `e2e_password_reset.php` | 完整重設流程 + hash 變更 + 舊密碼拒絕 |
| `e2e_login_security.php` | CAPTCHA + 錯 captcha + 3-strike lockout + locked POST rejected |
| `e2e_groups.php` | create/join/wrong-code/remove/ban/regenerate/delete + CASCADE |
| `e2e_devices.php` | activate/info/heartbeat/wrong-token/reuse-code/revoke + token-hash-on-disk |
| `e2e_tasks.php` | start/bind-device/second-device-403/random-token-400/cancel/expire + audit |
| `e2e_flags.php` | first-solve/duplicate/wrong/cross-student/task-uuid/device-complete/replay/leaderboard |
| `e2e_mvp.php` | 10 步驟 MVP 全鏈 → ALL PASS — **MVP COMPLETE** |

---

## 5. 15 條 Invariants 驗證結果

| # | Invariant | 自動檢查 |
|---|---|---|
| A1 | CTF Server 與 Target VM 為兩個獨立部署單元 | ✅ 雙向 grep |
| A2 | Target 不直接連 Server MariaDB | ✅ 所有 local_db host = 127.0.0.1 |
| A3 | Server 不 require Target source | ✅ |
| A4 | Target 不 require Server source | ✅ |
| A5 | Target 不含 `FLAG_MASTER_SECRET` | ✅ grep clean |
| A6 | Score / Solve 只能由 CTF Server 決定 | ✅ SubmissionService 不從 `$_POST` 讀 points |
| A7 | Task Token ≠ Flag | ✅ 兩者 derive 機制獨立 |
| A8 | Device Token ≠ Student password | ✅ `random_bytes(32)` vs `PASSWORD_ARGON2ID` |
| A9 | Challenge Package 必須 versioned | ✅ manifest.json schema |
| A10 | ZIP 必須驗證 hash + extraction path | ✅ Server ZipValidator + Agent zip_safe |
| A11 | Target DB 與 Server DB 分離 | ✅ ctf_target + ctf_<id> vs ctf_server |
| A12 | Challenge DB 與 Target 管理 DB 分離 | ✅ Per-challenge DB via installer |
| A13 | Portal 禁用 system operation 函式 | ✅ Apache config + grep guard |
| A14 | 瀏覽器 state-changing 必須 CSRF | ✅ 所有非 device API POST 都帶 CSRF |
| A15 | Device API 使用 Bearer Token | ✅ DeviceAuth middleware |

---

## 6. 關鍵設計決策彙整

| 決策 | 理由 |
|---|---|
| 群組取代 student↔teacher 直接連結 | 一個老師可有多群組（不同班），學生可跨班加入 |
| 動態 flag via HMAC | 學生無法從 ZIP 找到真 flag；FLAG_MASTER_SECRET 只在 Server |
| Loopback-only Portal | 學生 VM 不對外暴露 web，攻擊面縮小 |
| disable_functions on Portal | 雙層防護（Apache config + grep test） |
| Server 自寫 Router，不用 framework | CLAUDE.md 禁止 framework；原生 PHP 足夠 60 條路由 |
| Task Token ≠ Flag | 任務結束後 token 失效，但 flag 仍可驗（HMAC idempotent for same inputs） |
| Device Token ≠ Student password | 防止 device.json 洩漏時密碼也洩漏 |
| Solves UNIQUE (student_id, challenge_id) | DB 層保證 race condition 安全 |
| Nonce on device task complete | 防 replay attack |
| bin/seed-challenge.php 取代 §2.4 UI | 完整 upload UI 工作量大；CLI 已涵蓋核心安全（ZipValidator + ManifestParser） |

---

## 7. 過程中修過的 bug 彙整

| Phase | Bug | 修法 |
|---|---|---|
| §1 | CSRF 419 fallback 成 500（Apache mod_php） | 改用 403 |
| §1 | Logger 不自動加 `.log` 副檔名 | bootstrap 加 dir/file 判斷 |
| §2 | `flashSuccess` 不存在於 GroupController | 從 AuthController 升級到 BaseController |
| §2 | step 4 用 `substr_count('CS-101')` 誤把 JS confirm 字串算進去 | 改用 `<strong>CS-101</strong>` 精確匹配 |
| §3 | Apache 吞掉 `Authorization` header | `.htaccess` 加 `CGIPassAuth On` |
| §3 | `X-Device-ID` vs `X-Device-Id` 大小寫 | `Request::header()` 改 case-insensitive |
| §3 | `Content-Type` 沒被讀到 | 手動從 `$_SERVER['CONTENT_TYPE']` 補 |
| §4 | test 裡用 `time() - 1` 字串優先序錯誤 | 改 `time() - 1` 先算再 cast |
| §4 | `_platform_default` keys 不一致 | 統一用 `_platform_default_paths()` |
| §4 | install_path 帶 `v{version}` 子目錄但測試期望覆蓋 | 改成 flat dir |
| §5 | `fetchOne()['id']` 在 null 上 array offset | 改用顯式 `if ($row)` |
| §5 | `InvalidArgumentException::$code` 不可訪問 | 改用自訂 `TaskValidationException` |
| §7 | `Router::getInstance()` 不存在 | 改 `Router::render()` static |
| §8 | `shell_exec('php ...')` 找不到 php | 用 `where php` 找絕對路徑 |
| §8 | `lastInsertId()` 回錯的 table | 從 closure 回傳 `$chId` |
| §8 | submit 用錯 task | 用 reissued task 確保 uuid 對齊 |

---

## 8. 後續可選工作（非 MVP 阻塞）

- **§2.4 Teacher Challenge Upload UI**：目前用 `bin/seed-challenge.php` CLI；UI 加上傳 form + ZIP upload + validation 是 Phase 2 完整化項目
- **§10.3 PHPUnit 改寫**：目前用自製 e2e PHP 腳本，架構完整但不是 PHPUnit 框架
- **Ubuntu VM 整合測試**：`target/install.sh` + `install-portal.sh` 在 Linux 跑通 systemd + Apache vhost
- **Target VM 防火牆規則 UF**：`docs/DEPLOYMENT.md` 已寫但需在 Linux 上實測
- **Demo Challenge 擴充**：DEMO-002 web SQLi、DEMO-003 crypto、DEMO-004 reverse 等更多類型
- **CI pipeline**：把 96 個測試串到 GitHub Actions

---

## 9. 文件清單（供查閱）

| 文件 | 內容 |
|---|---|
| `README.md` | 專案總覽 + quick start |
| `WORKPLAN.md` | 全部 phase 的設計 + checklist + 進度日誌 |
| `CLAUDE.md` | 專案開發指令 + 15 條 invariants 原文 |
| `docs/PROGRESS.md` | **本檔** — §0–§10 完整工作成果 |
| `docs/API.md` | REST API 規格（60+ endpoints） |
| `docs/SECURITY.md` | Trust boundary + defense-in-depth 詳細說明 |
| `docs/DEPLOYMENT.md` | Ubuntu 24.04 部署步驟 |
| `ctf-server/README.md` | Server 模組說明 |
| `target/agent/README.md` | Agent 安裝與使用 |
| `target/portal/README.md` | Portal trust boundary + routes |
| `challenge-example/DEMO-001/README.md` | Demo 題的學生 + 老師流程 |

---

## 10. 結語

從 Foundation 到 MVP COMPLETE + 安全審查 + 文檔化，§0–§10 共 10 個階段、96 個測試 ALL PASS、16 條 invariants 自動驗證通過。

平台已可在 dev 環境（Windows）跑完整 demo 流程，正式上線只差 Linux VM 的 systemd 整合測試。

**🎉 MVP COMPLETE 🎉**
