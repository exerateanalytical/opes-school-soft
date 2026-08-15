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
        'danger' => '#D64545',
    ];

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
            '--color-success' => $this->get('success'),
            '--color-warning' => $this->get('warning'),
            '--color-danger' => $this->get('danger'),
            '--color-heritage-red' => $this->get('danger'),
        ];
    }
}
