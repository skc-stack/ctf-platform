<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Support\Config;

/**
 * Image-based CAPTCHA + per-browser login lockout.
 *
 * Flow:
 *   - GET /login        → CaptchaService::generate() stores HMAC-SHA256 hash in session
 *   - <img src="/captcha"> → CaptchaService::image() renders PNG
 *   - POST /login       → CaptchaService::verify() compares (one-shot use)
 *
 * Lockout:
 *   - On each credential failure, fail counter in session is incremented
 *   - At 3 failures, the session is locked for 1 hour (browser-bound)
 *   - Captcha failures do NOT count toward lockout (they regenerate instead)
 *   - Successful login clears both counter and lock
 */
final class CaptchaService
{
    /** Alphabet excluding visually-confusing chars (0/O/1/I/l). */
    private const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LEN = 5;

    private const TTL_SECONDS = 300;      // 5 minutes
    private const MAX_FAILS = 3;
    private const LOCK_SECONDS = 3600;    // 1 hour

    /**
     * Generate a new answer, store hash in session, return the raw answer.
     * The raw answer is needed by /captcha to render the image.
     */
    public function generate(): array
    {
        $max = strlen(self::CHARS) - 1;
        $answer = '';
        for ($i = 0; $i < self::LEN; $i++) {
            $answer .= self::CHARS[\random_int(0, $max)];
        }
        $_SESSION['captcha_hash']    = $this->hashAnswer($answer);
        $_SESSION['captcha_expires'] = \time() + self::TTL_SECONDS;
        return ['answer' => $answer, 'expires_at' => $_SESSION['captcha_expires']];
    }

    /**
     * Render a PNG image containing the answer.
     *
     * Uses a TTF font for larger, more readable characters. Image is small
     * (~110×36 px) so characters can be big relative to the canvas.
     * Each character is placed with random y-offset and slight rotation.
     */
    public function image(string $answer): string
    {
        if (!\function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD extension required for captcha image');
        }
        // Image dimensions — chars need clear breathing room to stay readable.
        $w = 180;
        $h = 44;

        $im = \imagecreatetruecolor($w, $h);
        $bg    = \imagecolorallocate($im, 232, 228, 214);  // paper
        $fg    = \imagecolorallocate($im, 14, 26, 46);     // ink
        $noise = \imagecolorallocate($im, 157, 168, 184); // paper-dim

        // truecolor images default to a BLACK background; explicitly fill with
        // our paper color so characters stay readable.
        \imagefill($im, 0, 0, $bg);

        // Distortion lines (fewer — large chars dominate)
        for ($i = 0; $i < 4; $i++) {
            \imageline(
                $im,
                \random_int(0, $w), \random_int(0, $h),
                \random_int(0, $w), \random_int(0, $h),
                $noise
            );
        }
        // Noise dots
        for ($i = 0; $i < 60; $i++) {
            \imagesetpixel($im, \random_int(0, $w), \random_int(0, $h), $noise);
        }

        // Try TTF first; fall back to built-in font if unavailable.
        $ttfPath = $this->findTtfFont();
        if ($ttfPath !== null && \function_exists('imagettftext')) {
            $fontSize = 22;
            $len = \strlen($answer);
            $y = $h - 6; // baseline near bottom

            // Layout strategy: work in a RELATIVE coordinate space starting
            // at 0, then place the first baseline so the global rendered
            // bbox is exactly centered in the image.
            //
            //   1. Pick each char's rotation; record rotated bbox.
            //   2. Lay out relative baseline positions so the visual gap
            //      between adjacent glyphs equals $gap.
            //   3. Compute the GLOBAL bbox (first glyph llx → last glyph urx).
            //   4. Solve for xFirst so the global bbox is centered:
            //      bbox_center = xFirst + globalLeft + globalW/2 = w/2
            //   5. Draw each char at xFirst + relativeX[i].
            $gap = 6;
            $angles = [];
            $boxes = [];
            for ($i = 0; $i < $len; $i++) {
                $angles[$i] = \random_int(-28, 28);
                $bb = \imagettfbbox($fontSize, $angles[$i], $ttfPath, $answer[$i]);
                $boxes[$i] = $bb !== false ? $bb : [0, 0, 14, 22];
            }

            // Relative baseline positions (anchored at 0)
            $relativeX = [0];
            for ($i = 0; $i < $len - 1; $i++) {
                $advance = $boxes[$i][2] - $boxes[$i + 1][0] + $gap;
                $relativeX[$i + 1] = $relativeX[$i] + $advance;
            }

            // Global bbox (relative to the first baseline)
            $globalLeft  = $boxes[0][0];                              // may be negative
            $globalRight = $relativeX[$len - 1] + $boxes[$len - 1][2];
            $globalW     = $globalRight - $globalLeft;

            // Anchor first baseline so the global bbox is centered.
            //   bbox_left  = xFirst + globalLeft
            //   bbox_right = xFirst + globalRight
            //   bbox_center = xFirst + (globalLeft + globalRight) / 2  =  w / 2
            $xFirst = (int)\round(($w - $globalW) / 2 - $globalLeft);
            if ($xFirst < 4) $xFirst = 4;

            for ($i = 0; $i < $len; $i++) {
                \imagettftext(
                    $im,
                    $fontSize,
                    $angles[$i],
                    $xFirst + (int)$relativeX[$i],
                    (int)$y,
                    $fg,
                    $ttfPath,
                    $answer[$i]
                );
            }
        } else {
            // Fallback: built-in GD font 5 (9×15)
            $x = 6;
            for ($i = 0, $n = \strlen($answer); $i < $n; $i++) {
                \imagechar($im, 5, $x, \random_int(12, 20), $answer[$i], $fg);
                $x += 16;
            }
        }

        \ob_start();
        \imagepng($im);
        $png = (string)\ob_get_clean();
        \imagedestroy($im);
        return $png;
    }

