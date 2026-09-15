# CTF 伺服器環境設定

> 本機 LAMP 環境安裝紀錄（Apache + PHP + MariaDB + phpMyAdmin）。  
> 安裝日期：2026-09-13  
> 適用：Windows 11 Pro、PowerShell、以 `ai` 這個使用者帳號操作（非系統管理員）

---

## 1. 總覽

| 元件 | 版本 | 安裝位置 | 啟動方式 |
|---|---|---|---|
| Apache | 2.4.68 (VS18 build) | `C:\Apache24\`（junction → WinGet portable 路徑） | `start-apache.bat` 前景模式 |
| PHP | 8.3.32 (TS x64, VS16) | `C:\Users\ai\AppData\Local\Programs\PHP\8.3.32\` | 隨 Apache 載入（`php8apache2_4.dll`） |
| MariaDB | 12.3.3.0 | `C:\Users\ai\MariaDB12\` | `start-mariadb.bat` 前景模式 |
| phpMyAdmin | 5.2.3 | `C:\Users\ai\CTF\pma\`（`http://localhost/pma/`） | 隨 Apache 提供 |

**設計重點：**
- 三個元件分開安裝（不是 XAMPP 之類的 bundle）
- **不安裝成 Windows service**，全部用 `.bat` 批次檔前景模式啟動
- DocumentRoot = `C:\Users\ai\CTF\`
- MariaDB 最高管理帳號：`admin` / `Misstree@0909`
- PHP 設定符合 DVWA 教學環境需求（`allow_url_include=On` 等）

---

## 2. Apache 2.4.68

### 安裝來源
winget 套件：`ApacheLounge.httpd`（portable ZIP，預設裝在 WinGet 深層路徑）。為符合一般慣例路徑，建了 junction：

```powershell
New-Item -ItemType Junction -Path 'C:\Apache24' -Target 'C:\Users\ai\AppData\Local\Microsoft\WinGet\Packages\ApacheLounge.httpd_Microsoft.Winget.Source_8wekyb3d8bbwe\Apache24'
```

### 設定檔位置
- 主設定：`C:\Apache24\conf\httpd.conf`
- 備份：`C:\Apache24\conf\httpd.conf.bak`
- 模組目錄：`C:\Apache24\modules\`
- Log：`C:\Apache24\logs\`（Apache 啟動時不會自動建，第一次啟動時會自動產生）

### httpd.conf 重要修改

| 項目 | 原始值 | 改為 |
|---|---|---|
| `DocumentRoot` (line 257) | `"${SRVROOT}/htdocs"` | `"C:/Users/ai/CTF"` |
| `<Directory>` (line 258) | `"${SRVROOT}/htdocs">` | `"C:/Users/ai/CTF">` |
| `LoadModule rewrite_module` (line 147) | 註解 | 取消註解（DVWA 需要 mod_rewrite） |
| `ServerName` (line 233) | 註解 | `ServerName localhost:80` |
| 檔尾新增 | — | PHP 接線區塊（見下） |

### PHP 接線區塊（附加於 `httpd.conf` 末尾）

```apache
# === PHP 8.3 (CTF) ===
LoadModule php_module "C:/Users/ai/AppData/Local/Programs/PHP/8.3.32/php8apache2_4.dll"
AddType application/x-httpd-php .php
PHPIniDir "C:/Users/ai/AppData/Local/Programs/PHP/8.3.32"
AddHandler application/x-httpd-php .php
```

### 語法檢查
```powershell
& 'C:\Apache24\bin\httpd.exe' -t
```
預期輸出：`Syntax OK`（PowerShell 會把 `httpd.exe :` 前綴加上去，那只是 stderr 格式，不算錯誤）

---

## 3. PHP 8.3.32

### 安裝來源
winget 套件 `PHP.PHP.8.3` 的 manifest 下載 URL 已失效（404，已移到 archives 目錄）。手動從 archives 下載：

```powershell
Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/archives/php-8.3.32-Win32-vs16-x64.zip' -OutFile "$env:TEMP\php.zip"
Expand-Archive "$env:TEMP\php.zip" "$env:LOCALAPPDATA\Programs\PHP\8.3.32" -Force
```

VS16 build 與 Apache VS18 build 相容（兩者都用 VCRuntime 140 + UCRT），實測可載入。

### php.ini 位置
`C:\Users\ai\AppData\Local\Programs\PHP\8.3.32\php.ini`（從 `php.ini-production` 複製）

### DVWA 需求的 php.ini 設定（附加於檔尾）

```ini
; === CTF/DVWA requirements ===
allow_url_include = On
allow_url_fopen = On
display_errors = On
display_startup_errors = On
file_uploads = On
safe_mode = Off
magic_quotes_gpc = Off
```

`safe_mode` 與 `magic_quotes_gpc` 在 PHP 5.4 之後已移除，設了等於無作用但不會報錯，DVWA 教學慣例上會列。

### 啟用的 extensions

| Extension | 用途 |
|---|---|
| `mysqli` | 連 MariaDB |
| `gd` | 圖形處理 |
| `mbstring` | phpMyAdmin 必要 |
| `openssl` | phpMyAdmin 必要、cookie 加密 |
| `curl` | phpMyAdmin 部分功能 |
| `zip` | phpMyAdmin import/export |
| `json` | 內建，自動載入 |
| `session` | 內建，自動載入 |

### ⚠️ 重要：`extension_dir` 必須用絕對路徑

在 `php.ini` 第 780 行附近：

```ini
extension_dir = "C:/Users/ai/AppData/Local/Programs/PHP/8.3.32/ext"
```

**為什麼不能用 `extension_dir = "ext"`？**
因為 PHP 跑在 Apache 模組下時，工作目錄是 Apache 的 bin dir（`C:\Apache24\bin\`），所以 `"ext"` 會去找 `C:\Apache24\bin\ext\`（不存在），mysqli/gd 會無聲失敗。CLI 跑 `php.exe` 時工作目錄是 PHP 自己，所以相對路徑能用 — 但 Apache 模式下不行。

---

## 4. MariaDB 12.3.3.0

### 安裝來源
winget 套件 `MariaDB.Server` 是 Wix MSI，**強制需要系統管理員權限**（因為預設會註冊 Windows service）。本環境是非系統管理員，因此改用以下方法：

```powershell
# 1. 下載 MSI（用 mariadb.org REST API）
Invoke-WebRequest -Uri 'https://downloads.mariadb.org/rest-api/mariadb/12.3.3/mariadb-12.3.3-winx64.msi' -OutFile "$env:TEMP\mariadb.msi"

