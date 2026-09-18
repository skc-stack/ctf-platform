<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body style="background:#0e1116;padding:32px">
<div class="ctf-dash-actions" id="actions">
  <a href="#" class="ctf-btn" id="a1"><i class="bi bi-pencil-square"></i> 編輯資料</a>
  <form action="#" method="post" class="ctf-dash-action-form" id="f1">
    <input type="hidden" name="_csrf" value="abc">
    <button type="submit" class="ctf-btn ctf-btn-primary" id="b1"><i class="bi bi-rocket-takeoff"></i> 發布</button>
  </form>
  <a href="#" class="ctf-btn ctf-btn-ghost" id="a2"><i class="bi bi-arrow-left"></i> 回到列表</a>
</div>
<pre id="out" style="color:#fff"></pre>
<script>
const r1 = document.getElementById('a1').getBoundingClientRect();
const r2 = document.getElementById('b1').getBoundingClientRect();
const r3 = document.getElementById('a2').getBoundingClientRect();
const s1 = getComputedStyle(document.getElementById('a1'));
const s2 = getComputedStyle(document.getElementById('b1'));
const s3 = getComputedStyle(document.getElementById('a2'));
document.getElementById('out').textContent = JSON.stringify({
  a_edit: { h: r1.height, top: r1.top, pTB: s1.paddingTop + '/' + s1.paddingBottom, border: s1.borderTopWidth + ' ' + s1.borderBottomWidth, lh: s1.lineHeight, fs: s1.fontSize, display: s1.display, vert: s1.verticalAlign },
  b_publish: { h: r2.height, top: r2.top, pTB: s2.paddingTop + '/' + s2.paddingBottom, border: s2.borderTopWidth + ' ' + s2.borderBottomWidth, lh: s2.lineHeight, fs: s2.fontSize, display: s2.display, vert: s2.verticalAlign },
  a_back: { h: r3.height, top: r3.top, pTB: s3.paddingTop + '/' + s3.paddingBottom, border: s3.borderTopWidth + ' ' + s3.borderBottomWidth, lh: s3.lineHeight, fs: s3.fontSize, display: s3.display, vert: s3.verticalAlign },
}, null, 2);
</script>
</body>
</html>
