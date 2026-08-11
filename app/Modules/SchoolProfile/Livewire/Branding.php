<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Branding\BrandPalette;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * /settings/branding - the school's ONE brand-colour choice.
 *
 * Deliberately narrow: this is a single-tenant-per-deployment platform
 * (school_document_profiles is a singleton, like fiscal_identities), so
 * "branding" here means the one school running this install choosing its
 * own colour instead of the built-in Heritage green - not a multi-tenant
 * theme system. `BrandPalette` derives the sidebar/active-state shades from
 * that one value so the shell stays visually coherent; this screen exposes
 * only the picker, not three separate colour inputs a non-designer would
 * have to keep in relation to each other by eye.
 */
#[Layout('layouts.app')]
final class Branding extends Component
{
    public const SETTING_KEY = 'branding.primary_color';

    public string $primaryColor = '#0B5A32';

    public function mount(ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit);

        $this->primaryColor = (string) $readSetting->handle(self::SETTING_KEY, '#0B5A32');
    }

    public function save(WriteSetting $writeSetting): void
    {
        Gate::authorize(Permission::SettingEdit);

        $this->resetErrorBag();

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        try {
            $writeSetting->handle(self::SETTING_KEY, $this->primaryColor, $user->toAuditActor());
        } catch (ValidationException $e) {
            $this->addError('primaryColor', implode(' ', $e->validator->errors()->get('value')));

            return;
        } catch (RuntimeException $e) {
            $this->addError('primaryColor', $e->getMessage());

            return;
        }

        session()->flash('status', __('opes.branding.saved'));
    }

    public function resetToHeritageGreen(): void
    {
        $this->primaryColor = '#0B5A32';
    }

    /**
     * @return array{chrome: string, chromeLight: string, primary: string}|null
     */
    public function previewPalette(): ?array
    {
        try {
            return BrandPalette::fromPrimary($this->primaryColor);
        } catch (Throwable) {
            // An in-progress, not-yet-valid hex from manual typing in the
            // text fallback - the preview simply holds its last good state
            // rather than throwing mid-keystroke.
            return null;
        }
    }

    public function render(): mixed
    {
        return view('livewire.schoolprofile.branding', [
            'preview' => $this->previewPalette(),
        ]);
    }
}
