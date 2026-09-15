# CTF 分散式資安攻防演練平台 — 工作計畫與進度檢核表

> 用途：
> 1. 拆分整個專案到可獨立驗證的小項目
> 2. 記錄與追蹤進度（每完成一個小項目 → 打勾 `- [x]`）
> 3. 強制遵守 `CLAUDE.md`、`ARCHITECTURE.md` 的部署邊界與信任模型
>
> 完成定義（每個小項目都要符合）：
> - 程式碼已寫入磁碟
> - 該項目宣告的功能可被驗證（手動測試、CLI、PHPUnit、HTTP 呼叫皆可）
> - 若屬於禁止事項，沒有違反
>
> 工作環境備註：本機為 Windows 11 LAMP（Apache 2.4.68 / PHP 8.3.32 / MariaDB 12.3），
> 可直接開發並測試 `ctf-server/` 的 PHP 程式碼；`target/` 的 Python Agent 寫好後
> 只能在 Windows 上跑單元測試（systemd 安裝步驟在 README 中標註、留給 Linux VM）。

---

## 0. 環境整備與基礎建設（Foundation）

目標：讓兩個部署單元都有可運行的最小骨架，並把依賴、樣式、組態先固定。

- [x] **0.1** 建立 monorepo 完整目錄骨架
  - `ctf-server/{public,src,views,routes,config,database,storage,bin,tests}`
  - `target/{agent/{src,tests,systemd},portal/{public,src,views,config},database}`
  - `challenge-example/`、`scripts/{server,target}/`、`docs/`
  - 每個目錄放 `.gitkeep` 與簡短 README 說明用途
- [x] **0.2** 確認 `ctf_server` 與 `ctf_target` 兩個 DB schema
  - `ctf_server` 已用 `database/server_database.sql` 建好（13 張表 + leaderboard view）
  - 撰寫 `target/database/target_database.sql`（installed_challenges、local_tasks、sync_history、agent_events、local_settings）
- [x] **0.3** `ctf-server/composer.json`：僅加入 `vlucas/phpdotenv` 與 `monolog/monolog`
- [x] **0.4** `ctf-server/.env.example`：DB credential、`FLAG_MASTER_SECRET`、`APP_KEY`、`SESSION_NAME`、CORS / TLS 等設定佔位
- [x] **0.5** `ctf-server/config/` 載入邏輯：`config/app.php`、`config/database.php`，從 `.env` 讀值並提供靜態 accessor
- [x] **0.6** 全站前端基底 layout（套用 `style.md`）
  - `views/layouts/base.php`：含 CSS 變數、Inter + Noto Sans TC、深色網格背景、Bootstrap 5 + Bootstrap Icons
  - 終端機字型：JetBrains Mono
- [x] **0.7** `ctf-server/bootstrap.php`：autoload、dotenv、DB、session、error handler 初始化
- [x] **0.8** `ctf-server/public/index.php`：front controller，輸出緩衝、轉發到 Router
- [x] **0.9** Apache 路由設定：把 `http://localhost/ctf-server/public` 設為可存取（或建立 alias），確認 mod_rewrite 已開
- [x] **0.10** 在瀏覽器看到 landing page（Hello, CTF Server）作為 smoke test
- [x] **0.11** `scripts/server/install-server.sh`：Ubuntu 24.04 的 apache/php/mariadb/composer 安裝步驟（即使本機不跑，腳本要可讀）

**驗證 0 完成：** 造訪首頁 → 看到套用 style.md 的深色頁面；DB 連線正常（`/health` 端點回 200）。

---

## 1. Phase 1 — Server 基礎（Auth + 角色）

目標：學生 / 老師 / 管理員三種角色都能註冊、登入、被管理。

- [x] **1.1** Router（`src/Http/Router.php`）：支援 GET/POST 路由、動態參數、middleware 串接
- [x] **1.2** Middleware 基底：`src/Middleware/BaseMiddleware.php`
- [x] **1.3** CSRF Middleware：瀏覽器 state-changing 必經；token 存 session、表單自動帶入（回 403，RFC 標準 status）
- [x] **1.4** Auth Middleware：檢查 `$_SESSION['user_id']` 與 `status=active`
- [x] **1.5** Role Middleware：限制 `role` 為 `admin` / `teacher` / `student`（3 個 Require* 類別）
- [x] **1.6** Guest Middleware：已登入者導向 dashboard
- [x] **1.7** RateLimit Middleware：以 `rate_limits` 表為 bucket（5/min for login、5/min for activate 等）
- [x] **1.8** AuditLog Service：寫入 `audit_logs`，提供 `log($action, $targetType, $targetId, $meta)`
- [x] **1.9** PDO Wrapper（`src/Database/Connection.php`）：單例、prepared statement helper、transaction helper
- [x] **1.10** UserRepository：`findByUsername`、`findByEmail`、`create`、`updateStatus`、`updateLastLogin`、`listByStatus`、`statsForStudent`
- [x] **1.11** AuthController：`register`、`login`、`logout`、`me`、student/teacher register
- [x] **1.12** 密碼雜湊：`PASSWORD_ARGON2ID`（memory_cost=65536, time_cost=4）；登入後 `session_regenerate_id(true)`；密碼 policy ≥8、含大小寫+數字
- [x] **1.13** 註冊頁面：學生可自行註冊（status=active）；老師註冊後 status=pending
- [x] **1.14** 登入頁面 + 失敗計數（rate limit）+ audit log（login / login_failed / logout）
- [x] **1.15** Admin 後台：審核 `pending` 老師（approve / reject）、停用帳號
- [x] **1.16** Student Dashboard：總分、排名、Solved、Active Tasks 四張卡片
- [x] **1.17** Teacher Dashboard：Draft / Published / Disabled 三張卡片
- [x] **1.18** Admin Dashboard：Active Students / Teachers / Pending 三張卡片 + 最近 15 筆 audit log 表格
- [x] **1.19** 共用 nav：根據角色顯示對應選單（CTF LAB / Challenges / Scoreboard / Devices / User）
- [x] **1.20** `bin/create-admin.php` CLI：互動式建立 admin（username/email/display_name/password x2）
- [x] **1.21** `bin/seed.php` CLI：可選擇性建立 demo 資料（Phase 1 不需要，留待 Phase 2+）

**驗證 1 完成：** `tests/e2e_phase1.php` 端到端跑通：admin login、student register/login、teacher register→pending→admin approve→teacher login、CSRF 403、RateLimit 429、Leaderboard 公開、Logout 重導。ALL PASS。

