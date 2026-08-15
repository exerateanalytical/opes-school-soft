<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

/**
 * Curated brand palettes a school can pick in one click on
 * /settings/branding.
 *
 * The point is not variety: it is that a bursar with no design training
 * gets a coherent, ACCESSIBLE result without picking six hex values by eye.
 * Every preset's primary clears WCAG AA on white (asserted in
 * BrandPresetTest) - the platform must never offer a palette its own
 * contrast warning would flag.
 *
 * Semantic colours (success/warning/danger) deliberately stay near the
 * defaults in every preset: a red "danger" that is actually green because a
 * school likes green is a safety problem, not a branding choice.
 */
final class BrandPreset
{
    /**
     * @return list<array{key: string, label: string, colors: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'heritage',
                'label' => 'Heritage Green',
                'colors' => [
                    'primary' => '#0B5A32', 'secondary' => '#064A2B', 'accent' => '#D9A829',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
            [
                'key' => 'indigo',
                'label' => 'Indigo',
                'colors' => [
                    'primary' => '#31408C', 'secondary' => '#232F66', 'accent' => '#E0A32E',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
            [
                'key' => 'burgundy',
                'label' => 'Burgundy',
                'colors' => [
                    'primary' => '#8A1F3D', 'secondary' => '#6B152E', 'accent' => '#C9A227',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
            [
                'key' => 'teal',
                'label' => 'Deep Teal',
                'colors' => [
                    'primary' => '#0F5C63', 'secondary' => '#0A464C', 'accent' => '#D9A829',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
            [
                'key' => 'navy',
                'label' => 'Navy & Gold',
                'colors' => [
                    'primary' => '#1B3A6B', 'secondary' => '#132B50', 'accent' => '#D9A829',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
            [
                'key' => 'slate',
                'label' => 'Graphite',
                'colors' => [
                    'primary' => '#3A4750', 'secondary' => '#2A343B', 'accent' => '#C98A2E',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
            [
                'key' => 'plum',
                'label' => 'Plum',
                'colors' => [
                    'primary' => '#5B2A6B', 'secondary' => '#451F52', 'accent' => '#D9A829',
                    'success' => '#198754', 'warning' => '#D99A20', 'danger' => '#D64545',
                ],
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, colors: array<string, string>}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        return null;
    }
}
