<?php
declare(strict_types=1);

namespace CTF\Server\Security;

use ZipArchive;

/**
 * Server-side ZIP safety validator — used by `bin/seed-challenge.php`
 * and (eventually) the full upload UI from §2.4.
 *
 * Defends against:
 *   - Zip Slip (relative paths like `../../etc/passwd`)
 *   - Absolute paths (POSIX or Windows)
 *   - Symlink entries
 *   - Files that escape the ZIP root after resolution
 *
 * Returns ['ok' => bool, 'reason' => ?string, 'detail' => ?string].
 */
final class ZipValidator
{
    public function validate(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'reason' => 'cannot_open_zip', 'detail' => null];
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                // Reject absolute paths.
                if (str_starts_with($name, '/') || preg_match('#^[A-Z]:[\\\\/]#i', $name)) {
                    return ['ok' => false, 'reason' => 'absolute_path', 'detail' => $name];
                }
                // Reject `..` segments that escape root.
                $normalized = str_replace('\\', '/', $name);
                $parts = explode('/', $normalized);
                $depth = 0;
                foreach ($parts as $p) {
                    if ($p === '..') {
                        $depth--;
                        if ($depth < 0) {
                            return ['ok' => false, 'reason' => 'path_escape', 'detail' => $name];
                        }
                    } elseif ($p !== '.' && $p !== '') {
                        $depth++;
                    }
                }
                // Reject symlink entries: statIndex returns no symlink flag
                // for ZipArchive, but external_attr high bytes hold the mode.
                // 0xA0000000 == symlink. We don't actually use symlinks in
                // challenge packages, so reject the entry if present.
                $attrs = $stat['external_attr'] ?? 0;
                $mode = ($attrs >> 16) & 0xFFFF;
                if (($mode & 0xF000) === 0xA000) {
                    return ['ok' => false, 'reason' => 'symlink', 'detail' => $name];
                }
            }
            return ['ok' => true, 'reason' => null, 'detail' => null];
        } finally {
            $zip->close();
        }
    }
}