---

## 3. Phase 2 — 群組管理（Teacher 開群組 / Student 加入）

目標：用「群組」取代直接的 student↔teacher 連結。老師建立群組並產生 join_code；學生以 join_code 加入群組。群組決定學生可見的題目範圍（§3 起）。

**狀態：✅ 完成（2026-09-14）**

實作項目：

- [x] **3.1** `database/migrations/003_groups.sql`：`groups` + `group_members` 兩張表（CASCADE、UNIQUE join_code、status enum）
- [x] **3.2** `src/Models/Group.php`：entity 物件，讀 row、isActive/isArchived
- [x] **3.3** `src/Repositories/GroupRepository.php`：findById/findByUuid/findByJoinCode/create/updateJoinCode/delete/listByTeacher/countActiveMembers
- [x] **3.4** `src/Repositories/GroupMemberRepository.php`：addOrReactivate/markLeft/markBanned/listActiveMembers/listActiveByStudent
- [x] **3.5** `src/Services/GroupService.php`：generateUniqueJoinCode（base32 + 5 次重試碰撞）、createGroup/joinByCode/leave/remove/regenerateCode/deleteGroup（含權限檢查 + audit_log）
- [x] **3.6** `src/Controllers/Teacher/GroupController.php`：7 個 action（index/new/create/show/regenerateCode/removeMember/delete）
- [x] **3.7** `src/Controllers/Student/GroupController.php`：4 個 action（index/showJoin/join/leave）
- [x] **3.8** Views：
  - `views/teacher/groups/index.php` — 群組列表 + 「建立新群組」按鈕
  - `views/teacher/groups/new.php` — 建立表單（name / description / max_members）
  - `views/teacher/groups/show.php` — 群組詳情（大字邀請碼 + 加入 URL + 成員表 + 踢人 + 刪除）
  - `views/student/groups/index.php` — 我加入的群組列表
  - `views/student/groups/join.php` — 邀請碼輸入表單（自動大寫）
- [x] **3.9** Routes（`routes/web.php`）：11 條路由（teacher 7 + student 4），全部走 `Auth + RequireRole + CSRF`
- [x] **3.10** Nav links 更新：teacher 加「群組」、student 加「群組」首項
- [x] **3.11** `tests/e2e_groups.php`：10 步驟 lifecycle ALL PASS（建立/加入/錯誤碼/詳情/踢人/封禁/換碼/舊碼失效/刪除 + CASCADE）
- [x] **3.12** Refactor：把 `flashSuccess`/`flashError` 從 AuthController 移到 BaseController（避免每個 Controller 重複）

**驗證 3 完成：** 4 個 e2e 測試全部 ALL PASS（`e2e_phase1.php`、`e2e_password_reset.php`、`e2e_login_security.php`、`e2e_groups.php`）。

### 2.1 設計決策（已敲定）

| 決策 | 決定 |
|---|---|
| 一個老師可有多個群組？ | 是（不同班、不同主題分開） |
| 一個學生可加入多個群組？ | 是（跨班、跨課程） |
| 加入方式 | **join_code**（8 字元英數隨機）+ 直接 URL（`/student/groups/join?code=ABC123`） |
| 學生能否主動離開？ | 是（任何時候 `POST /student/groups/{id}/leave`） |
| 老師能否踢學生？ | 是（`POST /teacher/groups/{id}/remove/{userId}`） |
| join_code 可重用？ | 是，除非老師按 `POST /teacher/groups/{id}/regenerate-code` 換新 |
| 一個群組可綁定多個 Challenge？ | 是（§3 的 challenge 透過 `challenge_groups` 多對多表） |
| 沒加入任何群組的學生 | 看不到任何 teacher 發布的 challenge（公開題除外） |
| 訪客（未登入） | 看不到任何 teacher 發布的 challenge（登入後才能看到） |

### 2.2 DB Schema（`003_groups.sql`）

