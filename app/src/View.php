<?php

declare(strict_types=1);

namespace Luna;

use Luna\Http\Response;
use RuntimeException;

/**
 * Plain PHP templates from app/views. Values are escaped in the templates with
 * e(); nothing is auto-escaped here, so the rule is simply that every template
 * wraps every interpolated value.
 */
final class View
{
    private static ?string $directory = null;

    public static function directory(): string
    {
        return self::$directory ??= dirname(__DIR__) . '/views';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        $__lunaFile = self::directory() . '/' . $template . '.php';

        if (!is_file($__lunaFile)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $__lunaFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Render a template inside the given layout and wrap it in a response.
     *
     * @param array<string, mixed> $data
     */
    public static function page(string $template, array $data = [], int $status = 200, string $layout = 'layout'): Response
    {
        $content = self::render($template, $data);

        return Response::html(
            self::render($layout, [...$data, 'content' => $content]),
            $status
        );
    }
}