# 2. 用 7-Zip 解 MSI 結構（不觸發 MSI 的 elevation 檢查）
& 'C:\Program Files\7-Zip\7z.exe' x "$env:TEMP\mariadb.msi" -oC:\Users\ai\MariaDB12 -y

# 3. 解所有 cab*.cab 到同一目錄（CAB 內的檔案會被展平為 F.bin.mariadbd.exe 等）
foreach ($cab in Get-ChildItem C:\Users\ai\MariaDB12\cab*.cab) {
    & 'C:\Program Files\7-Zip\7z.exe' x $cab.FullName -oC:\Users\ai\MariaDB12 -aoa -y
}

# 4. 還原目錄結構：去掉 F. 前綴，第一段當 dir，其餘（含 .）當 filename
$files = Get-ChildItem C:\Users\ai\MariaDB12 -Recurse -File -Filter 'F.*' | Where-Object { $_.Name -notmatch '^!|^Binary\.' }
foreach ($f in $files) {
    $rest = $f.Name.Substring(2)
    $parts = $rest.Split('.', 2)
    $newRel = if ($parts.Count -eq 1) { $parts[0] } else { "$($parts[0])\$($parts[1])" }
    Move-Item $f.FullName "C:\Users\ai\MariaDB12\$newRel" -Force
}