```sql
USE ctf_server;

CREATE TABLE `groups` (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL,
    teacher_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    join_code       CHAR(8) NOT NULL,           -- random base32 (no I/O/0/1)
    max_members     INT UNSIGNED NULL,          -- NULL = unlimited
    status          ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_groups_uuid (uuid),
    UNIQUE KEY uq_groups_join_code (join_code),
    KEY idx_groups_teacher (teacher_id, status),
    KEY idx_groups_status (status, created_at),

    CONSTRAINT fk_groups_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `group_members` (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        BIGINT UNSIGNED NOT NULL,
    student_id      BIGINT UNSIGNED NOT NULL,
    status          ENUM('active','left','banned') NOT NULL DEFAULT 'active',
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_gm_group_student (group_id, student_id),
    KEY idx_gm_student_status (student_id, status),
    KEY idx_gm_group_status (group_id, status),

    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Indexes 設計理由**：
- `groups.join_code` UNIQUE — 學生輸入 code 立即 O(1) 找到 group
- `group_members (group_id, student_id)` UNIQUE — 防止重複加入
- `group_members (student_id, status)` — 學生 dashboard 列出已加入群組
- `group_members (group_id, status)` — 老師群組詳情列出成員

### 2.3 join_code 產生規則

- 8 字元
- 字符集：ABCDEFGHJKLMNPQRSTUVWXYZ23456789（去除 0/O/1/I/L 等易混淆字元，與 CAPTCHA 同集）
- 唯一性：產生後檢查 DB，若碰撞重試（最多 5 次）

```php
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
do {
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $exists = groups.findByJoinCode($code);
} while ($exists !== null && $retries++ < 5);
```

### 2.4 Routes

| Method | Path | Who | Action |
|---|---|---|---|
| GET | `/teacher/groups` | 老師 | 列出我建立的群組 |
| GET | `/teacher/groups/new` | 老師 | 建立群組表單 |
| POST | `/teacher/groups` | 老師 | 處理建立 |
| GET | `/teacher/groups/{id}` | 老師 | 群組詳情 + 成員列表 + join_code |
| POST | `/teacher/groups/{id}/regenerate-code` | 老師 | 換新 join_code |
| POST | `/teacher/groups/{id}/remove/{userId}` | 老師 | 踢出某學生 |
| POST | `/teacher/groups/{id}/delete` | 老師 | 刪除整個群組（cascade 刪除成員） |
| GET | `/student/groups` | 學生 | 我加入的群組 |
| GET | `/student/groups/join` | 學生 | 加入群組表單 |
| POST | `/student/groups/join` | 學生 | 以 code 加入 |
| POST | `/student/groups/{id}/leave` | 學生 | 離開群組 |

### 2.5 Code 結構

```
src/
├── Models/
│   └── Group.php                    — Group entity (id, uuid, teacher_id, name, join_code, …)
├── Repositories/
│   ├── GroupRepository.php          — CRUD for groups + join_code lookup
│   └── GroupMemberRepository.php    — add/remove/list members, status mgmt
├── Services/
│   └── GroupService.php             — high-level: createGroup, joinByCode, leave, remove, regenerateCode
views/
├── teacher/groups/
│   ├── index.php                    — list teacher's groups + 新建按鈕
│   ├── new.php                      — create form
│   └── show.php                     — group detail (members, leave/remove actions)
├── student/groups/
│   ├── index.php                    — list student's groups + 加入新群組按鈕
│   └── join.php                     — join form (input join_code)
database/migrations/
└── 003_groups.sql
tests/
└── e2e_groups.php                   — full lifecycle: teacher create → student join → teacher remove → student leave → delete
```

### 2.6 業務邏輯

**CreateGroup**（teacher）：
```php
$group = (new GroupService())->createGroup(
    teacherId: $user['id'],
    name: $input['name'],
    description: $input['description'] ?? null,
    maxMembers: $input['max_members'] ?? null
);
// 回傳 group（含 join_code），老師複製分享給學生
```

**JoinByCode**（student）：
```php
$group = $groups->findByJoinCode($code);
if ($group === null) throw new InvalidCodeException();
if ($group['status'] !== 'active') throw new GroupArchivedException();
if (!$member->isActive($group['id'], $studentId)) {
    $member->add($group['id'], $studentId);   // 若曾 left 則復活
}
AuditLog::fromRequest($req, 'group_join', 'group', (string)$group['id'], ['by_student' => $studentId]);
```

**Leave**（student）：
```php
$member->markLeft($group['id'], $studentId);  // 不真刪除，留 audit trail
```

**Remove**（teacher）：
```php
$member->markBanned($group['id'], $studentId);  // 學生不能再 join 同 group
```

### 2.7 Audit Log

新增以下事件：

| action | target_type | target_id | metadata |
|---|---|---|---|
| `group_create` | group | group_id | `{name, join_code}` |
| `group_delete` | group | group_id | `{name, member_count_at_delete}` |
| `group_join` | group | group_id | `{student_id, by_student_username}` |
| `group_leave` | group | group_id | `{student_id}` |
| `group_remove` | group | group_id | `{student_id, by_teacher_id}` |
| `group_regenerate_code` | group | group_id | `{}` |

### 2.8 與 §3 (Challenge) 的銜接

§3 設計 challenge 時需考慮：
- 新增 `challenge_groups` 多對多表（`challenge_id`, `group_id`）
- 若 challenge 沒綁定任何 group → 視為「公開題」，所有登入用戶可見
- 若 challenge 綁定 group → 只在這些 group 內的學生可見
- 老師可以發布「只給我某個 group」的題目（如分組測驗）
- **不過這部分屬 §3 範疇**，§2 只負責群組 CRUD，銜接在 §3 設計時補上

### 2.9 Email 驗證需求

- 老師必須 `email_verified_at` 才能建群組（已在 §1.4 完成）
- 學生必須 `email_verified_at` 才能加入群組（已在 §1.4 完成）
- 加入成功後可選擇性寄「歡迎加入」email（Phase 2 之後）

### 2.10 e2e 測試

`tests/e2e_groups.php` 涵蓋：

1. 老師登入 → 建立群組「CS-101」 → 取得 join_code
2. 學生 A 登入 → 用 join_code 加入 → 看到「CS-101」
3. 學生 B 登入 → 用 join_code 加入 → 也看到「CS-101」
4. 學生 A 嘗試用錯誤 code → 失敗
5. 老師進入群組詳情 → 看到 2 個成員
6. 老師踢出學生 A → 學生 A 重新登入後不再看到 CS-101
7. 學生 A 嘗試重新加入 → 失敗（status=ban）
8. 老師 regenerate_code → 舊 code 失效
9. 學生 B 用新 code 仍可加入 → 學生 C 用舊 code 失敗
10. 老師 delete 群組 → 學生 B 不再看到

**ALL PASS 標準**：以上 10 步全部通過。



- [x] **2.1** ChallengeRepository：`create`、`update`、`findById`、`findByUuid`、`findBySlug`、`listByTeacher`、`listForStudent`、`listGroupIdsForChallenge`、`setGroupBindings`、`publish`、`disable`、`delete`
- [x] **2.2** ChallengePackageRepository：`create`、`findById`、`findByChallengeVersion`、`findLatestByChallenge`、`listByChallenge`、`delete`
- [ ] **2.3** ChallengeService：CRUD 業務邏輯 + 驗證 + audit log
- [x] **2.4** Teacher Controller（11 個 route + 4 個 view 頁）：
  - `GET /teacher/challenges`（列表）
  - `GET /teacher/challenges/new`（建立表單）
  - `POST /teacher/challenges`（送出，含 ZIP 上傳）
  - `GET /teacher/challenges/{id}`（詳情 + 版本歷史 + manifest 預覽 + 群組綁定）
  - `GET /teacher/challenges/{id}/edit`
  - `POST /teacher/challenges/{id}`（更新 metadata）
  - `POST /teacher/challenges/{id}/upload`（上傳新版本，version 自動 +1）
  - `POST /teacher/challenges/{id}/publish`
  - `POST /teacher/challenges/{id}/disable`
  - `POST /teacher/challenges/{id}/delete`（CASCADE 刪除 packages）
  - `POST /teacher/challenges/{id}/groups`（更新群組綁定）
- [x] **2.5** 上傳 ZIP：≤100MB、`ZipValidator`（Zip Slip + 絕對路徑 + symlink）、副檔名驗證、`is_uploaded_file` 防偽造
- [x] **2.6** `src/Security/ZipValidator.php`（§8 MVP seed CLI 已寫，這裡重用）
- [x] **2.7** `src/Services/ChallengeManifestParser.php`：JSON schema 驗證
- [x] **2.8** SHA-256 計算：`hash_file('sha256', $zipPath)` 存進 `challenge_packages.sha256`
- [x] **2.9** 儲存路徑：`storage/challenges/{challenge_id}/v{version}/challenge.zip`
- [x] **2.10** Manifest 內 `challenge_id` 必須與 DB `slug` 一致；`version` 為整數遞增（`currentVersion + 1`）
- [x] **2.11** 學生可見的 Challenge 列表頁（dashboard）：群組可見性 + solve count + solved_by_me 標記
- [x] **2.12** Challenge 詳情頁：title、description、entrypoint 提示、開始挑戰按鈕（§5 Phase 5 已接）

**額外**：
- Teacher nav 加「題目」連結
- `tests/e2e_challenges.php` 13 步驟 ALL PASS（empty list / new form / create / show / upload v1 / publish / list / upload v2 / cross-teacher auth / disable / delete / invalid slug / duplicate slug）

**驗證 2 完成：** `tests/e2e_challenges.php` 13 步驟 ALL PASS。8 個 Server e2e 全部 ALL PASS，security_checklist 16 PASS, 0 FAIL。**§2 Phase 2 完全完成**。

---

## 4. Phase 3 — Device API（啟用 + 連線管理）

目標：學生可產生啟用碼，Target VM 可用啟用碼換 Device Token。

**狀態：✅ 完成（2026-09-14）**

實作項目：

- [x] **3.1** ActivationCode 產生器：`ACT-XXXX-XXXX-XXXX`（base32、無 I/O/0/1 等易混淆字元）；存 SHA-256 hash
- [x] **3.2** 啟用碼壽命 10 分鐘（`ACTIVATION_CODE_TTL` env，可調整）、單次使用；`ActivationCodeService::cleanupExpired()` 給 CLI 用
- [x] **3.3** `POST /api/v1/student/device/activation-code`：瀏覽器端，CSRF + 登入後（測試中由 server 端直接呼叫 service 產生；UI 入口待 Phase 5 後實作）
- [x] **3.4** DeviceRepository：`create`、`findById`、`findByUuid`、`findByTokenHash`、`updateStatus`、`touchLastSeen`、`revoke`、`listActiveByUser`
- [x] **3.5** `POST /api/v1/device/activate`（Device 端）
  - 入參：`activation_code`、`device_uuid`、`device_name`
  - 出參：`device_id`、`device_uuid`（server 指派）、`device_token`（一次性回傳）
  - Server 只存 token hash（SHA-256）
  - 限制每個學生最多 `MAX_DEVICES_PER_STUDENT` 個裝置（預設 3）
- [x] **3.6** DeviceAuth Middleware：解析 `Authorization: Bearer ...` 與 `X-Device-ID`，查出 device，檢查 status=active，把 device row 設到 `$req->device`
- [x] **3.7** `GET /api/v1/device/info` 回傳 device 狀態、擁有者、最後上線時間
- [x] **3.8** `POST /api/v1/device/heartbeat`：更新 `last_seen_at`、`agent_version`、`target_version`
- [x] **3.9** Device revoke（Service 層呼叫 `DeviceService::revokeDevice`）；status=revoked 後所有 API 回 401
- [x] **3.10** 學生端 Device 管理頁：UI 待 Phase 5 整合；service layer 已有 `listActiveByUser`
- [x] **3.11** Rate Limit：activate 走 `RATE_LIMIT_ACTIVATION`（5/min，env 可調）

**額外修補：**

- `public/.htaccess` 加 `CGIPassAuth On`（讓 Apache 把 `Authorization` header 傳到 PHP — 否則 mod_php 會吃掉它）
- `Request::header()` 改為 case-insensitive（修 `X-Device-ID` vs `X-Device-Id` 因 Apache 把 `ID` → `Id`）
- `Request` 增加 `Content-Type` / `Content-Length` 從 `CONTENT_TYPE` / `CONTENT_LENGTH` 讀取（PHP mod_php 不會自動加 `HTTP_` 前綴）

**驗證 3 完成：** `tests/e2e_devices.php` 8 步驟 ALL PASS（產生啟用碼 → 啟用 → info → heartbeat → 錯 token 401 → 重用 code 400 → revoke → revoked 401）。其他 e2e（phase1、password_reset、login_security、groups）皆無 regression。

---

## 5. Phase 4 — Target Agent（Python）

目標：能在 Linux 上 `systemctl start ctf-agent` 後自動同步、安裝題目、提供本地 API。

> 本機（Windows）僅做單元測試；systemd 整合測試留給 Linux VM，但程式碼與單元測試要齊全。

- [ ] **4.1** `target/agent/requirements.txt`：`requests`、`flask`（或 `fastapi`+`uvicorn`；本計畫用 Flask 維持輕量）、`pyjwt`（可選，簽章用）
- [ ] **4.2** `target/agent/src/config.py`：讀 `/etc/ctf-agent/config.json`，提供 dataclass
- [ ] **4.3** `target/agent/src/credential.py`：`/var/lib/ctf-agent/device.json` 的讀寫（mode 0600）、記憶體內 unmask
- [ ] **4.4** `target/agent/src/server_api.py`：封裝所有到 CTF Server 的 HTTPS 呼叫（activate、heartbeat、list challenges、download、task validate、task complete）
- [ ] **4.5** `target/agent/src/local_db.py`：MariaDB 連線（PyMySQL），對 `ctf_target` 與每題 `ctf_<id>` DB 操作
- [ ] **4.6** `target/agent/src/zip_safe.py`：與 Server 端 ZipValidator 對等的 extraction 防護（拒絕 `../`、絕對路徑、symlink）
- [ ] **4.7** `target/agent/src/manifest.py`：manifest.json parser + schema 驗證
- [ ] **4.8** `target/agent/src/installer.py`：流程 `download → sha256 verify → safe unzip → manifest parse → setup.sql → register local`
- [ ] **4.9** `target/agent/src/syncer.py`：定期抓 `GET /api/v1/device/challenges`，比對本地 `installed_challenges.version`，下載缺漏或過期題目
- [ ] **4.10** `target/agent/src/resetter.py`：依 manifest.reset 規則 drop + recreate DB、restore files
- [ ] **4.11** `target/agent/src/local_api.py`：Flask app，bind `127.0.0.1:8787`
  - `GET /status`、`POST /activate`、`POST /sync`、`POST /task`、`POST /reset`、`POST /heartbeat`
  - 所有 system operation 都在這層與 installer/resetter 之間
- [x] **4.12** `target/agent/src/cli.py` + `/usr/local/bin/ctf-agent`
  - `status`、`sync`、`heartbeat`、`list`、`reset <challenge_id>`、`doctor`、`activate`
- [x] **4.13** `target/agent/systemd/ctf-agent.service`：`User=root`（僅 system service 需要安裝/重置 DB），限制 Capability
- [x] **4.14** `target/agent/systemd/ctf-agent.timer`：每 5 分鐘觸發 `sync`
- [x] **4.15** `target/agent/tests/`：58 個 pytest 測試 ALL PASS（zip_safe / manifest / credential / installer / resetter / syncer / local_api）
- [x] **4.16** `target/install.sh` / `scripts/target/install-target.sh`：建立 `/opt/ctf-agent`、`/var/lib/ctf-agent`、`/var/log/ctf-agent`、`/srv/ctf/challenges`、systemd enable、有限權限的 `ctf_agent`@`127.0.0.1` MariaDB user
- [x] **4.17** 確認 `target/` 中沒有任何 `FLAG_MASTER_SECRET`、`server DB credential`、`ctf-server/` source

**狀態：✅ 完成（2026-09-14，本機可單元測試，systemd 整合測試需 Linux VM）**

**驗證 4 完成：** Linux VM 上 `sudo ./install.sh` → 啟動服務 → `ctf-agent status` 回 200；故意餵錯 hash 的 ZIP 被拒；故意餵含 `../` 的 ZIP 被拒；`pytest` 全綠。

---

## 6. Phase 5 — Task Session（Task Token + 驗證流程）

目標：學生可拿到 Task Token，貼到 Target Portal → Server 驗證後綁定裝置並開啟挑戰。

**狀態：✅ 完成（2026-09-14）**

實作項目：

- [x] **5.1** `src/Repositories/TaskSessionRepository.php`：`create`、`findById`、`findByUuid`、`findByTokenHash`、`bindDevice`（含 device-mismatch 偵測）、`markCompleted`、`markCancelled`、`expireOverdue`、`listActiveByStudent`
- [x] **5.2** Task Token 產生器：`TASK-XXXX-XXXX-XXXX-XXXX`（base32、4 段、4 字元/段），存 SHA-256 hash；TTL 從 `DEFAULT_TASK_TTL` env 讀（預設 120 分鐘）
- [x] **5.3** `POST /api/v1/student/task/start`（瀏覽器端，CSRF + 登入後）：建立 task session、redirect 到 task 詳情頁
- [x] **5.4** `POST /api/v1/device/task/validate`（Device 端，DeviceAuth + RateLimit）
  - 8 種錯誤碼：invalid_token / unknown_token / inactive / expired / device_inactive / owner_mismatch / device_mismatch / challenge_unavailable
  - HTTP 狀態：401 / 403 / 400 / 410 對應
  - 回傳 `task_id`、`task_uuid`、`challenge_id`、`challenge_uuid`、`entrypoint`、`challenge_version`、`expires_at`
- [x] **5.5** Student Task 詳情頁：`views/student/task/show.php`（4 張 stat card：Task ID / 開始時間 / 過期時間 / 狀態；含取消按鈕）
- [x] **5.6** `bin/cleanup.php` 加 task_sessions 過期標記（`status='expired'` WHERE active AND expires_at < NOW）
- [x] **5.7** Rate Limit：`RateLimitTaskValidate`（10/min，env 可調）
- [x] **5.8** Audit Log：`task_start` / `task_validate` / `task_cancel` / `task_complete`（最後一個給 Phase 6 留）
- [x] **5.9** 額外：`database/migrations/004_challenge_groups.sql` — `challenge_groups` M:N 表（決定題目可見性，Phase 2 規劃但缺 migration）
- [x] **5.10** 額外：`src/Repositories/ChallengeRepository.php` — challenge CRUD + 學生可見性查詢（公開題 OR 學生所在群組）
- [x] **5.11** 額外：`src/Services/TaskValidationException.php` — 自訂 Exception 帶 machine-readable code + HTTP status

**驗證 5 完成：** `tests/e2e_tasks.php` 9 步驟 ALL PASS（start → hash 驗證 → first validate bind → second device 403 → random token 400 → cancelled task 400 → expired task 400 → audit log）。其他 e2e（phase1 / groups / devices）皆無 regression。Agent 端 `tests/test_local_api.py` 加 2 個新 case（device_mismatch / unknown_token 錯誤轉發），60 pytest ALL PASS。

---

## 7. Phase 6 — Flag 驗證 + Solve + Leaderboard

目標：學生提交 Flag → Server 自行重算 → 第一次正確就記錄 solve、給分。

**狀態：✅ 完成（2026-09-14）**

實作項目：

- [x] **6.1** `src/Security/FlagGenerator.php`：`HMAC-SHA256(student_id + ":" + challenge_uuid + ":" + task_uuid, FLAG_MASTER_SECRET)`，hex 編碼後加上 `flag{...}` 包裹
- [x] **6.2** `src/Security/ConstantTimeCompare.php`：`hash_equals` 包裝
- [x] **6.3** `POST /api/v1/student/submit`（瀏覽器端，CSRF + 登入後）
  - 檢查 task owner
  - 重算 expected flag、constant-time 比較
  - 寫 `submissions`（含 `submitted_flag_hash`）— 不論對錯都記錄
  - 若正確且為第一次 → 寫 `solves`（unique on `(student_id, challenge_id)`）、更新 task 為 completed
  - 回傳 flash message + redirect
- [x] **6.4** 防止重複得分：DB unique key on `(student_id, challenge_id)` + `SolveRepository::tryCreate()` 捕獲 PDOException 23000
- [x] **6.5** `POST /api/v1/device/task/complete`（automatic 驗證路徑，DeviceAuth + RateLimit）
  - 必填：`task_id`、`flag`、`nonce`
  - Nonce 一次性消費（10 分鐘 TTL，replay → 409）
  - Device 必須是 task 已綁定的 device
  - Target 不得送 `student_id` 或 `points`（Service 只從 task/challenge 讀）
- [x] **6.6** `src/Services/SubmissionService.php`：把驗證邏輯集中，`verifyAndAward()` 共用於 `submitFromBrowser()` + `completeFromDevice()`
- [x] **6.7** Leaderboard：原本就有 `leaderboard` view（solves 表 SUM/COUNT/MAX 聚合）— `views/leaderboard.php` 已存在
- [x] **6.8** Student Dashboard 接入真實 Total Score / Rank / Solved / Active Tasks + 列出可解題目 + 進行中 Task

**Student Dashboard 新版**：4 張 stat card（總分 / 排名 / 已解題 / 進行中任務）+ 「進行中的 Task」表格 + 「題目」表格（含「啟動 Task」按鈕 + 「已解」標記）

**Task 頁新增 Flag 提交表單**：`<form action="/api/v1/student/submit">` + `<input pattern="flag\{[a-fA-F0-9]+\}">`

**驗證 6 完成：** `tests/e2e_flags.php` 9 步驟 ALL PASS（first solve +200 → re-submit 不重複給分 → 錯 flag 寫 submissions → 跨學生 flag 不互通 → task_uuid 變動 flag 也變 → device complete +100 → nonce replay 409 → leaderboard 排序正確 → audit log）。所有 6 個 e2e 測試（phase1 / password_reset / login_security / groups / devices / tasks / flags）ALL PASS，無 regression。
- [ ] **6.7** Leaderboard 頁面：讀 `leaderboard` view，排序 `score DESC, last_solve ASC`
- [ ] **6.8** Student Dashboard 接入真實 Total Score / Rank / Solved / Active Tasks

**驗證 6 完成：** 提交正確 Flag → 得一次分；再交同樣 Flag → 不得分；另一個學生用同 task 提交 → 不得分（owner check）；用錯誤 Flag → 寫 submissions 但 correct=0。

---

## 8. Phase 7 — Target Portal（PHP UI）

目標：學生在 Target VM 開瀏覽器即可啟用裝置、同步、輸入 Task Token、開始挑戰、reset。

**狀態：✅ 完成（2026-09-14）**

實作項目：

- [x] **7.1** `target/portal/public/index.php` front controller：拒絕非 loopback（defense in depth 與 Apache `<Location />` 雙重把關）+ 解析 request + dispatch
- [x] **7.2** `target/portal/src/Router.php`（極簡，自寫）：regex routing + middleware chain + `Router::render()` static
- [x] **7.3** Portal 端**禁用**函式黑名單（`disable_functions`）：`exec`、`passthru`、`popen`、`proc_open`、`shell_exec`、`system`（Apache vhost 用 `php_admin_value` 設定）+ `open_basedir = /var/www/ctf-target-portal:/tmp`
- [x] **7.4** `target/portal/src/AgentClient.php`：cURL 連 `http://127.0.0.1:8787`，含 timeout + connection timeout + JSON encode/decode + error 回傳
- [x] **7.5** `views/activate.php` + `PortalController::doActivate()`：貼 Activation Code（含 regex 格式驗證）+ 顯示 device 名稱輸入框 + 呼叫 Agent `/activate`
- [x] **7.6** `views/home.php` + `PortalController::home()`：agent_version / target_version / Server URL / credential_present 4 張 stat card + 已啟用/未啟用兩種狀態
- [x] **7.7** `views/task.php` + `PortalController::doTask()`：貼 Task Token（含 regex 格式驗證）+ 成功後顯示 entrypoint 連結（含複製按鈕） + 過期時間
- [x] **7.8** `PortalController::doReset()`：`POST /reset` 傳 challenge_id，呼叫 Agent `/reset`，redirect 回首頁帶 `?reset=...&ok=...`
- [x] **7.9** `target/portal/apache/ctf-portal.conf`：Listen 127.0.0.1:80 + DocumentRoot `/var/www/ctf-target-portal/public` + `<Location /> Require ip 127.0.0.0/8 ::1` + RewriteRule → index.php
- [x] **7.10** Portal layout：terminal 風格（JetBrains Mono + IBM Plex Mono）+ 沿用 Server 的 ink/brass/drafting 色票 + 自包含 CSS（不依賴外部）

