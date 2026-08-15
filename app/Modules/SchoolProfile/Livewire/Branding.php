<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Support\Branding\BrandTokens;
use App\Support\Branding\ColorContrast;
use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
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
    use WithFileUploads;

    /** Kept for the shell layout, which still reads this key. */
    public const SETTING_KEY = 'branding.primary_color';

    public const PALETTE_KEY = 'branding.palette';

    public string $primary = '#0B5A32';

    public string $secondary = '#064A2B';

    public string $accent = '#D9A829';

    public string $success = '#198754';

    public string $warning = '#D99A20';

    public string $danger = '#CC3D3D';

    public string $appLogoPath = '';

    public string $faviconPath = '';

    public ?TemporaryUploadedFile $appLogoUpload = null;

    public ?TemporaryUploadedFile $faviconUpload = null;

    /**
     * app-shell slot => [upload property, path property, setting key].
     *
     * These two are settings keys rather than columns on
     * school_document_profiles ON PURPOSE: that table is the DOCUMENT
     * profile, and every column in it is frozen into render_envelope on every
     * issued document. The shell logo and the favicon are never printed on
     * anything, so freezing them onto every certificate a school ever issues
     * would be pure noise.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SHELL_SLOTS = [
        'app-logo' => ['appLogoUpload', 'appLogoPath', 'branding.app_logo_path'],
        'favicon' => ['faviconUpload', 'faviconPath', 'branding.favicon_path'],
    ];

    public function mount(ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->loadPalette($readSetting);

        $this->appLogoPath = (string) $readSetting->handle('branding.app_logo_path', '');
        $this->faviconPath = (string) $readSetting->handle('branding.favicon_path', '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $image = [
            'nullable', 'image',
            'mimes:'.implode(',', StoredImage::ALLOWED_EXTENSIONS),
            'max:'.StoredImage::MAX_KILOBYTES,
        ];

        return [
            'appLogoUpload' => [
                ...$image,
                'dimensions:max_width='.StoredImage::MAX_DIMENSION.',max_height='.StoredImage::MAX_DIMENSION,
            ],
            // A favicon is drawn at 16-32 px; anything above 512 is a
            // full-size logo pasted into the wrong slot.
            'faviconUpload' => [...$image, 'dimensions:max_width=512,max_height=512'],
        ];
    }

    /**
     * The image path each shell slot holds in the SETTINGS store right now.
     *
     * @return array<string, string>
     */
    private function persistedShellPaths(ReadSetting $readSetting): array
    {
        $paths = [];

        foreach (self::SHELL_SLOTS as $slot => [, , $settingKey]) {
            $paths[$slot] = (string) $readSetting->handle($settingKey, '');
        }

        return $paths;
    }

    public function removeAppLogo(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->appLogoUpload = null;
        $this->appLogoPath = '';
    }

    public function removeFavicon(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->faviconUpload = null;
        $this->faviconPath = '';
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

        // Cancel means "put it back", including a pending upload the operator
        // chose but has not saved.
        $this->appLogoUpload = null;
        $this->faviconUpload = null;
        $this->appLogoPath = (string) $readSetting->handle('branding.app_logo_path', '');
        $this->faviconPath = (string) $readSetting->handle('branding.favicon_path', '');
    }

    public function save(WriteSetting $writeSetting, ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();

        // Validate the uploads BEFORE anything reaches the disk: a refused
        // file must leave no trace at all.
        $this->validate();

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

        $persisted = $this->persistedShellPaths($readSetting);

        try {
            // One operator intent, one transaction: the palette and its
            // mirror move together or not at all, so nothing can read a
            // primary_color that disagrees with the palette it came from.
            DB::transaction(function () use ($writeSetting, $persisted, $tokens, $actor): void {
                $writeSetting->handle(self::PALETTE_KEY, $tokens->all(), $actor);
                $writeSetting->handle(self::SETTING_KEY, $tokens->get('primary'), $actor);

                foreach (self::SHELL_SLOTS as $slot => [$uploadProperty, $pathProperty, $settingKey]) {
                    // What the SETTING held, not what the property holds:
                    // removeAppLogo()/removeFavicon() have already blanked the
                    // property, so an in-memory comparison would report
                    // "nothing was there" and orphan the cleared file forever.
                    $previous = $persisted[$slot];
                    $upload = $this->{$uploadProperty};

                    if ($upload instanceof TemporaryUploadedFile) {
                        $this->{$pathProperty} = StoredImage::putContents(
                            $slot,
                            (string) file_get_contents((string) $upload->getRealPath()),
                            strtolower($upload->getClientOriginalExtension()),
                        );

                        $upload->delete();
                        $this->{$uploadProperty} = null;
                    }

                    $writeSetting->handle($settingKey, (string) $this->{$pathProperty}, $actor);

                    // Shell branding carries none of the document platform's
                    // reproducibility burden - no issued artefact freezes
                    // these paths - so the old file goes as soon as the
                    // setting that pointed at it has moved.
                    StoredImage::forget($previous, (string) $this->{$pathProperty});
                }
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
     * The check used to measure every semantic colour against WHITE, which
     * was the wrong pair twice over. Amber is never body text at its vivid
     * value - it is a FILL - so flagging it on white warned a school about a
     * colour it had not picked and could not fix. Meanwhile the pair that IS
     * rendered, amber on its own #FFF5D9 tint at 2.25:1, went unmeasured.
     *
     * So each role is now checked against the background it renders on:
     *   - SURFACE roles carry white text (the primary button, the table
     *     header, the sidebar active row, a solid success/danger pill), so
     *     they are measured against white;
     *   - the accent is a gold pill with charcoal on it;
     *   - the derived TEXT roles are measured against BOTH white and their
     *     own tint, which is where inline errors, status pills and KPI
     *     captions actually sit.
     *
     * `warning` has no surface entry on purpose: nothing in this codebase
     * puts white text on a solid amber fill.
     *
     * @return list<array{token: string, against: string, ratio: float}>
     */
    public function contrastWarnings(): array
    {
        /** @var list<array{token: string, against: string, text: bool}> $pairs */
        $pairs = [
            ['token' => 'primary', 'against' => '#FFFFFF', 'text' => false],
            ['token' => 'secondary', 'against' => '#FFFFFF', 'text' => false],
            ['token' => 'success', 'against' => '#FFFFFF', 'text' => false],
            ['token' => 'danger', 'against' => '#FFFFFF', 'text' => false],
            ['token' => 'accent', 'against' => '#14201A', 'text' => false],
        ];

        foreach (array_keys(BrandTokens::TINTS) as $semantic) {
            $pairs[] = ['token' => $semantic, 'against' => '#FFFFFF', 'text' => true];
            $pairs[] = ['token' => $semantic, 'against' => BrandTokens::TINTS[$semantic], 'text' => true];
        }

        try {
            $tokens = BrandTokens::fromArray($this->currentColors());
        } catch (Throwable) {
            // A half-typed hex mid-keystroke: no warning, no crash. save()
            // is what reports a genuinely malformed value, and it puts the
            // message under the picker that owns it.
            return [];
        }

        $warnings = [];

        foreach ($pairs as $pair) {
            // Text roles are derived from the picked colour, so this measures
            // the shade that will really be painted rather than the vivid one
            // it came from.
            $color = $pair['text']
                ? $tokens->textRole($pair['token'])
                : $tokens->get($pair['token']);

            $ratio = ColorContrast::ratio($color, $pair['against']);

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
