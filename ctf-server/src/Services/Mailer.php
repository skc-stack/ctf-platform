<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Support\Config;
use CTF\Server\Support\Logger;

/**
 * High-level mailer. Composes HTML bodies and dispatches via NylasClient.
 *
 * Each send*() returns true on success, false if the email failed to dispatch.
 * Failure does not throw — caller decides how to surface the problem.
 */
final class Mailer
{
    public function __construct(
        private readonly NylasClient $nylas = new NylasClient(),
    ) {}

    public function isConfigured(): bool
    {
        return $this->nylas->isConfigured();
    }

    /**
     * Send an Email verification link. The user must click this to activate
     * (student → active; teacher → still pending, awaiting admin approval).
     */
    public function sendVerificationEmail(
        string $toEmail,
        string $displayName,
        string $verifyUrl,
    ): bool {
        $appName = (string)Config::get('APP_NAME', 'CTF LAB');
        $subject = sprintf('[%s] 請驗證您的 Email 完成註冊', $appName);
        $body = $this->renderVerificationBody($appName, $displayName, $verifyUrl);
        try {
            $this->nylas->send($toEmail, $subject, $body, $displayName);
            return true;
        } catch (\Throwable $e) {
            Logger::get()->error('mailer.sendVerificationEmail', [
                'to'    => $toEmail,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a password reset link. Valid for PASSWORD_RESET_TTL minutes (default 60).
     */
    public function sendPasswordResetEmail(
        string $toEmail,
        string $displayName,
        string $resetUrl,
        int $ttlMinutes,
    ): bool {
        $appName = (string)Config::get('APP_NAME', 'CTF LAB');
        $subject = sprintf('[%s] 重設密碼請求', $appName);
        $body = $this->renderResetBody($appName, $displayName, $resetUrl, $ttlMinutes);
        try {
            $this->nylas->send($toEmail, $subject, $body, $displayName);
            return true;
        } catch (\Throwable $e) {
            Logger::get()->error('mailer.sendPasswordResetEmail', [
                'to'    => $toEmail,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function renderVerificationBody(string $appName, string $name, string $url): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $app = $h($appName);
        $nm  = $h($name);
        $u   = $h($url);
        return <<<HTML
<!doctype html>
<html lang="zh-Hant"><body style="margin:0;background:#0E1A2E;color:#E8E4D6;font-family:'IBM Plex Serif',Georgia,serif;padding:40px 16px;">
  <div style="max-width:560px;margin:0 auto;border:1px solid #C9A961;padding:36px;">
    <div style="font-family:'IBM Plex Mono',monospace;color:#C9A961;letter-spacing:.12em;text-transform:uppercase;font-size:13px;margin-bottom:24px;">/ {$app} / Email 驗證</div>
    <h1 style="font-family:'IBM Plex Mono',monospace;font-size:22px;color:#E8E4D6;margin:0 0 18px;letter-spacing:.04em;">VERIFY YOUR EMAIL</h1>
    <p style="margin:0 0 16px;">Hi {$nm},</p>
    <p style="margin:0 0 24px;">感謝您在 <strong>{$app}</strong> 註冊。請點擊下方按鈕完成 Email 驗證：</p>
    <p style="margin:32px 0;">
      <a href="{$u}" style="display:inline-block;padding:14px 28px;background:#C9A961;color:#0E1A2E;text-decoration:none;font-family:'IBM Plex Mono',monospace;font-weight:700;text-transform:uppercase;letter-spacing:.1em;font-size:14px;">[▸ VERIFY EMAIL]</a>
    </p>
    <p style="margin:0 0 8px;color:#9DA8B8;">或複製以下連結到瀏覽器：</p>
    <p style="font-family:'IBM Plex Mono',monospace;font-size:12px;background:#081424;padding:12px;word-break:break-all;border:1px solid #1E3556;">{$u}</p>
    <p style="margin-top:32px;color:#9DA8B8;font-size:13px;">此連結將在 24 小時後失效。學生帳號驗證後立即啟用；老師帳號驗證後仍須管理員審核。</p>
    <p style="margin-top:12px;color:#6B7787;font-size:12px;">如果您沒有註冊 {$app}，請忽略此信。</p>
  </div>
</body></html>
HTML;
    }

    private function renderResetBody(string $appName, string $name, string $url, int $ttlMinutes): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $app = $h($appName);
        $nm  = $h($name);
        $u   = $h($url);
        return <<<HTML
<!doctype html>
<html lang="zh-Hant"><body style="margin:0;background:#0E1A2E;color:#E8E4D6;font-family:'IBM Plex Serif',Georgia,serif;padding:40px 16px;">
  <div style="max-width:560px;margin:0 auto;border:1px solid #C9A961;padding:36px;">
    <div style="font-family:'IBM Plex Mono',monospace;color:#C9A961;letter-spacing:.12em;text-transform:uppercase;font-size:13px;margin-bottom:24px;">/ {$app} / 密碼重設</div>
    <h1 style="font-family:'IBM Plex Mono',monospace;font-size:22px;color:#E8E4D6;margin:0 0 18px;letter-spacing:.04em;">RESET PASSWORD</h1>
    <p style="margin:0 0 16px;">Hi {$nm},</p>
    <p style="margin:0 0 24px;">我們收到您重設 <strong>{$app}</strong> 密碼的請求。請點擊下方按鈕重設：</p>
    <p style="margin:32px 0;">
      <a href="{$u}" style="display:inline-block;padding:14px 28px;background:#C9A961;color:#0E1A2E;text-decoration:none;font-family:'IBM Plex Mono',monospace;font-weight:700;text-transform:uppercase;letter-spacing:.1em;font-size:14px;">[▸ RESET PASSWORD]</a>
    </p>
    <p style="margin:0 0 8px;color:#9DA8B8;">或複製以下連結到瀏覽器：</p>
    <p style="font-family:'IBM Plex Mono',monospace;font-size:12px;background:#081424;padding:12px;word-break:break-all;border:1px solid #1E3556;">{$u}</p>
    <p style="margin-top:32px;color:#9DA8B8;font-size:13px;">此連結將在 {$ttlMinutes} 分鐘後失效。如果您沒有要求重設密碼，請忽略此信，您的密碼不會被變更。</p>
  </div>
</body></html>
HTML;
    }
}