# 5. 用舊版 mysql_install_db 初始化（12.3 已不支援 --initialize-insecure）
& C:\Users\ai\MariaDB12\bin\mariadb_install_db.exe --datadir=C:\Users\ai\MariaDB12\data --password=TempRootPass1! --port=3306
```

### my.ini
`C:\Users\ai\MariaDB12\my.ini`：

```ini
[mysqld]
basedir=C:\Users\ai\MariaDB12
datadir=C:\Users\ai\MariaDB12\data
port=3306
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_general_ci

[client]
port=3306
default-character-set=utf8mb4
```

`bind-address=127.0.0.1` 表示只接受本機連線（LAN 連不到，預設安全性較佳）。

### 帳號

| 帳號 | 密碼 | 權限 |
|---|---|---|
| `admin@localhost` | `Misstree@0909` | `ALL PRIVILEGES ... WITH GRANT OPTION`（全權最高管理） |
| `root@localhost` | （空密碼） | 全部（dev 用，故意留空方便緊急救援） |

### 建立 admin 的 SQL
啟動 mariadbd 後、用 root 暫存密碼登入：

```sql
CREATE USER 'admin'@'localhost' IDENTIFIED BY 'Misstree@0909';
GRANT ALL PRIVILEGES ON *.* TO 'admin'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;

ALTER USER 'root'@'localhost' IDENTIFIED BY '';
FLUSH PRIVILEGES;
```

### 登入測試
```powershell
& 'C:\Users\ai\MariaDB12\bin\mariadb.exe' -u admin -p'Misstree@0909' -e "SELECT VERSION(), CURRENT_USER();"
```

### ⚠️ CLI 二進位檔案名稱
管理工具用底線：`mariadb_admin.exe`、`mariadb_install_db.exe`（不是 `mariadb-admin.exe`）。如果寫錯了批次檔會 "is not recognized"。

---

## 5. phpMyAdmin 5.2.3

### 安裝來源
從官網下載 5.2.3 all-languages 版（winget 沒有獨立套件，只有 WAMP 標籤）：

```powershell
Invoke-WebRequest -Uri 'https://files.phpmyadmin.net/phpMyAdmin/5.2.3/phpMyAdmin-5.2.3-all-languages.zip' -OutFile "$env:TEMP\pma.zip"
Expand-Archive "$env:TEMP\pma.zip" "C:\Users\ai\CTF\" -Force
Rename-Item "C:\Users\ai\CTF\phpMyAdmin-5.2.3-all-languages" "pma"
```

### URL
`http://localhost/pma/`

### Config
`C:\Users\ai\CTF\pma\config.inc.php`（從 `config.sample.inc.php` 複製）

修改：
- `blowfish_secret` → 32 字元隨機字串（給 cookie auth 加密用）
- `host` → `127.0.0.1`
- `auth_type` → `cookie`（預設值，保留）

### 登入流程
1. 開 `http://localhost/pma/`
2. 看到登入頁（繁體中文介面，lang=zh_TW）
3. 輸入 `admin` / `Misstree@0909`
4. 看到首頁，左側欄有 `information_schema`、`mysql`、`performance_schema`、`sys`、`test` 五個系統資料庫