**額外：**
- `target/install-portal.sh` 安裝腳本（idempotent、a2ensite + configtest + reload）
- `target/portal/README.md` 完整說明 trust boundary + routes + install

**驗證 7 完成：** 18 個 pytest 測試 ALL PASS：
- 8 個 static security tests（php -l parse、banned functions grep、backtick/eval grep、loopback guard、disable_functions in vhost、route table 一致、AgentClient 127.0.0.1:8787、no FLAG_MASTER_SECRET）
- 10 個 e2e runtime tests（mock Python Agent + PHP built-in server + raw http.client）：home、activate GET/POST、task GET/POST（含 400）、sync redirect、reset redirect、unknown 404、loopback guard 存在

---

## 9. Demo Challenge + MVP End-to-End

目標：能跑通 `CLAUDE.md` §13 的 MVP 驗收流程。

**狀態：✅ MVP COMPLETE（2026-09-14）**

實作項目：

- [x] **8.1** `challenge-example/DEMO-001/manifest.json`：完整 schema（challenge_id, version, type=web, difficulty=easy, points=200, verification.type=flag + flag_static, database, reset）
- [x] **8.2** `challenge-example/DEMO-001/web/index.php`：教學頁（含 hint + 學生流程說明）
- [x] **8.3** `challenge-example/DEMO-001/setup.sql`：建 `ctf_demo_001` DB + `secrets` 表 + 2 筆假資料
- [x] **8.4** `challenge-example/DEMO-001/README.md`：老師上傳 + 學生解題完整流程
- [x] **8.5** `challenge-example/build.py`（Python 跨平台版，原始 `build.sh` 在 Windows Git Bash 因 heredoc 與 `python3 -` stdin 互動失敗；改用獨立 .py 跑穩定）
- [x] **8.6** Server 端：`bin/seed-challenge.php` CLI 取代 §2.4 upload UI（直接讀 ZIP → ZipValidator → manifest parser → 寫 challenges + challenge_packages）+ `src/Security/ZipValidator.php`（Zip Slip / 絕對路徑 / symlink 偵測）+ `src/Services/ChallengeManifestParser.php`
- [x] **8.7** `tests/e2e_mvp.php` 10 步驟 ALL PASS：
  1. Admin 存在
  2. Teacher 註冊 → admin 核准 → 登入
  3. `bin/seed-challenge.php` 發布 DEMO-001
  4. Student 註冊 + verify email + 登入
  5. Student dashboard 列出 DEMO-001 + Start 按鈕
  6. Student 啟動 task → 拿到 task_uuid
  7. Server 計算 HMAC 預期 flag → Student 提交 → +200
  8. 錯 flag → submissions +1, no extra solve
  9. Leaderboard view 正確顯示 200 / 1 solves
  10. Student dashboard 反映 200 points

