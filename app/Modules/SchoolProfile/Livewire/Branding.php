<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Support\Branding\BrandTokens;
use App\Support\Branding\ColorContrast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * /settings/branding - the school's brand palette, logo and favicon.
 *
 * This screen used to expose ONE colour and derive everything else from it,
 * on the reasoning that a non-designer should not have to hold three shades
 * in relation to each other by eye. That reasoning survives for the CHROME
 * shades (still derived from `secondary`), but it was wrong about the rest:
 * a school with a navy-and-gold identity had no way to say "gold", and the
 * semantic colours were unreachable entirely.
 *
 * So: six picked colours, a curated preset list, a live contrast check
 * against the two text colours the shell actually puts on them, and a
 * preview built from the REAL components (KPI card, table header, button,
 * status pill) rather than swatches - a row of hex chips cannot tell you
 * that your chosen primary makes the table header unreadable.
 *
 * Storage: one JSON key `branding.palette`, plus `branding.primary_color`
 * written as a mirror so the shell layout's existing read keeps working.
 * See BrandTokens' class docblock for why one key rather than six.
 */
#[Layout('layouts.app')]
final class Branding extends Component
{
    /** Kept for the shell layout, which still reads this key. */
    public const SETTING_KEY = 'branding.primary_color';

    public const PALETTE_KEY = 'branding.palette';

    public string $primary = '#0B5A32';

    public string $secondary = '#064A2B';

    public string $accent = '#D9A829';

    public string $success = '#198754';

    public string $warning = '#D99A20';

    public string $danger = '#D64545';

    public function mount(ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->loadPalette($readSetting);
    }

    private function loadPalette(ReadSetting $readSetting): void
    {
        /** @var mixed $stored */
        $stored = $readSetting->handle(self::PALETTE_KEY, BrandTokens::DEFAULTS);

        try {
            $tokens = BrandTokens::fromArray(is_array($stored) ? $stored : BrandTokens::DEFAULTS);
        } catch (Throwable) {
            // A hand-edited palette row must not take the screen that fixes
            // it down with it.
            $tokens = BrandTokens::defaults();
        }

        $this->hydrateFrom($tokens->all());
    }

    /**
     * @param  array<string, string>  $colors
     */
    private function hydrateFrom(array $colors): void
    {
        $this->primary = $colors['primary'];
        $this->secondary = $colors['secondary'];
        $this->accent = $colors['accent'];
        $this->success = $colors['success'];
        $this->warning = $colors['warning'];
        $this->danger = $colors['danger'];
    }

    /**
     * @return array<string, string>
     */
    private function currentColors(): array
    {
        return [
            'primary' => $this->primary,
            'secondary' => $this->secondary,
            'accent' => $this->accent,
            'success' => $this->success,
            'warning' => $this->warning,
            'danger' => $this->danger,
        ];
    }

    public function applyPreset(string $key): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $preset = BrandPreset::find($key);

        if ($preset === null) {
            $this->addError('primary', (string) __('opes.branding.unknown_preset'));

            return;
        }

        $this->resetErrorBag();
        $this->hydrateFrom(BrandTokens::fromArray($preset['colors'])->all());
    }

    public function cancel(ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();
        $this->loadPalette($readSetting);
    }

    public function save(WriteSetting $writeSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();

        try {
            $tokens = BrandTokens::fromArray($this->currentColors());
        } catch (InvalidArgumentException $e) {
            // BrandTokens names the offending token in its message; map it
            // back onto the property so the error lands under the right
            // picker instead of at the top of the page.
            foreach (array_keys(BrandTokens::DEFAULTS) as $token) {
                if (str_contains($e->getMessage(), "[{$token}]")) {
                    $this->addError($token, $e->getMessage());

                    return;
                }
            }

            $this->addError('primary', $e->getMessage());

            return;
        }

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();
        $actor = $user->toAuditActor();

        try {
            // One operator intent, one transaction: the palette and its
            // mirror move together or not at all, so nothing can read a
            // primary_color that disagrees with the palette it came from.
            DB::transaction(function () use ($writeSetting, $tokens, $actor): void {
                $writeSetting->handle(self::PALETTE_KEY, $tokens->all(), $actor);
                $writeSetting->handle(self::SETTING_KEY, $tokens->get('primary'), $actor);
            });
        } catch (RuntimeException $e) {
            $this->addError('primary', $e->getMessage());

            return;
        }

        $this->dispatch('settings-saved');
    }

    /**
     * The pairs the shell ACTUALLY renders, checked against WCAG AA.
     *
     * White-on-primary is the button and the table header; charcoal-on-accent
     * is a gold status pill. Both are real combinations in this codebase, not
     * hypotheticals - which is why the warning is worth showing.
     *
     * @return list<array{token: string, against: string, ratio: float}>
     */
    public function contrastWarnings(): array
    {
        $pairs = [
            ['token' => 'primary', 'against' => '#FFFFFF'],
            ['token' => 'secondary', 'against' => '#FFFFFF'],
            ['token' => 'success', 'against' => '#FFFFFF'],
            ['token' => 'warning', 'against' => '#FFFFFF'],
            ['token' => 'danger', 'against' => '#FFFFFF'],
            ['token' => 'accent', 'against' => '#14201A'],
        ];

        $warnings = [];
        $colors = $this->currentColors();

        foreach ($pairs as $pair) {
            try {
                $ratio = ColorContrast::ratio($colors[$pair['token']], $pair['against']);
            } catch (Throwable) {
                // A half-typed hex mid-keystroke: no warning, no crash.
                continue;
            }

            if ($ratio < ColorContrast::AA_NORMAL) {
                $warnings[] = [
                    'token' => $pair['token'],
                    'against' => $pair['against'],
                    'ratio' => round($ratio, 2),
                ];
            }
        }

        return $warnings;
    }

    /**
     * The CSS custom properties the preview panel paints itself with. Falls
     * back to the built-in defaults on an in-progress hex so the preview
     * holds its last good state rather than throwing mid-keystroke.
     */
    public function previewStyle(): string
    {
        try {
            $vars = BrandTokens::fromArray($this->currentColors())->toCssVariables();
        } catch (Throwable) {
            $vars = BrandTokens::defaults()->toCssVariables();
        }

        $declarations = [];

        foreach ($vars as $name => $value) {
            $declarations[] = $name.': '.$value;
        }

        return implode('; ', $declarations);
    }

    public function render(): mixed
    {
        return view('livewire.schoolprofile.branding', [
            'presets' => BrandPreset::all(),
            'warnings' => $this->contrastWarnings(),
            'previewStyle' => $this->previewStyle(),
        ]);
    }
}
