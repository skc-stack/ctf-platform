# CTF Server UI / UX Style Guide

## 1. 風格

- CTF
- Cybersecurity
- Hacker / SOC
- Terminal
- Dark dashboard
- 專業、可讀性高

避免過度電影化「駭客」效果。

---

## 2. 色彩

```text
#07111F Background
#0B1628 Panel
#101D30 Card
#00E5A8 Cyber Green
#00B8FF Electric Blue
#7B61FF Purple
#22C55E Success
#F59E0B Warning
#EF4444 Danger
#F8FAFC Main Text
#CBD5E1 Secondary
#94A3B8 Muted
```

---

## 3. CSS Variables

```css
:root {
  --bg: #07111F;
  --bg-panel: #0B1628;
  --bg-card: #101D30;
  --cyber-green: #00E5A8;
  --cyber-blue: #00B8FF;
  --cyber-purple: #7B61FF;
  --text: #F8FAFC;
  --text-secondary: #CBD5E1;
  --text-muted: #94A3B8;
  --success: #22C55E;
  --warning: #F59E0B;
  --danger: #EF4444;
}
```

---

## 4. Font

UI：

- Inter
- Noto Sans TC

Terminal：

- JetBrains Mono
- Fira Code
- ui-monospace

---

## 5. Server UI

Header：

```text
CTF LAB | Challenges | Scoreboard | Devices | User
```

Teacher/Admin 使用 Sidebar：

```text
Dashboard
Challenges
Students
Devices
Leaderboard
Audit Logs
Settings
```

---

## 6. Student Dashboard

Cards：

- Total Score
- Rank
- Solved
- Active Tasks

Challenge Card 顯示：

- Category
- Title
- Difficulty
- Points
- Solve count
- Start

---

## 7. Task Token

Terminal Card：

```text
TASK TOKEN
TASK-M7KX-82PP-W9ZA-33QF
Expires in 01:42:12
[Copy Token]
```

---

## 8. Flag

Input：

```text
flag{...}
```

Correct：

```text
✓ Correct Flag
+100 points
```

Incorrect：

```text
✕ Incorrect Flag
Try again.
```

不可洩漏 expected flag。

---

## 9. Target Portal

Target Portal 可更偏 terminal style，但仍須易讀。

顯示：

- Device
- Server connection
- Last sync
- Installed Challenges
- Current Task

---

## 10. Background

使用深色 + subtle grid。

```css
body {
  background:
    linear-gradient(rgba(0,184,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,184,255,.025) 1px, transparent 1px),
    #07111F;
  background-size: 32px 32px;
}
```

---

## 11. SVG / Icon

優先：

- Bootstrap Icons
- Heroicons
- Lucide

建議 icon：

- Shield
- Terminal
- Flag
- Database
- Server
- Network
- Trophy
- Key
- Lock

---

## 12. 圖片

若需要 Hero / Background，可用：

- Unsplash
- Pexels
- Pixabay

建議：

- server rack
- cyber grid
- code screen
- network infrastructure

需保留 license / attribution 紀錄。

---

## 13. Responsive

Desktop：
- sidebar
- 3–4 column cards

Tablet：
- collapsible sidebar
- 2 columns

Mobile：
- top navigation
- single column

---

## 14. Accessibility

- 高對比
- keyboard focus
- 不只用顏色區分狀態
- mobile table scroll
- aria-label

---

## 15. 禁止風格

避免：

- Matrix rain
- 大量 neon blur
- 純黑 + 全綠
- 閃爍動畫
- 骷髏 / 犯罪意象
- 過度 game UI
