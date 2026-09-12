<?php

declare(strict_types=1);

namespace Luna;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Inline SVG QR codes, used once: to enrol the admin's authenticator app.
 *
 * Rendered inline rather than as an image URL so the secret never becomes a
 * request that could be logged, cached or shared.
 */
final class Qr
{
    public static function svg(string $text, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));
        $svg = $writer->writeString($text);

        // Drop the XML prolog so the fragment can sit inside an HTML document.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
