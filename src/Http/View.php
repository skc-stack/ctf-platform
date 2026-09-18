<?php
declare(strict_types=1);

namespace CTF\Server\Http;

/**
 * Minimal PHP view renderer. Renders views/<template>.php with $vars,
 * optionally wrapped in views/layouts/<layout>.php with $content.
 *
 * The template echoes into $content buffer; the layout prints it.
 */
final class View
{
    public static function render(string $template, array $vars = [], ?string $layout = null): string
    {
        $viewDir = realpath(__DIR__ . '/../../views');
        $templatePath = $viewDir . '/' . $template . '.php';
        if (!is_file($templatePath)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        // Extract vars to local scope
        extract($vars, EXTR_SKIP);

        // Render inner template into buffer
        ob_start();
        try {
            require $templatePath;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $content = (string)ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        $layoutPath = $viewDir . '/layouts/' . $layout . '.php';
        if (!is_file($layoutPath)) {
            throw new \RuntimeException("Layout not found: {$layout}");
        }

        // Render layout with $content
        $title = $vars['title'] ?? 'CTF LAB';
        ob_start();
        try {
            require $layoutPath;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
