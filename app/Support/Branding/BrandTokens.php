<?php

declare(strict_types=1);

namespace App\Support\Branding;

use InvalidArgumentException;

/**
 * The school's brand palette: the six colours a school actually chooses,
 * validated as a UNIT and stored as one JSON settings key
 * (`branding.palette`).
 *
 * One key rather than six: the palette's validation is cross-field (whether
 * `primary` is readable on white is not a property of `primary` alone), and
 * WriteSetting writes one key, one transaction and one audit row per call -
 * six calls would mean six audit rows for one operator intent and a
 * half-applied palette if the fourth throws. `branding.primary_color` is
 * still written alongside it as a mirror, because the shell layout and the
 * existing Branding screen already read that key.
 *
 * The three CHROME shades stay DERIVED (BrandPalette::shade) rather than
 * picked: a non-designer choosing a sidebar colour, an active-state colour
 * and a button colour independently produces a shell that reads as three
 * brands. The school picks primary and secondary; the relationship between
 * them is the platform's job.
 */
final readonly class BrandTokens
{
    /**
     * The built-in Heritage values - the palette every install starts on.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'primary' => '#0B5A32',    // buttons, links
        'secondary' => '#064A2B',  // sidebar active surface; chrome derives from it
        'accent' => '#D9A829',     // Heritage Gold - accents only
        'success' => '#198754',
        'warning' => '#D99A20',
        'danger' => '#CC3D3D',
    ];

    /**
     * The static tint each semantic colour is rendered ON when it is used as
     * TEXT rather than as a fill - `bg-warning-bg text-warning-text`, which
     * portal/row, portal/icon, the status pills, the KPI cards and every
     * inline validation error render.
     *
     * These mirror `--color-*-bg` in resources/css/app.css and are
     * deliberately NOT brandable: they are ~4% saturation washes chosen so
     * charcoal body text stays readable on them, and letting a school repaint
     * them would break that (asserted in PaletteAccessibilityTest).
     *
     * @var array<string, string>
     */
    public const TINTS = [
        'success' => '#EAF6EF',
        'warning' => '#FFF5D9',
        'danger' => '#FDECEC',
    ];

    /**
     * The contrast a derived TEXT role must reach on white AND on its tint.
     *
     * Above the 4.5 AA floor on purpose. Landing a shipped token exactly ON
     * 4.50 makes AA compliance a property of float rounding; the extra 0.15
     * costs a barely perceptible darkening and buys a margin that survives a
     * tint being nudged later.
     */
    private const TEXT_ROLE_TARGET = 4.65;

    /**
     * @param  array<string, string>  $colors  keyed exactly like DEFAULTS
     */
    private function __construct(private array $colors)
    {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $colors = [];

        foreach (self::DEFAULTS as $token => $default) {
            /** @var mixed $value */
            $value = $input[$token] ?? $default;

            if (! is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
                throw new InvalidArgumentException(
                    "Brand token [{$token}] must be a 6-digit hex colour such as #0B5A32."
                );
            }

            $colors[$token] = strtoupper($value);
        }

        return new self($colors);
    }

    public static function defaults(): self
    {
        return self::fromArray(self::DEFAULTS);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->colors;
    }

    public function get(string $token): string
    {
        return $this->colors[$token] ?? self::DEFAULTS[$token];
    }

    /**
     * The readable TEXT shade of a semantic colour.
     *
     * A semantic colour is used in two roles and only one of them was ever
     * checked. As a solid FILL - a red "Overdue" pill, an amber chart mark,
     * an icon circle - the vivid value is right and white on it passes. As
     * TEXT it failed, badly and in the places people actually read: Heritage
     * amber measures 2.25:1 on its own tint, success 4.08:1 on its, danger
     * 4.27:1 on its.
     *
     * The fix is NOT to darken the surface colour. That would repaint the
     * product's character - vivid amber to dark brown - on every badge, icon
     * and chart fill where the colour carries no text and is perfectly
     * legible. Material, Radix and Tailwind all separate these two roles, and
     * so does this now.
     *
     * DERIVED rather than three more picked hexes, because the palette is
     * user-editable: a school that types a pale amber into the warning picker
     * must not thereby get unreadable body text, and a hard-coded text role
     * would silently keep pointing at the OLD colour's shade. Darkening
     * toward black scales all three channels by the same factor, so the hue
     * the school chose survives; only the lightness moves, and only as far as
     * AA requires.
     */
    public function textRole(string $token): string
    {
        if (! array_key_exists($token, self::TINTS)) {
            throw new InvalidArgumentException(
                "Brand token [{$token}] has no text role; only ".implode(', ', array_keys(self::TINTS)).' do.'
            );
        }

        $tint = self::TINTS[$token];
        $base = $this->get($token);

        // 1% steps toward black. Bounded and always satisfied: at step 100 the
        // candidate is black, which clears the target on white and on every
        // tint, so the loop cannot walk off the end in practice.
        for ($step = 0; $step <= 100; $step++) {
            $candidate = $step === 0 ? $base : BrandPalette::darken($base, $step / 100);

            if (ColorContrast::ratio($candidate, '#FFFFFF') >= self::TEXT_ROLE_TARGET
                && ColorContrast::ratio($candidate, $tint) >= self::TEXT_ROLE_TARGET) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * The CSS custom properties the shell layout emits into an UNLAYERED
     * <style> block in <head>.
     *
     * Unlayered matters: Tailwind 4 compiles utilities into @layer utilities,
     * and unlayered CSS outranks every layered rule regardless of
     * specificity. A @layer components version of this ships as a silent
     * no-op that measures correctly in devtools and repaints nothing.
     *
     * @return array<string, string>
     */
    public function toCssVariables(): array
    {
        $secondary = $this->get('secondary');

        return [
            // The sidebar body: a darker step below the secondary, the same
            // relationship Heritage Dark Green (#002D17) has to Heritage
            // Forest Green (#064A2B).
            '--color-chrome' => BrandPalette::darken($secondary, 0.35),
            '--color-chrome-light' => $secondary,
            '--color-primary' => $this->get('primary'),
            '--color-heritage-yellow' => $this->get('accent'),
            // The SURFACE role: solid pills, icon circles, chart fills,
            // borders. Stays exactly as vivid as the school picked it.
            '--color-success' => $this->get('success'),
            '--color-warning' => $this->get('warning'),
            '--color-danger' => $this->get('danger'),
            '--color-heritage-red' => $this->get('danger'),
            // The TEXT role: the same hue, darkened only as far as AA needs
            // on white and on its own tint. See textRole().
            '--color-success-text' => $this->textRole('success'),
            '--color-warning-text' => $this->textRole('warning'),
            '--color-danger-text' => $this->textRole('danger'),
        ];
    }
}