### tmp/ 權限
`C:\Users\ai\CTF\pma\tmp\` 需要可寫，給了 `Everyone:Modify`。這是 phpMyAdmin 寫 session/cache 用的。

### ⚠️ config.inc.php 不能有 UTF-8 BOM
phpMyAdmin 5.x 的 config 開頭有 `declare(strict_types=1);`，這個宣告必須是檔案第一個 statement。如果檔案開頭有 BOM（`EF BB BF`），PHP 會報：

> Failed to load phpMyAdmin configuration: strict_types declaration must be the very first statement in the script

PowerShell 的 `Set-Content -Encoding UTF8` 會加 BOM；要用 `-Encoding ASCII`、`Out-File -Encoding utf8NoBOM`（PS7+），或 `[IO.File]::WriteAllText($path, $content, [System.Text.UTF8Encoding]::new($false))`。

---

## 6. 啟動與停止

`C:\Users\ai\CTF\` 下有四個 `.bat` 檔（全部不用系統管理員）：

### `start-apache.bat`
```bat
@echo off
echo Starting Apache on http://localhost/ (Ctrl+C to stop)
"C:\Apache24\bin\httpd.exe" -D FOREGROUND
```
前景模式，console 視窗保持開啟。要停就 Ctrl+C，或執行 `stop-apache.bat`。

### `start-mariadb.bat`
```bat
@echo off
echo Starting MariaDB (foreground, Ctrl+C to stop)...
"C:\Users\ai\MariaDB12\bin\mariadbd.exe" --defaults-file="C:\Users\ai\MariaDB12\my.ini" --console
```
前景模式，console 視窗保持開啟。

### `stop-apache.bat`
```bat
@echo off
taskkill /IM httpd.exe /F
```
強制終止所有 httpd.exe process。

### `stop-mariadb.bat`
```bat
@echo off
"C:\Users\ai\MariaDB12\bin\mariadb_admin.exe" -u admin -pMisstree@0909 shutdown
if errorlevel 1 taskkill /IM mariadbd.exe /F
```
優先 graceful shutdown（會等現有連線結束），失敗才 taskkill。

### 標準啟動順序
```
先 mariadbd → 再 apache
```

---

## 7. 常用指令速查

### 檢查服務狀態
```powershell
Get-Process -Name httpd,mariadbd -ErrorAction SilentlyContinue | Select Name, Id, StartTime
Test-NetConnection localhost -Port 80   -InformationLevel Quiet
Test-NetConnection localhost -Port 3306 -InformationLevel Quiet
```

### Apache 語法檢查
```powershell
& 'C:\Apache24\bin\httpd.exe' -t
```

### PHP CLI 測試
```powershell
& "$env:LOCALAPPDATA\Programs\PHP\8.3.32\php.exe" --version
& "$env:LOCALAPPDATA\Programs\PHP\8.3.32\php.exe" -m
& "$env:LOCALAPPDATA\Programs\PHP\8.3.32\php.exe" -r "echo ini_get('allow_url_include');"
```

### MariaDB CLI
```powershell
& 'C:\Users\ai\MariaDB12\bin\mariadb.exe' -u admin -p'Misstree@0909' -e "SHOW DATABASES;"
& 'C:\Users\ai\MariaDB12\bin\mariadb.exe' -u admin -p'Misstree@0909' -e "SELECT user, host FROM mysql.user;"
```

### 一次啟動兩個服務（用兩個 console window）
```powershell
Start-Process cmd -ArgumentList '/c', 'C:\Users\ai\CTF\start-mariadb.bat' -WindowStyle Normal
Start-Sleep 4
Start-Process cmd -ArgumentList '/c', 'C:\Users\ai\CTF\start-apache.bat' -WindowStyle Normal
```

---

## 8. 安裝 DVWA（或其他 PHP 應用）

DVWA 是經典的漏洞練習環境，設定跟本環境完全相容。

```powershell
# 1. 啟動服務
& 'C:\Users\ai\CTF\start-mariadb.bat'  # 在另一個 console
& 'C:\Users\ai\CTF\start-apache.bat'

# 2. Clone DVWA
git clone https://github.com/digininja/DVWA.git C:\Users\ai\CTF\DVWA

# 3. 設定 config
Copy-Item C:\Users\ai\CTF\DVWA\config\config.inc.php.dist C:\Users\ai\CTF\DVWA\config\config.inc.php
# 編輯：db_user=admin, db_password=Misstree@0909

# 4. 瀏覽器跑 setup
#    http://localhost/DVWA/setup.php
#    點 "Create / Reset Database"

# 5. 登入
#    http://localhost/DVWA/login.php
#    admin / password
```

如果要裝到 doc root 頂層（`http://localhost/` 而非 `/DVWA/`）：
```powershell
git clone https://github.com/digininja/DVWA.git C:\Users\ai\CTF\tmp_dvwa
# 把 tmp_dvwa 內所有檔案（含隱藏的）移到 C:\Users\ai\CTF\
Move-Item C:\Users\ai\CTF\tmp_dvwa\* C:\Users\ai\CTF\ -Force
Remove-Item C:\Users\ai\CTF\tmp_dvwa -Recurse -Force
```

