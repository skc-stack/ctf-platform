<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body style="background:#0e1116;padding:32px">

<!-- Replicate the show.php structure exactly -->
<h3>show.php action bar (current code)</h3>
<div class="ctf-dash-actions" id="actions">
  <a href="#" class="ctf-btn" id="a1"><i class="bi bi-pencil-square"></i> 編輯資料</a>
  <form action="/teacher/challenges/145/publish" method="post" class="ctf-dash-action-form"
        onsubmit="return confirm('...');">
    <input type="hidden" name="_csrf" value="abc">
    <button type="submit" class="ctf-btn ctf-btn-primary" id="b1"><i class="bi bi-rocket-takeoff"></i> 發布</button>
  </form>
  <a href="#" class="ctf-btn ctf-btn-ghost" id="a2"><i class="bi bi-arrow-left"></i> 回到列表</a>
</div>

<!-- Old style attribute (before fix) -->
<h3 style="margin-top:40px">OLD style="display:inline" (baseline still has 2px diff)</h3>
<div class="ctf-dash-actions">
  <a href="#" class="ctf-btn"><i class="bi bi-pencil-square"></i> 編輯資料</a>
  <form action="#" method="post" style="display:inline">
    <input type="hidden" name="_csrf" value="abc">
    <button type="submit" class="ctf-btn ctf-btn-primary" id="b2"><i class="bi bi-rocket-takeoff"></i> 發布</button>
  </form>
  <a href="#" class="ctf-btn ctf-btn-ghost"><i class="bi bi-arrow-left"></i> 回到列表</a>
</div>

<pre id="out" style="color:#fff;background:#222;padding:12px;margin-top:24px"></pre>
<script>
const out = [];
const cs = id => {
  const el = document.getElementById(id);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  const s = getComputedStyle(el);
  return { h: r.height.toFixed(2), top: r.top.toFixed(2) };
};
out.push('NEW: ' + JSON.stringify({ a: cs('a1'), b: cs('b1'), a2: cs('a2') }));
out.push('OLD: ' + JSON.stringify({ b: cs('b2') }));
document.getElementById('out').textContent = out.join('\n');
</script>
</body>
</html>
