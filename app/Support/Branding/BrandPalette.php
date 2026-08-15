<?php

declare(strict_types=1);

namespace App\Support\Branding;

use InvalidArgumentException;

/**
 * Derives the shell's chrome/chrome-light/primary trio from the ONE colour a
 * school picks (`branding.primary_color`). A colour picker that only ever
 * set `--color-primary` would leave the sidebar and its active-state shade
 * mismatched against whatever the school chose - this derives all three
 * from a single input so the shell reads as one coherent brand, the same
 * relationship the built-in Heritage palette has (chrome darker than
 * chrome-light darker than primary).
 */
final class BrandPalette
{
    /**
     * @return array{chrome: string, chromeLight: string, primary: string}
     */
    public static function fromPrimary(string $hex): array
    {
        self::assertHex($hex);

        return [
            // The sidebar body: noticeably darker than the picked colour,
            // the same relationship Heritage Dark Green (#002D17) has to
            // Heritage Green (#0B5A32).
            'chrome' => self::shade($hex, -0.45),
            // Active/hover surface on the sidebar: a lighter step, between
            // chrome and the picked colour.
            'chromeLight' => self::shade($hex, -0.20),
            // Buttons and links: the colour exactly as picked.
            'primary' => strtoupper($hex),
        ];
    }

    private static function assertHex(string $hex): void
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException("[{$hex}] is not a 6-digit hex colour.");
        }
    }

    /**
     * Darken toward black by $fraction (0..1). Public because BrandTokens
     * derives the sidebar chrome from the school's secondary colour and must
     * use the SAME relationship the built-in Heritage palette has, rather
     * than a second, subtly different shading rule of its own.
     */
    public static function darken(string $hex, float $fraction): string
    {
        self::assertHex($hex);

        return self::shade($hex, -abs($fraction));
    }

    /**
     * A negative $amount darkens toward black; this never lightens, because
     * the two derived shades only ever need to sit BELOW the picked colour.
     */
    private static function shade(string $hex, float $amount): string
    {
        // sscanf's %x is unsupported in PHP - hexdec on each 2-char slice is
        // the reliable way to parse a #RRGGBB triplet.
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        $apply = static fn (int $channel): int => (int) round(
            $amount < 0 ? $channel * (1 + $amount) : $channel + (255 - $channel) * $amount
        );

        return sprintf('#%02X%02X%02X', $apply($r), $apply($g), $apply($b));
    }
}
