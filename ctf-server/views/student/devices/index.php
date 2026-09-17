<?php
use CTF\Server\Security\CSRF;

/** @var array $devices */
$devices = $devices ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">我的裝置</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">管理已啟用的 Target VM 裝置，或請求新的 Activation Code。</p>

    <!-- Request Activation Code -->
    <div class="ctf-panel">
        <h2 class="ctf-dash-sub">/ 請求 Activation Code</h2>
        <p class="ctf-muted" style="margin-bottom:1rem">
            輸入 Activation Code 到 Target VM 的 Portal 以啟用新裝置。<br>
            Code 有效時間為 10 分鐘，單次使用。
        </p>
        <button type="button" id="request-code-btn" class="ctf-btn ctf-btn-primary">
            [▸ 請求新 Activation Code]
        </button>
        <div id="code-result" style="margin-top:1rem;display:none">
            <div class="ctf-alert ctf-alert-info">
                <strong>Activation Code：</strong>
                <code id="code-display" style="font-size:1.2em;letter-spacing:0.1em"></code>
                <button type="button" id="copy-code-btn" class="ctf-btn ctf-btn-sm ctf-btn-ghost" onclick="copyCode()">
                    <i class="bi bi-clipboard"></i> 複製
                </button>
                <br><small class="ctf-muted">請在 10 分鐘內使用，僅顯示一次。</small>
            </div>
        </div>
        <div id="code-error" style="margin-top:1rem;display:none">
            <div class="ctf-alert ctf-alert-error"></div>
        </div>
    </div>

    <!-- Device List -->
    <div class="ctf-panel" style="margin-top:2rem">
        <h2 class="ctf-dash-sub">/ 已啟用的裝置</h2>
        <?php if (empty($devices)): ?>
            <p class="ctf-muted">尚無已啟用的裝置。使用上方的 Activation Code 在 Target VM 啟用裝置。</p>
        <?php else: ?>
            <table class="ctf-table">
                <thead>
                    <tr>
                        <th>裝置名稱</th>
                        <th>UUID</th>
                        <th>啟用時間</th>
                        <th>最後連線</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['device_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($d['uuid'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($d['activated_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($d['last_seen_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($d['status'] === 'active'): ?>
                                <span class="ctf-tag" style="color:var(--drafting-green);border-color:var(--drafting-green)">啟用中</span>
                            <?php else: ?>
                                <span class="ctf-tag ctf-tag-muted"><?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['status'] === 'active'): ?>
                                <form action="/api/v1/student/devices/revoke/<?= (int)$d['id'] ?>" method="post" class="revoke-form" style="display:inline">
                                    <?= CSRF::field() ?>
                                    <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-ghost"
                                            onclick="return confirm('確定要撤銷此裝置？')">
                                        [撤銷]
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Instructions -->
    <div class="ctf-panel" style="margin-top:2rem">
        <h2 class="ctf-dash-sub">/ 啟用步驟</h2>
        <ol style="line-height:1.8">
            <li>點擊上方「請求新 Activation Code」按鈕</li>
            <li>複製產生的 Activation Code（格式：ACT-XXXX-XXXX-XXXX）</li>
            <li>在 Target VM 的 Portal 頁面，選擇「啟用裝置」</li>
            <li>貼上 Activation Code 並確認</li>
            <li>裝置啟用成功後即可開始解題</li>
        </ol>
    </div>
</section>

<script>
document.getElementById('request-code-btn').addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    btn.textContent = '[產生中...]';

    const resultDiv = document.getElementById('code-result');
    const errorDiv = document.getElementById('code-error');
    const codeDisplay = document.getElementById('code-display');

    resultDiv.style.display = 'none';
    errorDiv.style.display = 'none';

    try {
        const csrfField = document.querySelector('input[name="_csrf"]');
        const csrf = csrfField ? csrfField.value : '';

        const res = await fetch('/api/v1/student/devices/request-code', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF': csrf
            },
            body: JSON.stringify({})
        });

        const data = await res.json();

        if (data.success) {
            codeDisplay.textContent = data.data.activation_code;
            resultDiv.style.display = 'block';
        } else {
            errorDiv.querySelector('.ctf-alert-error').textContent = '錯誤：' + (data.error || '未知錯誤');
            errorDiv.style.display = 'block';
        }
    } catch (e) {
        errorDiv.querySelector('.ctf-alert-error').textContent = '網路錯誤，請稍後再試';
        errorDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = '[▸ 請求新 Activation Code]';
    }
});

// Handle revoke forms
document.querySelectorAll('.revoke-form').forEach(function(form) {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        if (!confirm('確定要撤銷此裝置？')) return;

        const csrfField = form.querySelector('input[name="_csrf"]');
        const csrf = csrfField ? csrfField.value : '';
        const action = form.action;

        try {
            const res = await fetch(action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF': csrf
                },
                body: JSON.stringify({})
            });

            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('錯誤：' + (data.error || '未知錯誤'));
            }
        } catch (e) {
            alert('網路錯誤，請稍後再試');
        }
    });
});

// Copy activation code to clipboard
function copyCode() {
    const code = document.getElementById('code-display').textContent;
    if (!code) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(function() {
            const btn = document.getElementById('copy-code-btn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check"></i> 已複製';
            btn.disabled = true;
            setTimeout(function() {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }, 2000);
        }).catch(function() {
            fallbackCopyCode(code);
        });
    } else {
        fallbackCopyCode(code);
    }
}

function fallbackCopyCode(code) {
    const textarea = document.createElement('textarea');
    textarea.value = code;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        const btn = document.getElementById('copy-code-btn');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i> 已複製';
        btn.disabled = true;
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }, 2000);
    } catch (err) {
        alert('複製失敗，請手動選取文字複製');
    }
    document.body.removeChild(textarea);
}
</script>