---

## 9. 安全性與限制

這是給本機 CTF 練習用的環境，**故意寬鬆**：

- ✅ `bind-address=127.0.0.1` — MariaDB 只 listen 本機
- ✅ Windows Firewall 預設擋住 inbound TCP 80 — LAN 也連不到
- ❌ `allow_url_include = On`（PHP）— **危險**，正式環境不能開
- ❌ `root`@localhost 密碼為空 — 方便救援，正式環境不能這樣
- ❌ `Everyone:Modify` on `pma\tmp` — 給本機所有帳號寫入，正式環境要限縮

**這台機器不要對外開放**，特別是 80 與 3306 port。如要 LAN 連線：
```powershell
# 開放 inbound TCP 80（給 LAN 上的其他機器用）
New-NetFirewallRule -DisplayName "Apache HTTP (CTF)" -Direction Inbound -LocalPort 80 -Protocol TCP -Action Allow
```

---

## 10. 故障排除速查

| 症狀 | 可能原因 | 解法 |
|---|---|---|
| Apache 啟動後連不上 | 80 port 被佔 | `Get-NetTCPConnection -LocalPort 80 -State Listen` 找佔用者 |
| `httpd.exe -t` 報 Syntax Error | 編輯 httpd.conf 改錯 | `Compare-Object (Get-Content C:\Apache24\conf\httpd.conf.bak) (Get-Content C:\Apache24\conf\httpd.conf)` diff |
| PHP 頁面顯示原始碼 | `AddType application/x-httpd-php .php` 沒生效 | 確認 `php8apache2_4.dll` 存在、httpd.conf 末尾的接線區塊 |
| `mysqli_connect` 報 "undefined function" | `extension_dir` 是相對路徑 | 改絕對路徑，參考 §3 |
| mysqli/gd 顯示已載入但 `extension_loaded` 回 false | Apache 用舊 php.ini | 重啟 Apache 讓 PHP module 重讀 |
| phpMyAdmin 報 "strict_types must be the very first statement" | `config.inc.php` 有 BOM | 用 `[IO.File]::WriteAllText` 重新寫檔（不加 BOM） |
| mariadbd 起不來：找不到 data 目錄 | 沒跑 `mariadb_install_db.exe` | 重新初始化（見 §4） |
| mariadbd 起不來：bind-address error | my.ini 設定衝突 | 檢查 my.ini，確認沒有重複的 `bind-address` |
| `mariadb -u admin -p` Access denied | 密碼打錯或 admin 不存在 | 用 `root`（空密碼）登入後 `SELECT user, host FROM mysql.user;` |
| `phpMyAdmin` 登入後跳 404 | 表單 POST 到 `/pma/` | 這是預期行為，POST 應送到 `/pma/index.php?route=/`（瀏覽器自動處理） |

---

## 11. 重要檔案位置總覽

```
C:\Users\ai\CTF\
├── start-apache.bat
├── start-mariadb.bat
├── stop-apache.bat
├── stop-mariadb.bat
├── pma\                       ← phpMyAdmin
│   ├── index.php
│   ├── config.inc.php
│   └── tmp\                   ← Everyone:Modify
└── SERVER-ENVIRONMENT.md      ← 本檔

C:\Apache24\                  ← junction
├── bin\httpd.exe
└── conf\httpd.conf (+ .bak)

C:\Users\ai\AppData\Local\Programs\PHP\8.3.32\
├── php.exe
├── php8apache2_4.dll
├── php.ini                    ← DVWA flags、絕對 extension_dir
└── ext\

C:\Users\ai\MariaDB12\
├── bin\
│   ├── mariadbd.exe
│   ├── mariadb.exe
│   └── mariadb_admin.exe      ← 注意是底線
├── data\                      ← mariadb_install_db 建出來的
├── my.ini
└── (其他如 lib\, share\, include\)
```

---

最後更新：2026-09-13
