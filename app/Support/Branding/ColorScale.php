<?php

declare(strict_types=1);

namespace App\Support\Branding;

use InvalidArgumentException;

/**
 * The 50 -> 900 tint/shade ramp for one brand colour, in HSV.
 *
 * This is the ui-design-system skill's documented algorithm
 * (scripts/design_token_generator.py::_generate_color_scale), reimplemented in
 * PHP because the palette is chosen at RUNTIME by an operator on
 * /settings/branding and the skill's python generator cannot run inside a
 * request. ColorScaleTest pins this implementation to that generator's actual
 * output for the platform's own brand colour, so the two cannot drift
 * silently.
 *
 * Algorithm, verbatim from the generator:
 *   - hue is constant across the whole ramp;
 *   - value (brightness) is a fixed 0.95 below step 500, and above it decays
 *     as base_value * (1 - (step - 500) / 500), reaching ~20% at step 900;
 *   - saturation scales as base_saturation * (0.3 + 0.7 * step / 900);
 *   - the 0..1 channel is converted to 0..255 by TRUNCATION, not rounding -
 *     the generator does `int(c * 255)`. Rounding instead would put this
 *     implementation up to one unit per channel away from the reference on
 *     most steps.
 *
 * ONE DELIBERATE DIVERGENCE FROM THE GENERATOR. Step 500 is returned EXACTLY
 * as supplied, so the colour a school picked is the colour it gets rather than
 * a re-quantised approximation of it. The generator re-derives step 500
 * through the same saturation scaling as every other step - for #0B5A32 it
 * emits #235A3E, which is visibly not the colour that was typed in - and
 * parks the original under a separate `DEFAULT` key instead. Here step 500 IS
 * that DEFAULT.
 */
final class ColorScale
{
    /** @var list<int> */
    private const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

    /**
     * @return array<int, string>
     */
    public static function of(string $hex): array
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException("[{$hex}] is not a 6-digit hex colour.");
        }

        [$hue, $saturation, $value] = self::toHsv($hex);

        $scale = [];

        foreach (self::STEPS as $step) {
            if ($step === 500) {
                $scale[$step] = strtoupper($hex);

                continue;
            }

            $stepValue = $step < 500
                ? 0.95
                : $value * (1 - ($step - 500) / 500);

            $stepSaturation = $saturation * (0.3 + 0.7 * ($step / 900));

            $scale[$step] = self::fromHsv($hue, min(1.0, $stepSaturation), max(0.0, $stepValue));
        }

        // Steps 800 and 900 exist to be TEXT on a white card. For a light
        // brand colour (a yellow, a pale teal) the proportional falloff
        // leaves them too bright to read, so they are darkened until they
        // clear WCAG AA. Hue and ordering are untouched, and for a colour
        // that already clears it - such as the platform's own Heritage
        // green - this loop is a no-op, which is why the reference fixture
        // in ColorScaleTest still holds.
        foreach ([800, 900] as $textStep) {
            while (ColorContrast::ratio($scale[$textStep], '#FFFFFF') < ColorContrast::AA_NORMAL) {
                $darker = BrandPalette::darken($scale[$textStep], 0.10);

                if ($darker === $scale[$textStep]) {
                    break;
                }

                $scale[$textStep] = $darker;
            }
        }

        return $scale;
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue in degrees, saturation and value in 0..1
     */
    private static function toHsv(string $hex): array
    {
        $r = ((int) hexdec(substr($hex, 1, 2))) / 255;
        $g = ((int) hexdec(substr($hex, 3, 2))) / 255;
        $b = ((int) hexdec(substr($hex, 5, 2))) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        if ($delta === 0.0) {
            $hue = 0.0;
        } elseif ($max === $r) {
            $hue = 60 * fmod(($g - $b) / $delta, 6);
        } elseif ($max === $g) {
            $hue = 60 * ((($b - $r) / $delta) + 2);
        } else {
            $hue = 60 * ((($r - $g) / $delta) + 4);
        }

        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $max === 0.0 ? 0.0 : $delta / $max, $max];
    }

    private static function fromHsv(float $hue, float $saturation, float $value): string
    {
        $c = $value * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $value - $c;

        [$r, $g, $b] = match (true) {
            $hue < 60 => [$c, $x, 0.0],
            $hue < 120 => [$x, $c, 0.0],
            $hue < 180 => [0.0, $c, $x],
            $hue < 240 => [0.0, $x, $c],
            $hue < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        // Truncation, not rounding: the reference generator does
        // `int(c * 255)`.
        return sprintf(
            '#%02X%02X%02X',
            (int) (($r + $m) * 255),
            (int) (($g + $m) * 255),
            (int) (($b + $m) * 255),
        );
    }
}