    /**
     * Locate a TTF font usable for captcha. Prefers monospaced fonts
     * (digits/letters align cleanly), falls back to any sans-serif.
     *
     * Returns null if no TTF is available (caller falls back to GD built-in).
     */
    private function findTtfFont(): ?string
    {
        $candidates = [
            'C:/Windows/Fonts/consola.ttf',
            'C:/Windows/Fonts/cour.ttf',
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf',
            '/System/Library/Fonts/Menlo.ttc',
        ];
        foreach ($candidates as $p) {
            if (\is_file($p)) return $p;
        }
        return null;
    }

    /**
     * Compare user input against the stored hash. Always consumes the token
     * (one-shot) — even on failure — so a stale answer can't be reused.
     */
    public function verify(string $input): bool
    {
        $hash = $_SESSION['captcha_hash'] ?? null;
        $exp  = (int)($_SESSION['captcha_expires'] ?? 0);
        unset($_SESSION['captcha_hash'], $_SESSION['captcha_expires']);

        if (!\is_string($hash) || $exp < \time()) {
            return false;
        }
        $expected = $this->hashAnswer(\strtoupper(\trim($input)));
        return \hash_equals($hash, $expected);
    }

    /* ============== Lockout ============== */

    public function isLocked(): bool
    {
        $until = (int)($_SESSION['login_locked_until'] ?? 0);
        return $until > \time();
    }

    public function lockoutRemainingSeconds(): int
    {
        $until = (int)($_SESSION['login_locked_until'] ?? 0);
        return \max(0, $until - \time());
    }

    public function recordFail(): int
    {
        $count = ((int)($_SESSION['login_fail_count'] ?? 0)) + 1;
        $_SESSION['login_fail_count'] = $count;
        if ($count >= self::MAX_FAILS) {
            $_SESSION['login_locked_until'] = \time() + self::LOCK_SECONDS;
        }
        return $count;
    }

    public function clearFails(): void
    {
        unset($_SESSION['login_fail_count'], $_SESSION['login_locked_until']);
    }

    public function maxFails(): int
    {
        return self::MAX_FAILS;
    }

    public function lockSeconds(): int
    {
        return self::LOCK_SECONDS;
    }

    private function hashAnswer(string $answer): string
    {
        return \hash_hmac('sha256', $answer, (string)Config::get('APP_KEY', 'fallback'));
    }
}