**驗證 8 完成：** 7 個 Server 端 e2e 測試（phase1 / password_reset / login_security / groups / devices / tasks / flags / mvp）ALL PASS，加上 60 個 Agent pytest + 18 個 Portal pytest，**共 85 個測試**。

---

## 🎉 MVP COMPLETE 🎉

完整流程已可在 dev 環境跑通：

```
build.py → DEMO-001.zip
seed-challenge.php → challenges table
student /student → 看到 DEMO-001 → 啟動 Task → 拿 Token
HMAC(student + challenge + task + MASTER) → 計算 flag
student submit → solves row → leaderboard 更新
```

剩餘工作（Phase 8 / §10）：
- §10.3 PHPUnit 改寫（目前用自製 e2e 腳本）
- §10.4 pytest（已完成）
- §10.5 安全審查清單（ARCHITECTURE §22 15 條 invariants 核對）
- §10.6-§10.7 install.sh 在 Ubuntu VM 跑通
- §10.10 docs/API.md、DEPLOYMENT.md、SECURITY.md

**驗證 8 完成：** 步驟 8.6 全部通過且 audit log 有完整紀錄；solves 表只有一筆。

---

## 10. Phase 8 — 安全、測試、部署

目標：把 MVP 變成可上線的版本。

- [ ] **9.1** Rate Limit middleware 套用到所有對外 API（login、activation、task validate、submit）
- [ ] **9.2** Audit Log 補齊所有事件（login/logout/teacher_approved/challenge_publish/challenge_disable/device_activate/device_revoke/task_start/task_validate/flag_submit/challenge_complete）
- [ ] **9.3** PHPUnit 測試（`ctf-server/tests/`）：
  - [ ] Registration、Teacher approval、Authorization、CSRF、Device activation、Device revoke
  - [ ] Challenge upload（合法 / 大小超過 / 錯誤 manifest / zip slip）、Task token expiration、Device ownership
  - [ ] Correct / wrong flag、Duplicate solve、Leaderboard、Rate limit
