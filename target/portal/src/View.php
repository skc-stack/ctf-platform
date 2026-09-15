<?php
declare(strict_types=1);

namespace CTF\Portal;

/**
 * Minimal view renderer — plain PHP includes, no template language.
 *
 * Each view is a PHP file that returns a string. The render() method
 * extracts the vars into the view's local scope and captures output.
 */
final class View
{
    private const VIEWS_DIR = __DIR__ . '/../views';

    /**
     * @param array<string,mixed> $vars
     */
    public static function render(string $template, array $vars = []): string
    {
        $file = self::VIEWS_DIR . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            return "[View missing: $template]";
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}
