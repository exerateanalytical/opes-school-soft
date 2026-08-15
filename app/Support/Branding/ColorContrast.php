<?php

declare(strict_types=1);

namespace App\Support\Branding;

use InvalidArgumentException;

/**
 * WCAG 2.1 relative-luminance contrast, used by /settings/branding to tell a
 * school BEFORE it saves that the colour it just picked cannot be read.
 *
 * A brand picker without this ships unreadable screens: the platform's own
 * Heritage Gold (#D9A829) is a 1.9:1 contrast on white, which is why the
 * design system only ever uses it as an accent - and nothing stopped an
 * operator from choosing it as the primary button colour.
 *
 * Formula: WCAG 2.1 §1.4.3. Channel is normalised to 0..1, linearised
 * (the 0.03928 / 12.92 piecewise sRGB transfer function), weighted
 * 0.2126/0.7152/0.0722, then (L_lighter + 0.05) / (L_darker + 0.05).
 */
final class ColorContrast
{
    /** WCAG AA for normal-size text. */
    public const AA_NORMAL = 4.5;

    /** WCAG AA for large text (>= 18.66px bold or 24px regular). */
    public const AA_LARGE = 3.0;

    public static function ratio(string $foreground, string $background): float
    {
        $l1 = self::relativeLuminance($foreground);
        $l2 = self::relativeLuminance($background);

        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function passesAA(string $foreground, string $background): bool
    {
        return self::ratio($foreground, $background) >= self::AA_NORMAL;
    }

    public static function passesAALarge(string $foreground, string $background): bool
    {
        return self::ratio($foreground, $background) >= self::AA_LARGE;
    }

    private static function relativeLuminance(string $hex): float
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException("[{$hex}] is not a 6-digit hex colour.");
        }

        $channels = [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];

        $linear = array_map(
            static fn (int $channel): float => ($channel / 255) <= 0.03928
                ? ($channel / 255) / 12.92
                : (((($channel / 255) + 0.055) / 1.055) ** 2.4),
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