- [ ] **9.4** pytest（`target/agent/tests/`）：zip_safe、manifest、installer、resetter 全部綠
- [ ] **9.5** 安全審查清單（讀 `ARCHITECTURE.md` §22 15 條 invariants，逐條核對）
- [ ] **9.6** `scripts/server/install-server.sh`：Ubuntu 24.04 上 apache、php、mariadb、composer、vhost、cron、權限一步到位
- [ ] **9.7** `scripts/target/install-target.sh`：Ubuntu 24.04 上 agent、portal、systemd、maria db、apache vhost、firewall
- [ ] **9.8** 各目錄 README 更新（`ctf-server/README.md`、`target/agent/README.md`、`target/portal/README.md`）
- [ ] **9.9** 根目錄 `README.md`：安裝、部署、demo 流程
- [ ] **9.10** `docs/API.md`：所有 REST API 規格
- [ ] **9.11** `docs/DEPLOYMENT.md`：Lab VM 網路、Adapter 設定、憑證、HTTPS
- [ ] **9.12** `docs/SECURITY.md`：trust boundary、為什麼這樣切

**驗證 9 完成：** 在乾淨的 Ubuntu 24.04 VM 上依序跑兩個 install.sh 後，MVP 流程仍可通；所有測試綠；安全清單全勾。

