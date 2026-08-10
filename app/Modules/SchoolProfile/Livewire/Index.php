<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Modules\SchoolProfile\Domain\SettingClass;
use App\Modules\SchoolProfile\Domain\SettingType;
use App\Modules\SchoolProfile\Models\Setting;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Settings browser at /settings (route wired centrally), gated
 * `setting.view`. Listing of the generic engine-configuration key/value
 * store (app/Modules/SchoolProfile/Models/Setting.php): every setting
 * grouped/filterable by setting_class, showing its typed value, scope,
 * lock status and last-updated metadata.
 *
 * Inline per-row editing (unlocked rows only) is gated `setting.edit` and
 * goes through WriteSetting's Actor + validation + audit trail contract;
 * locked rows stay read-only (WriteSetting itself refuses to touch them).
 *
 * DB::table queries only, one paginated query per render (00-core 6.2
 * rule 8, enforced by x-list-screen); no unbounded collection reaches
 * the view.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $settingClass = '';

    #[Url]
    public string $scope = '';

    #[Url]
    public string $locked = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Inline row edit ─────────────────────────────────────────────────
    public ?int $editingSettingId = null;

    public string $editValue = '';

    public bool $editValueBool = false;

    public function mount(): void
    {
        Gate::authorize(Permission::SettingView);
    }

    public function resetFilters(): void
    {
        $this->reset(['settingClass', 'scope', 'locked', 'search']);
        $this->resetPage();
    }

    public function updatedSettingClass(): void
    {
        $this->resetPage();
    }

    public function updatedScope(): void
    {
        $this->resetPage();
    }

    public function updatedLocked(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return DB::table('settings as s')
            ->leftJoin('users as u', 'u.id', '=', 's.updated_by')
            ->when($this->settingClass !== '', fn ($q) => $q->where('s.setting_class', $this->settingClass))
            ->when($this->scope !== '', fn ($q) => $q->where('s.scope', $this->scope))
            ->when($this->locked === 'locked', fn ($q) => $q->whereNotNull('s.locked_at'))
            ->when($this->locked === 'unlocked', fn ($q) => $q->whereNull('s.locked_at'))
            ->when($this->search !== '', function ($q): void {
                $q->where('s.key', 'like', '%'.$this->search.'%');
            })
            ->orderBy('s.setting_class')
            ->orderBy('s.key')
            ->select([
                's.id', 's.key', 's.value', 's.default_value', 's.value_type',
                's.setting_class', 's.scope', 's.scope_id', 's.validation_rule',
                's.locked_at', 's.locked_reason', 's.updated_by', 's.updated_at',
                'u.name as updated_by_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Dataset-wide KPI strip: total settings, locked settings, settings
     * still at their seeded default value.
     *
     * @return array{total: int, locked: int, at_default: int}
     */
    private function kpis(): array
    {
        $total = (int) DB::table('settings')->count();
        $locked = (int) DB::table('settings')->whereNotNull('locked_at')->count();
        $atDefault = (int) DB::table('settings')
            ->whereColumn('value', 'default_value')
            ->count();

        return [
            'total' => $total,
            'locked' => $locked,
            'at_default' => $atDefault,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function classOptions(): array
    {
        $options = [];

        foreach (SettingClass::cases() as $case) {
            $options[] = ['value' => $case->value, 'label' => ucwords(str_replace('_', ' ', $case->value))];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function scopeOptions(): array
    {
        $scopes = [];

        foreach (DB::table('settings')->distinct()->orderBy('scope')->pluck('scope') as $scope) {
            /** @var string $scope */
            $scopes[] = $scope;
        }

        return $scopes;
    }

    public function startEdit(int $settingId): void
    {
        Gate::authorize(Permission::SettingEdit);

        $setting = Setting::query()->findOrFail($settingId);

        if ($setting->isLocked()) {
            return;
        }

        $this->resetErrorBag();
        $this->editingSettingId = $settingId;

        $typed = $setting->typedValue();

        if ($setting->type() === SettingType::Bool) {
            $this->editValueBool = (bool) $typed;
            $this->editValue = '';
        } elseif ($setting->type() === SettingType::Json) {
            $this->editValue = json_encode($typed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
        } else {
            $this->editValue = (string) $typed;
        }
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingSettingId', 'editValue', 'editValueBool']);
        $this->resetErrorBag();
    }

    public function saveEditedSetting(WriteSetting $writeSetting): void
    {
        Gate::authorize(Permission::SettingEdit);

        if ($this->editingSettingId === null) {
            return;
        }

        $setting = Setting::query()->findOrFail($this->editingSettingId);

        $value = match ($setting->type()) {
            SettingType::Bool => $this->editValueBool,
            SettingType::Int => is_numeric($this->editValue) ? (int) $this->editValue : $this->editValue,
            SettingType::Json => $this->decodeJsonEdit($this->editValue),
            SettingType::String => $this->editValue,
        };

        if ($setting->type() === SettingType::Json && $value === false) {
            $this->addError('editValue', 'Enter valid JSON.');

            return;
        }

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        try {
            $writeSetting->handle(
                $setting->key,
                $value,
                $user->toAuditActor(),
                $setting->scope,
                $setting->scope_id,
            );
        } catch (RuntimeException $e) {
            $this->addError('editValue', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('editValue', implode(' ', $e->validator->errors()->get('value')));

            return;
        }

        $this->reset(['editingSettingId', 'editValue', 'editValueBool']);
        session()->flash('status', 'Setting updated.');
    }

    /**
     * Decodes a textarea's raw JSON into a PHP value, or false on failure
     * (json_decode's own sentinel doubles as "invalid" here since a
     * setting value of literal boolean `false` is handled via the Bool
     * type branch, never routed through this method).
     */
    private function decodeJsonEdit(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
    }

    public function render(): mixed
    {
        $classCounts = [];

        foreach (DB::table('settings')->groupBy('setting_class')->select(['setting_class', DB::raw('COUNT(*) as n')])->get() as $row) {
            /** @var object{setting_class: string, n: int|string} $row */
            $classCounts[$row->setting_class] = (int) $row->n;
        }

        return view('livewire.schoolprofile.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'classOptions' => $this->classOptions(),
            'scopeOptions' => $this->scopeOptions(),
            'classCounts' => $classCounts,
            'valueTypes' => SettingType::cases(),
        ]);
    }
}