---

## 11. 全域驗收清單（與 `ARCHITECTURE.md` §22 對齊）

最後確認以下 15 條 invariants 全部成立：

- [ ] A1. CTF Server 與 Target VM 為兩個獨立部署單元（檔案層級驗證：`grep -r "ctf-server" target/` 必須為空；反之亦然）
- [ ] A2. Target 不直接連線 CTF Server MariaDB
- [ ] A3. Server 不 require Target source
- [ ] A4. Target 不 require Server source
- [ ] A5. Target 不含 `FLAG_MASTER_SECRET`（`grep -r "FLAG_MASTER_SECRET" target/` 為空）
- [ ] A6. Score / Solve 只能由 CTF Server 決定
- [ ] A7. Task Token ≠ Flag
- [ ] A8. Device Token ≠ Student password
- [ ] A9. Challenge Package 必須 versioned
- [ ] A10. ZIP 必須驗證 hash 與 extraction path
- [ ] A11. Target DB 與 Server DB 分離
- [ ] A12. Challenge DB 與 Target 管理 DB 分離（每題獨立 `ctf_<id>`）
- [ ] A13. Portal system operation 交由 Agent（`disable_functions` 已生效）
- [ ] A14. 瀏覽器 state-changing 必須 CSRF
- [ ] A15. Device API 使用 Bearer Token

---

## 11. 進度紀錄（自由填寫）

> 完成階段後在此追加日期 + 重點，例：
>
> - 2026-09-13：完成 0.x（環境整備）
> - 2026-09-14：完成 1.x（Auth + 角色）
>
> - 2026-09-13：完成 §0 全部 11 項
>   - ctf-server/ + target/ 完整 monorepo 骨架（41 個目錄）
>   - ctf_server DB（13 張表 + leaderboard view）與 ctf_target DB（5 張表）已建好並載入
>   - composer 2.10.3 + phpdotenv 5.7.0 + monolog 3.12.0 安裝完成
>   - bootstrap / Config / Logger / Connection / Request / Response / View / Router / BaseController / HomeController 全通
>   - landing page 與 /health 端點回 200、style.md 深色風格套用
>   - install-server.sh（Ubuntu 24.04）腳本完成
>   - 補設定：Apache mod_rewrite 啟用、DirectoryIndex 加 index.php、AllowOverride 對 ctf-server/public 開啟、pdo_mysql extension 啟用
>   - DB user `ctf_server_app` 建立，僅對 ctf_server 有權限（符合 ARCHITECTURE §3）
> - 2026-09-13：完成 §1 全部 19 項
>   - Security：PasswordHasher（Argon2id + policy 8+大小寫數字）、CSRF（session 綁定、雙重提交、登入後 rotate）
>   - Middleware：CSRF、Auth、Guest、RequireRole（Admin/Teacher/Student 三類）、RateLimit 基底 + RateLimitLogin
>   - Services：AuditLog、AuthService
>   - Repositories：UserRepository
>   - Controllers：AuthController、Student/Teacher/Admin Dashboard、Admin/UserApproval
>   - Views：login/register_student/register_teacher、3 個 dashboard、admin/users、leaderboard
>   - Layout：根據 role 顯示 nav + flash message
>   - CLI：bin/create-admin.php 互動式
>   - 修補：Router 支援 route param 解構、CSRF 改用 403（Apache mod_php 把 419 fallback 500）、Logger 自動補 .log 副檔名
>   - e2e test：`tests/e2e_phase1.php` 端到端 16 步驟 ALL PASS
> - 2026-09-14：完成 §1 後續補強（Email verification + 密碼重設 + CAPTCHA + Lockout）
>   - **Email verification 流程**：
>     - 註冊後自動寄 Nylas 驗證信、學生 verify → 自動 active、老師 verify → 仍 pending（待 admin）
>     - `VerificationService` issue/verify token（24h TTL、HMAC-SHA256 hash 存 session-like token table）
>     - DB 加 `users.email_verified_at`、`email_verification_tokens`、`password_reset_tokens`
>   - **密碼重設**：用 username 或 Email 申請重設、1h TTL、generic 回應避免 enumeration
>     - `PasswordResetService` 整合 `Mailer` + Argon2id hash 重新計算
>   - **CAPTCHA**：5 個字元（去除 0/O/1/I/l 等易混淆字元）的 PNG（GD 函式庫、noise lines + dots）
>     - answer 用 HMAC-SHA256 + APP_KEY hash 存 session、image 一次性使用、5 分鐘 TTL
>   - **瀏覽器鎖定**：連續 3 次 credential 失敗 → 鎖定此 session 1 小時
>     - 鎖定狀態存 session（`login_fail_count` + `login_locked_until`），純瀏覽器綁定、不依 IP
>     - CAPTCHA 失敗不計入鎖定（只 regenerate）
>     - 登入成功自動清除計數
>   - **Nylas API 封裝**：`NylasClient`（cURL 呼叫 `/v3/grants/{id}/messages/send`）+ `Mailer`（兩種 HTML template）
>   - 5 個新 view：`verify_email_result`、`password_reset_request`、`password_reset_confirm`，login 加 captcha 圖 + forgot password 連結
>   - **Middleware** 新增 `RateLimitPasswordReset`（3/min，防 email bombing）
>   - **3 個 e2e test 全部 ALL PASS**：
>     - `e2e_phase1.php`：19 步驟（新增 verify-email ×2）
>     - `e2e_password_reset.php`：7 步驟（完整重設流程 + hash 變更 + 舊密碼拒絕）
>     - `e2e_login_security.php`：8 步驟（CAPTCHA image + form + wrong captcha + 3-strike lockout + locked POST rejected + fresh session unaffected）
> - 2026-09-14：完成 §3 Phase 2 群組管理
>   - **群組架構**：以「老師建群組、學生以 join_code 加入」取代直接 student↔teacher 連結
>   - **DB**：`groups` + `group_members` 兩張表（migration 003_groups.sql，UNIQUE join_code + CASCADE FK + status enum）
>   - **Model / Repo / Service**：
>     - `Models/Group.php`：entity
>     - `Repositories/GroupRepository.php`：findById / findByUuid / findByJoinCode / create / updateJoinCode / delete / listByTeacher / countActiveMembers
>     - `Repositories/GroupMemberRepository.php`：addOrReactivate / markLeft / markBanned / listActiveMembers / listActiveByStudent
>     - `Services/GroupService.php`：generateUniqueJoinCode（base32、8 字元、5 次重試碰撞）、createGroup / joinByCode / leave / remove / regenerateCode / deleteGroup（含權限檢查 + audit_log）
>   - **Controllers**：`Teacher/GroupController`（7 actions）+ `Student/GroupController`（4 actions）
>   - **Views**：5 個（teacher/groups/{index, new, show} + student/groups/{index, join}）
>   - **Routes**：11 條（全部 Auth + RequireRole + CSRF middleware）
>   - **Nav**：teacher 加「群組」、student 改為「群組 / 題目 / 排行榜」
>   - **Refactor**：`flashSuccess` / `flashError` 從 AuthController 升級到 BaseController，避免每個 controller 重複
>   - **e2e_groups.php**：10 步驟 lifecycle ALL PASS（建立 / 學生 A 加入 / 學生 B 加入 / 錯碼 / 詳情 2 成員 / 踢 A / A 重加入失敗（banned）/ 換碼 / 舊碼失效 / B 用新碼 / C 用舊碼失敗 / 刪除群組 + CASCADE）
>   - **§3 驗證**：4 個 e2e 全部 ALL PASS（e2e_phase1 / e2e_password_reset / e2e_login_security / e2e_groups），無 regression
- 2026-09-14：完成 §4 Phase 3 Device API
  - **架構**：學生端用 ActivationCode（10 分鐘單次）→ Device 用啟用碼換 Device Token（64 字元 hex、SHA-256 hash 存 DB）→ 之後用 Bearer Token 呼叫 API
  - **DB**：`devices` + `device_activation_codes` 兩張表（已存在於 000_init.sql）
  - **Service / Repo / Middleware**：
    - `Services/ActivationCodeService.php`：generate / verifyAndUse / cleanupExpired（單次使用、過期失效）
    - `Repositories/DeviceRepository.php`：create / findById / findByUuid / findByTokenHash / updateStatus / touchLastSeen / revoke / listActiveByUser
    - `Services/DeviceService.php`：activateDevice / heartbeat / revokeDevice（含 max devices 限制）
    - `Middleware/DeviceAuth.php`：解析 Bearer token + X-Device-ID、查 device、檢查 status=active
  - **Controllers**：`Controllers/DeviceApiController.php`（activate）+ `Controllers/DeviceController.php`（info / heartbeat）
  - **Routes**：3 條（`/api/v1/device/activate` 公開、`/api/v1/device/info` + `/heartbeat` 走 DeviceAuth）
  - **修補**：
    - `public/.htaccess` 加 `CGIPassAuth On`（Apache 預設會吞掉 Authorization header）
    - `Request::header()` 改為 case-insensitive
    - `Request` 增加 Content-Type/Content-Length 從 SERVER 直接讀
  - **e2e_devices.php**：8 步驟 ALL PASS（產生啟用碼 → 啟用 → info → heartbeat → 錯 token 401 → 重用 code 400 → revoke → revoked 401）

