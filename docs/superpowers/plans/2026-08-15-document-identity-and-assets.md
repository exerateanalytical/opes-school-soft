# Document Identity, Branding and Asset Labelling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give OPES School an industry-standard settings and branding shell, then use it to put the school's real marks (logo, crest, signatures, stamp, watermark) onto every printed document, add scannable asset labels, add a pre-issue document preview, and remove the inert controls on the student profile.

**Architecture:** Seven phases in strict dependency order. Phase 0 rebuilds the settings hub, a reusable settings-form pattern and a real multi-colour branding system, because every later phase adds fields to those same screens. Phase 1 adds the codebase's first Livewire file uploads (content-hashed filenames on the `public` disk) and renders those images into documents as **base64 data URIs**, because `DompdfRenderer` sets `setIsRemoteEnabled(false)` and cannot fetch a URL. Phase 2 adds a configurable school watermark as a **second, independent** watermark layer so it can coexist with the derived status watermark (DUPLICATA/ANNULÉ/SPECIMEN). Phase 3 adds asset tag barcodes and CR80 labels, mirroring `AdmissionNumber`'s round-trip discipline. Phase 4 adds a preview path through `RenderDocument` that shares the exact same template/payload assembly as issue. Phase 5 kills the seven inert student-profile tabs. Phase 6 settles A3/LETTER.

The load-bearing invariant across all of it: **watermarks and output overlays are never part of the hashed artefact**, and **the school chrome frozen into `render_envelope`/`payload_snapshot` at issue must resolve to the same bytes forever**. That is why uploaded images are stored under a content-hashed filename — replacing a signature produces a *new path*, so a frozen path can never silently change its bytes.

**Tech Stack:** Laravel 13, Livewire 4 (`WithFileUploads`), Tailwind 4, dompdf, MySQL 8.4.3, `picqer/php-barcode-generator` (already in `vendor`, referenced nowhere yet), Pest + `RefreshDatabase` against real MySQL, PHPStan level 8 with zero `ignoreErrors`.

---

## Non-negotiable repo rules (read before Task 1)

- **PHP is not on PATH.** Every command below uses `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. Shorthand used in this plan: `$PHP`. In Git Bash: `PHP='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'`.
- **Tests:** real MySQL, never two suites at once. Prefix every test command with `DB_DATABASE=opeschool_test_verify`. A first migrate on a fresh DB takes 10–20 minutes — background it and wait.
- **PHPStan:** level 8, zero `ignoreErrors`, baseline ~268 pre-existing errors. **New/changed files must add ZERO.** Scope runs to changed paths only.
- **Module boundaries** (`tests/Architecture/ModuleBoundaryTest.php`): no cross-module Model imports; cross-module reads go through `DB::table`; Actions are the only cross-module doors.
- **Migrations:** unique dated filenames. This plan owns the range `2026_08_15_440001` … `2026_08_15_440020`.
- **Blade/CSS changes need `npm run build`.**
- **`git add` exact paths only, never `-A`.**
- **Every NEW Livewire component in `app/Modules/*/Livewire` MUST be registered by name in `app/Providers/AppServiceProvider.php`** (e.g. `Livewire::component('settings.hub', SettingsHub::class)`). Without it the class resolves to a nonsense name and 500s with `ComponentNotFoundException`. This has bitten this codebase twice.
- **Root font-size is 17px**, so Tailwind spacing names lie: `w-72` renders 306px, `text-base` is 17px. Never reason about pixel sizes from the utility name.
- **Tailwind 4 layering:** utilities compile into `@layer utilities`, and **unlayered CSS outranks every layered rule regardless of specificity**. Brand overrides must therefore be emitted as an *unlayered* `<style>:root{…}</style>` in the layout head (which is what `resources/views/layouts/app.blade.php` already does). A `@layer components` override ships as a silent no-op.

## File Structure

**Phase 0 — settings shell and branding**
- Create `app/Modules/SchoolProfile/Domain/SettingsCatalogue.php` — pure static metadata for the settings hub cards (route name, permission, icon, lang keys). No DB.
- Create `app/Modules/SchoolProfile/Livewire/SettingsHub.php` — the new `/settings` landing screen; computes per-card state summaries.
- Create `resources/views/livewire/schoolprofile/settings-hub.blade.php`.
- Create `resources/views/components/settings-form.blade.php` — the shared form shell (sticky save bar, dirty state, unsaved-changes guard, toast).
- Create `resources/views/components/settings-fieldset.blade.php` — a titled section with helper text.
- Create `resources/views/components/settings-field.blade.php` — label + helper text + inline error.
- Create `app/Support/Branding/BrandTokens.php` — validated multi-colour palette value object.
- Create `app/Support/Branding/ColorContrast.php` — WCAG relative-luminance contrast ratio.
- Create `app/Modules/SchoolProfile/Domain/BrandPreset.php` — the curated preset palettes.
- Create `database/migrations/2026_08_15_440001_seed_branding_palette_settings.php`.
- Modify `app/Support/Branding/BrandPalette.php` — add `fromTokens()`, keep `fromPrimary()`.
- Modify `app/Modules/SchoolProfile/Livewire/Branding.php` and its blade — multi-colour, presets, contrast warnings, live preview.
- Modify `resources/views/layouts/app.blade.php` — emit the full token set, the app-shell logo and the favicon.
- Modify `routes/web.php` — `/settings` → `SettingsHub`; the old key/value browser moves to `/settings/advanced`.
- Modify `app/Providers/AppServiceProvider.php` — register `settings.hub`.
- Modify `lang/en/opes.php` and `lang/fr/opes.php`.

**Phase 1 — uploads and document image rendering**
- Create `app/Support/Storage/StoredImage.php` — content-hashed store + delete-on-replace on the `public` disk.
- Create `app/Modules/Reporting/Domain/EmbeddedImage.php` — relative path → `data:` URI (dompdf has remote disabled).
- Modify `app/Modules/SchoolProfile/Livewire/DocumentProfile.php` (+ blade) — `WithFileUploads`, five image slots, previews.
- Modify `app/Modules/SchoolProfile/Livewire/Branding.php` (+ blade) — app-shell logo and favicon upload.
- Modify `app/Modules/Reporting/Actions/RenderDocument.php` — resolve branding paths to data URIs at render time.
- Modify `resources/views/documents/blocks/school_header.blade.php` — crest + logo.
- Modify `resources/views/documents/blocks/signature_block.blade.php` — signature images and the stamp.

**Phase 2 — configurable school watermark**
- Create `database/migrations/2026_08_15_440002_add_school_watermark_to_document_profile.php`.
- Create `resources/views/documents/blocks/school_watermark.blade.php`.
- Modify `documents/layout.blade.php`, `documents/blocks/watermark.blade.php`, `RenderDocument.php`, `SaveDocumentProfile.php`, `DocumentProfile.php` + blade.

**Phase 3 — asset labels**
- Create `app/Modules/Reporting/Domain/AssetTagBarcode.php`, `app/Modules/Reporting/Domain/Code39Image.php`.
- Create `app/Modules/Assets/Actions/PrintAssetLabel.php`, `app/Modules/Assets/Actions/PrintAssetLabelSheet.php`.
- Create `database/migrations/2026_08_15_440003_seed_asset_label_templates.php`.
- Create `resources/views/documents/assets/label.blade.php`, `resources/views/documents/assets/label-sheet.blade.php`.
- Modify `app/Modules/Assets/Livewire/Show.php` + blade, `app/Modules/Assets/Livewire/Index.php` + blade.

**Phase 4 — preview**
- Modify `app/Modules/Reporting/Actions/RenderDocument.php` — add `preview()`.
- Create `app/Modules/Reporting/Http/Controllers/DocumentPreviewController.php`.
- Modify `routes/web.php`, `resources/views/livewire/students/show.blade.php`.

**Phase 5 — dead buttons**
- Create `docs/superpowers/audits/2026-08-15-inert-controls.md`.
- Modify `app/Modules/Students/Livewire/Students/Show.php` + `resources/views/livewire/students/show.blade.php`.

**Phase 6 — paper sizes**
- Create `database/migrations/2026_08_15_440010_seed_broadsheet_template.php`, `resources/views/documents/assessment/broadsheet.blade.php`.
- Create `docs/superpowers/audits/2026-08-15-paper-sizes.md`.

---

# Phase 0 — Settings shell and a real branding system

**Why first:** Phases 1 and 2 both add fields to `/settings/school-identity`. Building uploads and watermark configuration onto today's flat, silently-saving field list means reworking those screens twice.

**What exists today (verified):** seven settings screens with no hub — `/settings` is a raw key/value browser (`SchoolProfile\Livewire\Index`), `/settings/school-identity`, `/settings/branding`, `/settings/tax`, `/settings/fiscal-identity`, `/settings/licence`, `/operations/setup`, plus `/academics/settings`. The header gear icon **is** already wired to `route('settings.index')` behind `@can('setting.view')` (the stale "no route yet" comment was removed in an earlier pass) — so 0a only has to make the destination worth arriving at. `Branding.php` holds one property, `$primaryColor`, persisted to one settings key `branding.primary_color`, and `BrandPalette::fromPrimary()` shades chrome/chrome-light out of it. `WriteSetting::handle()` does `firstOrFail()` — **a settings key must be seeded by a migration before it can ever be written.**

### Task 1: `SettingsCatalogue` — the hub's card metadata

**Files:**
- Create: `app/Modules/SchoolProfile/Domain/SettingsCatalogue.php`
- Test: `tests/Feature/SchoolProfile/SettingsCatalogueTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\SchoolProfile\Domain\SettingsCatalogue;
use Illuminate\Support\Facades\Route;

/**
 * The nav contract (00-core 6.2): a link the holder's permission refuses is
 * the one thing the shell may never offer. The catalogue is the single list
 * the hub renders from, so every entry must name a route that EXISTS and a
 * permission string the Gate actually knows.
 */
it('names only routes that exist', function (): void {
    foreach (SettingsCatalogue::cards() as $card) {
        expect(Route::has($card['route']))->toBeTrue("route [{$card['route']}] is missing");
    }
});

it('gives every card a permission, an icon and lang keys', function (): void {
    foreach (SettingsCatalogue::cards() as $card) {
        expect($card['permission'])->toBeString()->not->toBe('')
            ->and($card['icon'])->toBeString()->not->toBe('')
            ->and($card['title_key'])->toStartWith('opes.settings_hub.')
            ->and($card['description_key'])->toStartWith('opes.settings_hub.');
    }
});

it('uses a stable, unique key per card', function (): void {
    $keys = array_column(SettingsCatalogue::cards(), 'key');

    expect($keys)->toBe(array_unique($keys))
        ->and($keys)->toContain('school_identity', 'branding', 'fiscal', 'tax', 'licence', 'academic', 'go_live', 'advanced');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SettingsCatalogueTest.php`
Expected: FAIL — `Class "App\Modules\SchoolProfile\Domain\SettingsCatalogue" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Domain;

/**
 * The /settings hub's card list: WHICH settings screens exist, what each one
 * is for, and which permission opens it.
 *
 * Pure metadata by design (DomainPurityTest forbids DB access here): the hub
 * component computes the per-card "current state" summaries itself, because
 * those are reads across five modules and belong in the component, not in a
 * value list every test would then have to boot a database for.
 *
 * `permission` is the SAME string the route's `can:` middleware carries, so
 * a card can never offer a link its holder would be refused at - the nav
 * contract in 00-core 6.2.
 */
final class SettingsCatalogue
{
    /**
     * @return list<array{key: string, route: string, permission: string, icon: string, title_key: string, description_key: string}>
     */
    public static function cards(): array
    {
        return [
            [
                'key' => 'school_identity',
                'route' => 'settings.school-identity',
                'permission' => 'setting.edit',
                'icon' => 'system_documentation',
                'title_key' => 'opes.settings_hub.school_identity_title',
                'description_key' => 'opes.settings_hub.school_identity_description',
            ],
            [
                'key' => 'branding',
                'route' => 'settings.branding',
                'permission' => 'setting.edit',
                'icon' => 'branding',
                'title_key' => 'opes.settings_hub.branding_title',
                'description_key' => 'opes.settings_hub.branding_description',
            ],
            [
                'key' => 'fiscal',
                'route' => 'tax.fiscal-identity',
                'permission' => 'ledger.configure',
                'icon' => 'fiscal_identity',
                'title_key' => 'opes.settings_hub.fiscal_title',
                'description_key' => 'opes.settings_hub.fiscal_description',
            ],
            [
                'key' => 'tax',
                'route' => 'tax.settings',
                'permission' => 'ledger.configure',
                'icon' => 'finance',
                'title_key' => 'opes.settings_hub.tax_title',
                'description_key' => 'opes.settings_hub.tax_description',
            ],
            [
                'key' => 'licence',
                'route' => 'settings.licence',
                'permission' => 'licence.manage',
                'icon' => 'licence',
                'title_key' => 'opes.settings_hub.licence_title',
                'description_key' => 'opes.settings_hub.licence_description',
            ],
            [
                'key' => 'academic',
                'route' => 'academics.settings',
                'permission' => 'academics.manage',
                'icon' => 'academics',
                'title_key' => 'opes.settings_hub.academic_title',
                'description_key' => 'opes.settings_hub.academic_description',
            ],
            [
                'key' => 'go_live',
                'route' => 'operations.setup',
                'permission' => 'setting.view',
                'icon' => 'setup',
                'title_key' => 'opes.settings_hub.go_live_title',
                'description_key' => 'opes.settings_hub.go_live_description',
            ],
            [
                'key' => 'advanced',
                'route' => 'settings.advanced',
                'permission' => 'setting.view',
                'icon' => 'settings',
                'title_key' => 'opes.settings_hub.advanced_title',
                'description_key' => 'opes.settings_hub.advanced_description',
            ],
        ];
    }
}
```

- [ ] **Step 4: Run the test — it still fails on `settings.advanced`**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SettingsCatalogueTest.php`
Expected: FAIL — `route [settings.advanced] is missing`. That route is created in Task 2; this is the right failure.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SchoolProfile/Domain/SettingsCatalogue.php tests/Feature/SchoolProfile/SettingsCatalogueTest.php
git commit -m "feat(settings): add the settings-hub card catalogue"
```

---

### Task 2: The `/settings` hub screen

**Files:**
- Create: `app/Modules/SchoolProfile/Livewire/SettingsHub.php`
- Create: `resources/views/livewire/schoolprofile/settings-hub.blade.php`
- Modify: `routes/web.php:524-525`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/SchoolProfile/SettingsHubTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function settingsHubUserAs(Role ...$roles): User
{
    (new RolePermissionSeeder)->run();
    $user = User::factory()->create();

    foreach ($roles as $role) {
        $user->assignRole($role->value);
    }

    $user = $user->fresh() ?? $user;
    actingAs($user);

    return $user;
}

it('shows every settings card a principal may open', function (): void {
    settingsHubUserAs(Role::Principal);

    get('/settings')
        ->assertOk()
        ->assertSee('School Identity')
        ->assertSee('Branding');
});

it('never renders a card the role cannot open', function (): void {
    // A Bursar holds ledger.configure but not academics.manage: the Academic
    // Year card must be ABSENT, not disabled - offering a link the route
    // would refuse is the one thing the nav contract forbids.
    settingsHubUserAs(Role::Bursar);

    get('/settings')
        ->assertOk()
        ->assertDontSee('Academic Year & Terms');
});

it('summarises how many document images are set', function (): void {
    settingsHubUserAs(Role::Principal);

    DB::table('school_document_profiles')->updateOrInsert(['id' => 1], [
        'crest_path' => 'branding/crest-abc.png',
        'logo_path' => 'branding/logo-abc.png',
        'state_header_enabled' => false,
        'bilingual_documents' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    get('/settings')->assertOk()->assertSee('2 of 5 images set');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SettingsHubTest.php`
Expected: FAIL — the current `/settings` renders the key/value browser, so `assertSee('School Identity')` fails.

- [ ] **Step 3: Write the component**

```php
<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Domain\SettingsCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /settings - the categorised landing page for the seven settings screens
 * this platform grew without ever tying them together. Before this, /settings
 * served a raw key/value grid of the generic Setting store and NOTHING linked
 * to /settings/school-identity, /settings/branding or /settings/licence, so a
 * principal could only reach them by typing the URL.
 *
 * The key/value grid still exists and is still useful (it is the only way to
 * see an engine-behaviour setting's lock status); it moved to
 * /settings/advanced and is the last card here.
 *
 * Cards are filtered by the SAME permission string their route's `can:`
 * middleware carries: a card the holder cannot open is ABSENT, never
 * disabled. Each carries a one-line state summary so the hub answers "what
 * still needs doing" without opening seven screens.
 */
#[Layout('layouts.app')]
final class SettingsHub extends Component
{
    public function mount(): void
    {
        Gate::authorize(Permission::SettingView->value);
    }

    /**
     * @return list<array{key: string, route: string, icon: string, title: string, description: string, summary: string, tone: string}>
     */
    private function visibleCards(): array
    {
        $summaries = $this->summaries();
        $cards = [];

        foreach (SettingsCatalogue::cards() as $card) {
            if (! Gate::allows($card['permission'])) {
                continue;
            }

            $summary = $summaries[$card['key']] ?? ['text' => '', 'tone' => 'neutral'];

            $cards[] = [
                'key' => $card['key'],
                'route' => $card['route'],
                'icon' => $card['icon'],
                'title' => (string) __($card['title_key']),
                'description' => (string) __($card['description_key']),
                'summary' => $summary['text'],
                'tone' => $summary['tone'],
            ];
        }

        return $cards;
    }

    /**
     * The per-card "current state" line. Query-builder reads only: these
     * cross five modules, and ModuleBoundaryTest allows exactly this shape.
     *
     * @return array<string, array{text: string, tone: string}>
     */
    private function summaries(): array
    {
        $profile = DB::table('school_document_profiles')->where('id', 1)->first();

        $imageColumns = [
            'crest_path', 'logo_path', 'principal_signature_path',
            'registrar_signature_path', 'school_stamp_path',
        ];

        $imagesSet = 0;

        foreach ($imageColumns as $column) {
            if ($profile !== null && is_string($profile->{$column} ?? null) && $profile->{$column} !== '') {
                $imagesSet++;
            }
        }

        $fiscal = DB::table('fiscal_identities')->where('id', 1)->first();
        $fiscalConfirmed = $fiscal !== null && $fiscal->fiscal_identity_confirmed_at !== null;

        $vatRegistered = DB::table('fiscal_identities')->where('id', 1)->value('tax_regime');

        $licenceExpiry = DB::table('licences')->orderByDesc('id')->value('expires_on');
        $licenceDays = is_string($licenceExpiry)
            ? (int) round((strtotime($licenceExpiry) - strtotime('today')) / 86400)
            : null;

        $currentYear = DB::table('academic_years')->where('is_current', true)->value('name');

        $blockers = DB::table('setup_checklist_items')->where('is_complete', false)->count();

        return [
            'school_identity' => [
                'text' => (string) __('opes.settings_hub.images_set', ['set' => $imagesSet, 'total' => count($imageColumns)]),
                'tone' => $imagesSet === count($imageColumns) ? 'good' : 'warn',
            ],
            'branding' => [
                'text' => (string) __('opes.settings_hub.branding_summary'),
                'tone' => 'neutral',
            ],
            'fiscal' => [
                'text' => $fiscalConfirmed
                    ? (string) __('opes.settings_hub.fiscal_confirmed')
                    : (string) __('opes.settings_hub.fiscal_specimen'),
                'tone' => $fiscalConfirmed ? 'good' : 'warn',
            ],
            'tax' => [
                'text' => is_string($vatRegistered) && $vatRegistered !== ''
                    ? (string) __('opes.settings_hub.tax_regime', ['regime' => strtoupper($vatRegistered)])
                    : (string) __('opes.settings_hub.tax_unset'),
                'tone' => is_string($vatRegistered) && $vatRegistered !== '' ? 'good' : 'warn',
            ],
            'licence' => [
                'text' => $licenceDays === null
                    ? (string) __('opes.settings_hub.licence_none')
                    : (string) __('opes.settings_hub.licence_expires', ['days' => $licenceDays]),
                'tone' => $licenceDays !== null && $licenceDays > 30 ? 'good' : 'warn',
            ],
            'academic' => [
                'text' => is_string($currentYear)
                    ? (string) __('opes.settings_hub.academic_current', ['year' => $currentYear])
                    : (string) __('opes.settings_hub.academic_none'),
                'tone' => is_string($currentYear) ? 'good' : 'warn',
            ],
            'go_live' => [
                'text' => $blockers === 0
                    ? (string) __('opes.settings_hub.go_live_clear')
                    : (string) __('opes.settings_hub.go_live_blockers', ['count' => $blockers]),
                'tone' => $blockers === 0 ? 'good' : 'warn',
            ],
            'advanced' => [
                'text' => (string) __('opes.settings_hub.advanced_summary', [
                    'count' => DB::table('settings')->count(),
                ]),
                'tone' => 'neutral',
            ],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.schoolprofile.settings-hub', [
            'cards' => $this->visibleCards(),
        ]);
    }
}
```

- [ ] **Step 4: Write the blade**

Create `resources/views/livewire/schoolprofile/settings-hub.blade.php`:

```blade
{{-- /settings - the categorised hub. Every card is permission-filtered in the
     component, so anything rendered here is a link the holder can actually
     follow. --}}
<div class="min-w-0 space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.settings_hub.title') }}</h1>
        <p class="mt-1 text-sm text-text-secondary">{{ __('opes.settings_hub.subtitle') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($cards as $card)
            <a href="{{ route($card['route']) }}"
               class="group flex min-w-0 flex-col rounded-xl border border-border-primary bg-white p-5 shadow-sm transition hover:border-primary hover:shadow-md">
                <span class="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-kpi-green text-kpi-green-solid">
                    <x-opes-nav-icon :nav-key="$card['icon']" class="h-5 w-5"/>
                </span>
                <span class="text-base font-semibold text-charcoal group-hover:text-primary">{{ $card['title'] }}</span>
                <span class="mt-1 text-sm text-text-secondary">{{ $card['description'] }}</span>
                @if ($card['summary'] !== '')
                    <span @class([
                        'mt-3 inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-medium',
                        'bg-success-bg text-success' => $card['tone'] === 'good',
                        'bg-warning-bg text-warning' => $card['tone'] === 'warn',
                        'bg-sand text-text-secondary' => $card['tone'] === 'neutral',
                    ])>{{ $card['summary'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
</div>
```

- [ ] **Step 5: Add the three missing icon glyphs**

`resources/views/components/opes-nav-icon.blade.php` takes a `navKey` prop and looks it up in a `$paths` array of raw SVG path markup drawn on a 24×24 stroke grid; an unknown key falls back to a plain dot rather than nothing. Five of the catalogue's keys already exist (`system_documentation`, `finance`, `academics`, `setup`, and the fallback path). Add the three that do not, inside the `$paths` array:

```php
        // Branding: a paint roller over a swatch.
        'branding' => '<rect x="3" y="4" width="12" height="6" rx="1.5"/><path d="M15 7h4a1 1 0 011 1v3h-6"/><rect x="10" y="14" width="4" height="7" rx="1"/>',
        // Fiscal identity: a shield with a check - a verified registration.
        'fiscal_identity' => '<path d="M12 3l8 3v6c0 5-3.4 8.1-8 9-4.6-.9-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        // Licence: a key.
        'licence' => '<circle cx="8" cy="12" r="4"/><path d="M12 12h9M18 12v3M15.5 12v2.5"/>',
```

Then verify every catalogue key resolves:

Run: `grep -c "'system_documentation'\|'branding'\|'fiscal_identity'\|'finance'\|'licence'\|'academics'\|'setup'\|'settings'" resources/views/components/opes-nav-icon.blade.php`
Expected: at least 8. A missing key renders a plain dot, not an error, so this grep is the only thing that catches it.

- [ ] **Step 6: Swap the routes**

In `routes/web.php`, replace the `/settings` route block (currently `Route::get('/settings', \App\Modules\SchoolProfile\Livewire\Index::class)->middleware('can:setting.view')->name('settings.index');`) with:

```php
    /*
     * /settings is the categorised HUB (SettingsHub); the raw key/value
     * browser it used to serve moved to /settings/advanced, where it is one
     * card among eight rather than the only thing a principal finds.
     */
    Route::get('/settings', \App\Modules\SchoolProfile\Livewire\SettingsHub::class)
        ->middleware('can:setting.view')->name('settings.index');

    Route::get('/settings/advanced', \App\Modules\SchoolProfile\Livewire\Index::class)
        ->middleware('can:setting.view')->name('settings.advanced');
```

- [ ] **Step 7: Register the component**

In `app/Providers/AppServiceProvider.php`, beside the other `Livewire::component(...)` lines, add the import `use App\Modules\SchoolProfile\Livewire\SettingsHub;` and the line:

```php
        Livewire::component('settings.hub', SettingsHub::class);
```

- [ ] **Step 8: Add the lang keys**

In `lang/en/opes.php`, add under a new `'settings_hub' => [...]` key:

```php
    'settings_hub' => [
        'title' => 'Settings',
        'subtitle' => 'Everything that shapes how this school looks, prints and bills.',
        'school_identity_title' => 'School Identity',
        'school_identity_description' => 'Letterhead, address, ministry header, crest, signatures and stamp.',
        'branding_title' => 'Branding',
        'branding_description' => 'Screen colours, school logo and favicon.',
        'fiscal_title' => 'Fiscal Identity',
        'fiscal_description' => 'NIU, RCCM and the confirmation that clears the SPECIMEN watermark.',
        'tax_title' => 'Tax Configuration',
        'tax_description' => 'VAT and withholding rates applied to money documents.',
        'licence_title' => 'Licence',
        'licence_description' => 'Activation key, seat count and expiry.',
        'academic_title' => 'Academic Year & Terms',
        'academic_description' => 'The current year, its terms and assessment periods.',
        'go_live_title' => 'Go-live Setup',
        'go_live_description' => 'The checklist that must be clear before real data is entered.',
        'advanced_title' => 'Advanced Settings',
        'advanced_description' => 'The raw engine-configuration store, with lock status.',
        'images_set' => ':set of :total images set',
        'branding_summary' => 'Palette and logo',
        'fiscal_confirmed' => 'Fiscal identity confirmed',
        'fiscal_specimen' => 'Fiscal identity: SPECIMEN',
        'tax_regime' => 'Regime: :regime',
        'tax_unset' => 'Tax regime not set',
        'licence_none' => 'No licence recorded',
        'licence_expires' => 'Licence expires in :days days',
        'academic_current' => 'Current year: :year',
        'academic_none' => 'No current academic year',
        'go_live_clear' => 'All go-live checks clear',
        'go_live_blockers' => ':count go-live blockers',
        'advanced_summary' => ':count settings',
    ],
```

Add the same structure to `lang/fr/opes.php` with these French values: `'title' => 'Paramètres'`, `'subtitle' => "Tout ce qui détermine l'apparence, les impressions et la facturation de l'établissement."`, `'school_identity_title' => "Identité de l'établissement"`, `'school_identity_description' => "En-tête, adresse, en-tête ministériel, armoiries, signatures et cachet."`, `'branding_title' => 'Charte graphique'`, `'branding_description' => "Couleurs d'écran, logo et favicon."`, `'fiscal_title' => 'Identité fiscale'`, `'fiscal_description' => "NIU, RCCM et la confirmation qui lève le filigrane SPÉCIMEN."`, `'tax_title' => 'Configuration fiscale'`, `'tax_description' => "Taux de TVA et de retenue à la source appliqués aux documents financiers."`, `'licence_title' => 'Licence'`, `'licence_description' => "Clé d'activation, nombre de postes et échéance."`, `'academic_title' => 'Année et périodes scolaires'`, `'academic_description' => "L'année en cours, ses trimestres et ses périodes d'évaluation."`, `'go_live_title' => 'Mise en service'`, `'go_live_description' => "La liste de contrôle à solder avant toute saisie réelle."`, `'advanced_title' => 'Paramètres avancés'`, `'advanced_description' => "Le magasin brut de configuration, avec l'état de verrouillage."`, `'images_set' => ':set images sur :total définies'`, `'branding_summary' => 'Palette et logo'`, `'fiscal_confirmed' => 'Identité fiscale confirmée'`, `'fiscal_specimen' => 'Identité fiscale : SPÉCIMEN'`, `'tax_regime' => 'Régime : :regime'`, `'tax_unset' => 'Régime fiscal non défini'`, `'licence_none' => 'Aucune licence enregistrée'`, `'licence_expires' => 'Licence expirant dans :days jours'`, `'academic_current' => 'Année en cours : :year'`, `'academic_none' => "Aucune année scolaire en cours"`, `'go_live_clear' => 'Tous les contrôles sont soldés'`, `'go_live_blockers' => ':count blocages de mise en service'`, `'advanced_summary' => ':count paramètres'`.

- [ ] **Step 9: Run both tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SettingsHubTest.php tests/Feature/SchoolProfile/SettingsCatalogueTest.php`
Expected: PASS, 6 tests.

If `licences` or `setup_checklist_items` is not the real table name, `DB::table(...)` will throw `Table ... doesn't exist`. Confirm with `$PHP artisan db:show --counts | grep -i "licen\|setup"` and correct the table/column names in `summaries()` — do not guess.

- [ ] **Step 10: Build and commit**

```bash
npm run build
git add app/Modules/SchoolProfile/Livewire/SettingsHub.php resources/views/livewire/schoolprofile/settings-hub.blade.php routes/web.php app/Providers/AppServiceProvider.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/SettingsHubTest.php
git commit -m "feat(settings): replace the raw /settings grid with a categorised hub"
```

---

### Task 3: The reusable settings-form pattern

**Files:**
- Create: `resources/views/components/settings-form.blade.php`
- Create: `resources/views/components/settings-fieldset.blade.php`
- Create: `resources/views/components/settings-field.blade.php`
- Test: `tests/Feature/Ui/SettingsFormComponentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders a sticky save bar, a cancel control and a dirty-state marker', function (): void {
    $html = Blade::render(
        '<x-settings-form title="Test screen" description="A description.">'
        .'<x-settings-fieldset heading="Group" hint="What this group affects.">'
        .'<x-settings-field label="Field label" hint="What this field affects.">'
        .'<input type="text" wire:model="thing">'
        .'</x-settings-field></x-settings-fieldset></x-settings-form>'
    );

    expect($html)
        ->toContain('Test screen')
        ->toContain('A description.')
        ->toContain('What this group affects.')
        ->toContain('What this field affects.')
        // The dirty-state guard is the whole point: a settings screen that
        // loses a half-typed ministry header on a stray back button is worse
        // than one that never had the field.
        ->toContain('dirty')
        ->toContain('beforeunload')
        ->toContain('wire:submit="save"')
        ->toContain('sticky');
});

it('shows an inline error under the field when one is passed', function (): void {
    $html = Blade::render(
        '<x-settings-field label="Email" error="That is not an email address.">'
        .'<input type="text"></x-settings-field>'
    );

    expect($html)->toContain('That is not an email address.');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/SettingsFormComponentTest.php`
Expected: FAIL — `Unable to locate a class or view for component [settings-form]`.

- [ ] **Step 3: Write `settings-form.blade.php`**

```blade
{{-- The shared shell for EVERY settings screen (/settings/school-identity,
     /settings/branding, /settings/tax, ...). Before this each screen was a
     flat list of inputs with a Save button at the bottom that saved
     silently, so an operator could not tell a saved screen from an unsaved
     one, and a stray navigation discarded the lot.

     Three things it guarantees, in one place so the six screens cannot
     diverge:
       1. a STICKY save bar that is always reachable on a long form;
       2. a DIRTY marker plus a beforeunload guard, so leaving with unsaved
          edits requires a deliberate answer;
       3. a success toast driven by the `settings-saved` browser event the
          component dispatches, which also clears the dirty flag.

     `sticky bottom-0` rather than `fixed` with a sidebar offset: the bar
     lives INSIDE the form's own column, so it needs no knowledge of the
     shell's sidebar width (and the root font-size is 17px, which makes any
     hard-coded Tailwind width name a lie). --}}
@props([
    'title',
    'description' => null,
    'submit' => 'save',
    'cancel' => null,
])
<div class="min-w-0 max-w-4xl"
     x-data="{ dirty: false, toast: false }"
     x-on:input="dirty = true"
     x-on:change="dirty = true"
     x-on:settings-saved.window="dirty = false; toast = true; setTimeout(() => toast = false, 4000)"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">

    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li><a href="{{ route('settings.index') }}" class="hover:text-primary hover:underline">{{ __('opes.nav.settings') }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $title }}</li>
        </ol>
    </nav>

    <div class="mt-3">
        <h1 class="text-2xl font-bold text-charcoal">{{ $title }}</h1>
        @if ($description !== null)
            <p class="mt-1 text-sm text-text-secondary">{{ $description }}</p>
        @endif
    </div>

    @isset($banner)
        <div class="mt-4">{{ $banner }}</div>
    @endisset

    {{-- The toast. `role="status"` so a screen reader announces the save;
         a flash message that only appears visually is not a confirmation. --}}
    <div x-show="toast" x-cloak role="status"
         class="fixed right-4 top-20 z-50 rounded-lg border border-success/30 bg-success-bg px-4 py-3 text-sm font-medium text-success shadow-lg">
        {{ __('opes.ui.saved') }}
    </div>

    <form wire:submit="{{ $submit }}" class="mt-4 space-y-6">
        {{ $slot }}

        <div class="sticky bottom-0 -mx-4 border-t border-border-primary bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-b-xl">
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit"
                        class="rounded-lg border border-primary bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                    <span wire:loading.remove wire:target="{{ $submit }}">{{ __('opes.ui.save') }}</span>
                    <span wire:loading wire:target="{{ $submit }}">{{ __('opes.ui.saving') }}</span>
                </button>

                @if ($cancel !== null)
                    <button type="button" wire:click="{{ $cancel }}" x-on:click="dirty = false"
                            class="rounded-lg border border-border-primary px-4 py-2 text-sm font-medium text-charcoal transition hover:bg-sand">
                        {{ __('opes.ui.cancel') }}
                    </button>
                @endif

                <span x-show="dirty" x-cloak class="text-xs font-medium text-warning">
                    {{ __('opes.ui.unsaved_changes') }}
                </span>
            </div>
        </div>
    </form>
</div>
```

- [ ] **Step 4: Write `settings-fieldset.blade.php`**

```blade
{{-- One titled group of related settings. The `hint` says what the whole
     group AFFECTS, which is the question a settings screen most often fails
     to answer ("does this print, or is it only on screen?"). --}}
@props([
    'heading',
    'hint' => null,
    'columns' => 2,
])
<section class="rounded-xl border border-border-primary bg-white p-5 shadow-sm">
    <h2 class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">{{ $heading }}</h2>
    @if ($hint !== null)
        <p class="mt-1 text-xs text-text-secondary">{{ $hint }}</p>
    @endif
    <div @class([
        'mt-4 grid gap-4',
        'sm:grid-cols-2' => (int) $columns === 2,
        'sm:grid-cols-3' => (int) $columns === 3,
    ])>
        {{ $slot }}
    </div>
</section>
```

- [ ] **Step 5: Write `settings-field.blade.php`**

```blade
{{-- One labelled control: label, the control itself, helper text saying what
     the value AFFECTS, and an inline error. Pass `:error="$errors->first($key)"`
     from the screen - the component does not guess the error-bag key, because
     these screens map camelCase properties onto snake_case columns and the
     two never line up. --}}
@props([
    'label',
    'hint' => null,
    'error' => null,
    'span' => 1,
])
<label @class(['block text-sm', 'sm:col-span-2' => (int) $span === 2])>
    <span class="mb-1 block font-medium text-charcoal">{{ $label }}</span>
    {{ $slot }}
    @if ($hint !== null)
        <span class="mt-1 block text-xs text-text-secondary">{{ $hint }}</span>
    @endif
    @if ($error !== null && $error !== '')
        <span class="mt-1 block text-xs font-medium text-danger">{{ $error }}</span>
    @endif
</label>
```

- [ ] **Step 6: Add the shared UI lang keys**

In `lang/en/opes.php` under the existing `'ui' => [...]` key add: `'saving' => 'Saving…'`, `'cancel' => 'Cancel'`, `'saved' => 'Saved.'`, `'unsaved_changes' => 'Unsaved changes'`. In `lang/fr/opes.php` add: `'saving' => 'Enregistrement…'`, `'cancel' => 'Annuler'`, `'saved' => 'Enregistré.'`, `'unsaved_changes' => 'Modifications non enregistrées'`. If any key already exists there, leave the existing value alone.

- [ ] **Step 7: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/SettingsFormComponentTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 8: Build and commit**

```bash
npm run build
git add resources/views/components/settings-form.blade.php resources/views/components/settings-fieldset.blade.php resources/views/components/settings-field.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Ui/SettingsFormComponentTest.php
git commit -m "feat(settings): add the shared settings-form pattern"
```

---

### Task 4: Move the School Identity screen onto the pattern

**Files:**
- Modify: `app/Modules/SchoolProfile/Livewire/DocumentProfile.php:115-158`
- Modify: `resources/views/livewire/schoolprofile/document-profile.blade.php` (full rewrite)
- Test: `tests/Feature/SchoolProfile/DocumentProfileScreenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\DocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('dispatches settings-saved so the shared form can clear its dirty flag', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('city', 'Yaoundé')
        ->call('save')
        ->assertDispatched('settings-saved');

    expect(DB::table('school_document_profiles')->where('id', 1)->value('city'))->toBe('Yaoundé');
});

it('restores the persisted values when the operator cancels', function (): void {
    p13coreUserAs(Role::Principal);
    p13coreDocumentProfile(['city' => 'Douala']);

    Livewire::test(DocumentProfile::class)
        ->set('city', 'Typed but not saved')
        ->call('cancel')
        ->assertSet('city', 'Douala');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/DocumentProfileScreenTest.php`
Expected: FAIL — `Failed asserting that event [settings-saved] was dispatched` (the component currently flashes to the session instead), and `Method cancel does not exist`.

- [ ] **Step 3: Add `cancel()` and the dispatch to the component**

In `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`, extract the hydration out of `mount()` into a reusable method and add `cancel()`. Replace the body of `mount()` and append the two methods:

```php
    public function mount(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->hydrateFromDatabase();
    }

    /**
     * Discard in-progress edits and re-read the persisted row. The shared
     * <x-settings-form> Cancel control calls this, and clears its own dirty
     * flag on the same click - so Cancel means "put it back", not "leave the
     * screen", which is the one thing a settings Cancel must never be
     * ambiguous about.
     */
    public function cancel(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();
        $this->hydrateFromDatabase();
    }

    private function hydrateFromDatabase(): void
    {
        $row = DB::table('school_document_profiles')->where('id', 1)->first();

        if ($row === null) {
            return;
        }

        $this->addressLine1 = (string) ($row->address_line1 ?? '');
        $this->addressLine2 = (string) ($row->address_line2 ?? '');
        $this->city = (string) ($row->city ?? '');
        $this->region = (string) ($row->region ?? '');
        $this->poBox = (string) ($row->po_box ?? '');
        $this->phone = (string) ($row->phone ?? '');
        $this->phoneAlt = (string) ($row->phone_alt ?? '');
        $this->email = (string) ($row->email ?? '');
        $this->website = (string) ($row->website ?? '');
        $this->authorisationLine = (string) ($row->authorisation_line ?? '');
        $this->stateHeaderEnabled = (bool) $row->state_header_enabled;
        $this->ministryEn = (string) ($row->ministry_en ?? '');
        $this->ministryFr = (string) ($row->ministry_fr ?? '');
        $this->regionalDelegationEn = (string) ($row->regional_delegation_en ?? '');
        $this->regionalDelegationFr = (string) ($row->regional_delegation_fr ?? '');
        $this->divisionalDelegationEn = (string) ($row->divisional_delegation_en ?? '');
        $this->divisionalDelegationFr = (string) ($row->divisional_delegation_fr ?? '');
        $this->bilingualDocuments = (bool) $row->bilingual_documents;
        $this->defaultDocumentLanguage = (string) ($row->default_document_language ?? 'en');
        $this->crestPath = (string) ($row->crest_path ?? '');
        $this->logoPath = (string) ($row->logo_path ?? '');
        $this->principalSignaturePath = (string) ($row->principal_signature_path ?? '');
        $this->registrarSignaturePath = (string) ($row->registrar_signature_path ?? '');
        $this->schoolStampPath = (string) ($row->school_stamp_path ?? '');
    }
```

Then replace the final line of `save()`, `session()->flash('status', __('opes.school_identity.saved'));`, with:

```php
        // A browser event rather than a session flash: the shared
        // <x-settings-form> listens for it to clear its dirty flag and raise
        // the toast, and a Livewire round trip does not reload the page a
        // session flash would need.
        $this->dispatch('settings-saved');
```

- [ ] **Step 4: Rewrite the blade onto the shared pattern**

Replace the entire contents of `resources/views/livewire/schoolprofile/document-profile.blade.php` with:

```blade
{{-- /settings/school-identity - the letterhead every printed document wears.
     Built on the shared <x-settings-form> pattern so this screen, Branding
     and Tax cannot drift apart. Every field's helper text says where the
     value ends up, because "address_line1" alone does not tell an operator
     that it prints on every invoice. --}}
<div>
    <x-settings-form :title="__('opes.school_identity.title')"
                     :description="__('opes.school_identity.subtitle')"
                     submit="save" cancel="cancel">

        @if ($fiscalProvisional)
            <x-slot:banner>
                <div class="rounded-xl border border-heritage-yellow/60 bg-heritage-yellow/15 px-4 py-3 text-sm text-charcoal">
                    <p class="font-semibold">{{ __('opes.school_identity.fiscal_provisional_title') }}</p>
                    <p class="mt-1">{{ __('opes.school_identity.fiscal_provisional_body') }}</p>
                    @can('ledger.configure')
                        <a href="{{ route('tax.fiscal-identity') }}" class="mt-2 inline-block font-medium text-primary hover:underline">
                            {{ __('opes.school_identity.fiscal_provisional_action') }}
                        </a>
                    @endcan
                </div>
            </x-slot:banner>
        @endif

        <x-settings-fieldset :heading="__('opes.school_identity.contacts')"
                             :hint="__('opes.school_identity.contacts_hint')">
            @foreach ([
                'addressLine1' => 'address_line1', 'addressLine2' => 'address_line2',
                'city' => 'city', 'region' => 'region', 'poBox' => 'po_box',
                'phone' => 'phone', 'phoneAlt' => 'phone_alt', 'email' => 'email',
                'website' => 'website', 'authorisationLine' => 'authorisation_line',
            ] as $model => $key)
                <x-settings-field :label="__('opes.school_identity.'.$key)"
                                  :hint="__('opes.school_identity.hint_'.$key)"
                                  :error="$errors->first($key)">
                    <input type="text" wire:model="{{ $model }}"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.school_identity.state_header')"
                             :hint="__('opes.school_identity.state_header_hint')">
            <x-settings-field :label="__('opes.school_identity.state_header_enabled')" :span="2">
                <label class="flex items-center gap-2 text-sm font-normal">
                    <input type="checkbox" wire:model.live="stateHeaderEnabled">
                    <span>{{ __('opes.school_identity.state_header_enabled_hint') }}</span>
                </label>
            </x-settings-field>

            @if ($stateHeaderEnabled)
                @foreach ([
                    'ministryEn' => 'ministry_en', 'ministryFr' => 'ministry_fr',
                    'regionalDelegationEn' => 'regional_delegation_en',
                    'regionalDelegationFr' => 'regional_delegation_fr',
                    'divisionalDelegationEn' => 'divisional_delegation_en',
                    'divisionalDelegationFr' => 'divisional_delegation_fr',
                ] as $model => $key)
                    <x-settings-field :label="__('opes.school_identity.'.$key)"
                                      :error="$errors->first($key)">
                        <input type="text" wire:model="{{ $model }}"
                               class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </x-settings-field>
                @endforeach
            @endif
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.school_identity.marks')"
                             :hint="__('opes.school_identity.marks_hint')">
            @foreach ([
                'logoPath' => 'logo_path', 'crestPath' => 'crest_path',
                'principalSignaturePath' => 'principal_signature_path',
                'registrarSignaturePath' => 'registrar_signature_path',
                'schoolStampPath' => 'school_stamp_path',
            ] as $model => $key)
                <x-settings-field :label="__('opes.school_identity.'.$key)"
                                  :hint="__('opes.school_identity.hint_'.$key)"
                                  :error="$errors->first($key)">
                    <input type="text" wire:model="{{ $model }}"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.school_identity.language')"
                             :hint="__('opes.school_identity.language_hint')">
            <x-settings-field :label="__('opes.school_identity.default_document_language')"
                              :hint="__('opes.school_identity.hint_default_document_language')">
                <select wire:model="defaultDocumentLanguage"
                        class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="en">English</option>
                    <option value="fr">Français</option>
                </select>
            </x-settings-field>

            <x-settings-field :label="__('opes.school_identity.bilingual_documents')"
                              :hint="__('opes.school_identity.hint_bilingual_documents')">
                <label class="flex items-center gap-2 text-sm font-normal">
                    <input type="checkbox" wire:model="bilingualDocuments">
                    <span>{{ __('opes.school_identity.bilingual_documents') }}</span>
                </label>
            </x-settings-field>
        </x-settings-fieldset>
    </x-settings-form>
</div>
```

- [ ] **Step 5: Add the helper-text lang keys**

In `lang/en/opes.php` under `'school_identity'`, add:

```php
        'contacts_hint' => 'Printed in the letterhead of every document this school issues.',
        'state_header_hint' => 'The bilingual state block above the letterhead on statutory documents. Text only — never an emblem.',
        'state_header_enabled_hint' => 'Print the ministry and delegation lines above the school name.',
        'marks' => 'Marks & images',
        'marks_hint' => 'The crest, logo, signatures and stamp printed on documents.',
        'language' => 'Document language',
        'language_hint' => 'The language a document renders in when the caller does not ask for one.',
        'hint_address_line1' => 'First address line in the letterhead.',
        'hint_address_line2' => 'Second address line, if the school needs one.',
        'hint_city' => 'Printed beside the region in the letterhead.',
        'hint_region' => 'Printed beside the city in the letterhead.',
        'hint_po_box' => 'Printed as "P.O. Box …" in the letterhead.',
        'hint_phone' => 'Printed as "Tel: …" in the letterhead.',
        'hint_phone_alt' => 'A second number, printed without a label.',
        'hint_email' => 'Printed in the contact strip.',
        'hint_website' => 'Printed in the contact strip.',
        'hint_authorisation_line' => 'The ministerial authorisation reference, e.g. "Arrêté N° 123/MINESEC/SG".',
        'hint_logo_path' => 'The school logo, printed at the top-right of the letterhead.',
        'hint_crest_path' => 'The school crest, printed centred above the school name.',
        'hint_principal_signature_path' => 'Printed above the Principal signature line on certificates.',
        'hint_registrar_signature_path' => 'Printed above the Registrar signature line on certificates.',
        'hint_school_stamp_path' => 'Printed beside the signatures on certificates.',
        'hint_default_document_language' => 'Used when neither the caller nor the section specifies one.',
        'hint_bilingual_documents' => 'Print the English and French school names together.',
```

Add the French equivalents to `lang/fr/opes.php`: `'contacts_hint' => "Imprimé dans l'en-tête de chaque document émis."`, `'state_header_hint' => "Le bloc bilingue de l'État au-dessus de l'en-tête. Texte uniquement, jamais un emblème."`, `'state_header_enabled_hint' => "Imprimer les lignes ministère et délégation au-dessus du nom de l'établissement."`, `'marks' => 'Marques et images'`, `'marks_hint' => "Les armoiries, le logo, les signatures et le cachet imprimés sur les documents."`, `'language' => 'Langue des documents'`, `'language_hint' => "La langue utilisée lorsque l'appelant n'en précise aucune."`, `'hint_address_line1' => "Première ligne d'adresse de l'en-tête."`, `'hint_address_line2' => "Deuxième ligne d'adresse, si nécessaire."`, `'hint_city' => "Imprimée à côté de la région."`, `'hint_region' => "Imprimée à côté de la ville."`, `'hint_po_box' => 'Imprimé sous la forme « B.P. … ».'`, `'hint_phone' => 'Imprimé sous la forme « Tél : … ».'`, `'hint_phone_alt' => 'Un second numéro, imprimé sans libellé.'`, `'hint_email' => 'Imprimé dans la bande de contact.'`, `'hint_website' => 'Imprimé dans la bande de contact.'`, `'hint_authorisation_line' => "La référence de l'arrêté d'autorisation, ex. « Arrêté N° 123/MINESEC/SG »."`, `'hint_logo_path' => "Le logo, imprimé en haut à droite de l'en-tête."`, `'hint_crest_path' => "Les armoiries, imprimées au centre au-dessus du nom."`, `'hint_principal_signature_path' => 'Imprimée au-dessus de la ligne de signature du Proviseur.'`, `'hint_registrar_signature_path' => "Imprimée au-dessus de la ligne de signature du Censeur."`, `'hint_school_stamp_path' => 'Imprimé à côté des signatures sur les certificats.'`, `'hint_default_document_language' => "Utilisée si ni l'appelant ni la section n'en précise une."`, `'hint_bilingual_documents' => "Imprimer ensemble les noms anglais et français."`.

- [ ] **Step 6: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/DocumentProfileScreenTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 7: Run the localisation guard**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/LocalisationTest.php`
Expected: PASS — this suite asserts `lang/en` and `lang/fr` have identical key sets. A failure here names the exact missing French key; add it and re-run.

- [ ] **Step 8: Build and commit**

```bash
npm run build
git add app/Modules/SchoolProfile/Livewire/DocumentProfile.php resources/views/livewire/schoolprofile/document-profile.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/DocumentProfileScreenTest.php
git commit -m "feat(settings): move School Identity onto the shared settings-form pattern"
```

---

### Task 5: `ColorContrast` — WCAG ratios

**Files:**
- Create: `app/Support/Branding/ColorContrast.php`
- Test: `tests/Unit/Support/ColorContrastTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\Branding\ColorContrast;

it('computes the canonical black-on-white ratio', function (): void {
    expect(ColorContrast::ratio('#000000', '#FFFFFF'))->toBeGreaterThan(20.9)
        ->and(ColorContrast::ratio('#000000', '#FFFFFF'))->toBeLessThan(21.1);
});

it('is symmetric', function (): void {
    expect(round(ColorContrast::ratio('#0B5A32', '#FFFFFF'), 4))
        ->toBe(round(ColorContrast::ratio('#FFFFFF', '#0B5A32'), 4));
});

it('gives identical colours a ratio of 1', function (): void {
    expect(round(ColorContrast::ratio('#D9A829', '#D9A829'), 4))->toBe(1.0);
});

it('passes AA for Heritage green on white and fails for gold on white', function (): void {
    // Heritage Green #0B5A32 on white is the platform's own button colour and
    // must clear 4.5:1. Heritage Gold #D9A829 on white does NOT - which is
    // exactly why the design system uses gold as an accent only, never as a
    // text or button fill. The branding screen has to say so out loud when a
    // school picks something similar.
    expect(ColorContrast::passesAA('#0B5A32', '#FFFFFF'))->toBeTrue()
        ->and(ColorContrast::passesAA('#D9A829', '#FFFFFF'))->toBeFalse();
});

it('refuses a malformed hex', function (): void {
    ColorContrast::ratio('0B5A32', '#FFFFFF');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/ColorContrastTest.php`
Expected: FAIL — `Class "App\Support\Branding\ColorContrast" not found`.

- [ ] **Step 3: Write the implementation**

```php
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
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/ColorContrastTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Branding/ColorContrast.php tests/Unit/Support/ColorContrastTest.php
git commit -m "feat(branding): add WCAG contrast computation"
```

---

### Task 6: `BrandTokens` — the validated multi-colour palette

**Storage decision, argued here so it is not re-litigated in a later task.** The palette is stored as **ONE JSON settings key, `branding.palette`**, not one key per colour. Three reasons: (1) `WriteSetting::handle()` writes one key per call in its own transaction and writes one audit row per call — six keys means six audit rows for one intent, and a half-applied palette if the fourth write throws; (2) the validation that matters is *cross-field* (contrast between primary and the surfaces it sits on), which a per-key `validation_rule` regex cannot express; (3) adding a seventh colour later is a value change, not a migration. The existing `branding.primary_color` key is **kept and written in the same `save()`** so the layout's current read, and anything else that already depends on it, keeps working — `branding.palette` is canonical and `branding.primary_color` is its mirror.

**Files:**
- Create: `app/Support/Branding/BrandTokens.php`
- Test: `tests/Unit/Support/BrandTokensTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\Branding\BrandTokens;

it('falls back to the Heritage defaults for anything not supplied', function (): void {
    $tokens = BrandTokens::fromArray(['primary' => '#123456']);

    expect($tokens->all()['primary'])->toBe('#123456')
        ->and($tokens->all()['accent'])->toBe(BrandTokens::DEFAULTS['accent'])
        ->and($tokens->all()['danger'])->toBe(BrandTokens::DEFAULTS['danger']);
});

it('uppercases every stored hex so a saved palette is byte-stable', function (): void {
    expect(BrandTokens::fromArray(['primary' => '#0b5a32'])->all()['primary'])->toBe('#0B5A32');
});

it('refuses a malformed hex naming the offending token', function (): void {
    BrandTokens::fromArray(['primary' => 'rgb(1,2,3)']);
})->throws(InvalidArgumentException::class, 'primary');

it('ignores a token name it does not know', function (): void {
    $tokens = BrandTokens::fromArray(['primary' => '#123456', 'not_a_token' => '#000000']);

    expect(array_keys($tokens->all()))->toBe(array_keys(BrandTokens::DEFAULTS));
});

it('emits the CSS custom properties the shell paints from', function (): void {
    $vars = BrandTokens::fromArray([
        'primary' => '#0B5A32',
        'secondary' => '#064A2B',
        'accent' => '#D9A829',
    ])->toCssVariables();

    expect($vars)->toHaveKeys([
        '--color-primary', '--color-chrome', '--color-chrome-light',
        '--color-heritage-yellow', '--color-success', '--color-warning', '--color-danger',
    ])
        // The sidebar body is a DARKER step than the secondary it derives
        // from, the same relationship the built-in palette has.
        ->and($vars['--color-chrome-light'])->toBe('#064A2B')
        ->and($vars['--color-primary'])->toBe('#0B5A32')
        ->and($vars['--color-heritage-yellow'])->toBe('#D9A829');
});

it('round-trips through its array form', function (): void {
    $original = BrandTokens::fromArray(['primary' => '#123456', 'accent' => '#ABCDEF']);

    expect(BrandTokens::fromArray($original->all())->all())->toBe($original->all());
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/BrandTokensTest.php`
Expected: FAIL — `Class "App\Support\Branding\BrandTokens" not found`.

- [ ] **Step 3: Write the implementation**

```php
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
```

- [ ] **Step 4: Expose `darken()` on `BrandPalette`**

In `app/Support/Branding/BrandPalette.php`, change the `shade()` method's visibility and add a public wrapper. Replace `private static function shade(string $hex, float $amount): string` with:

```php
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
```

(Leave the existing body of `shade()` and `fromPrimary()` untouched — `fromPrimary()` is still used by the layout's fallback path and by the old Branding preview.)

- [ ] **Step 5: Run both branding unit tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/BrandTokensTest.php tests/Unit/Support/ColorContrastTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Branding/BrandTokens.php app/Support/Branding/BrandPalette.php tests/Unit/Support/BrandTokensTest.php
git commit -m "feat(branding): add the validated multi-colour BrandTokens palette"
```

---

### Task 7: Seed the branding settings keys

**Files:**
- Create: `database/migrations/2026_08_15_440001_seed_branding_palette_settings.php`
- Test: `tests/Feature/SchoolProfile/BrandingSettingsSeedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Branding\BrandTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('seeds the palette, app logo and favicon keys', function (): void {
    foreach (['branding.palette', 'branding.app_logo_path', 'branding.favicon_path'] as $key) {
        expect(DB::table('settings')->where('key', $key)->where('scope', 'global')->exists())
            ->toBeTrue("setting [{$key}] was not seeded");
    }
});

it('seeds the palette as the Heritage defaults', function (): void {
    $raw = DB::table('settings')->where('key', 'branding.palette')->value('value');

    expect(json_decode((string) $raw, true))->toBe(BrandTokens::DEFAULTS);
});

it('lets WriteSetting write the seeded palette key', function (): void {
    // WriteSetting::handle() does firstOrFail(): an unseeded key can never be
    // written at all, which is the whole reason this migration exists.
    $user = p13coreUserAs(Role::Principal);

    app(WriteSetting::class)->handle(
        'branding.palette',
        ['primary' => '#123456'] + BrandTokens::DEFAULTS,
        $user->toAuditActor(),
    );

    $raw = DB::table('settings')->where('key', 'branding.palette')->value('value');

    expect(json_decode((string) $raw, true)['primary'])->toBe('#123456');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/BrandingSettingsSeedTest.php`
Expected: FAIL — `setting [branding.palette] was not seeded`.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use App\Support\Branding\BrandTokens;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The branding screen grew from one colour to a palette, a school logo and a
 * favicon. WriteSetting::handle() does firstOrFail() - a settings key that
 * was never seeded can never be written - so every new key has to arrive
 * through a migration like this one.
 *
 * `branding.palette` is JSON and validated by BrandTokens rather than by a
 * `validation_rule` regex: the rule that matters is cross-field (contrast
 * between the picked colours), which no single-value rule can express. The
 * `array` base rule below is the outer shape only; BrandTokens::fromArray()
 * is what refuses a bad hex, and the Branding screen calls it BEFORE
 * WriteSetting ever sees the value.
 *
 * `branding.primary_color` (seeded by 2026_08_11_500002) is deliberately
 * left in place: the shell layout and anything else already reading it keeps
 * working, and the Branding screen writes it as a mirror of the palette's
 * primary in the same save.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('settings')->updateOrInsert(
            ['key' => 'branding.palette', 'scope' => 'global'],
            [
                'value' => json_encode(BrandTokens::DEFAULTS, JSON_THROW_ON_ERROR),
                'default_value' => json_encode(BrandTokens::DEFAULTS, JSON_THROW_ON_ERROR),
                'value_type' => 'json',
                'setting_class' => 'cosmetic',
                'scope' => 'global',
                'validation_rule' => 'array',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        foreach (['branding.app_logo_path', 'branding.favicon_path'] as $key) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key, 'scope' => 'global'],
                [
                    'value' => json_encode('', JSON_THROW_ON_ERROR),
                    'default_value' => json_encode('', JSON_THROW_ON_ERROR),
                    'value_type' => 'string',
                    'setting_class' => 'cosmetic',
                    'scope' => 'global',
                    // A relative path on the `public` disk, or empty. Never a
                    // URL and never absolute: the uploader writes
                    // content-hashed relative paths and nothing else may be
                    // hand-typed into a <link rel="icon"> or an <img src>.
                    'validation_rule' => 'nullable|string|max:255|regex:/^(branding\/[A-Za-z0-9._-]+)?$/',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn('key', ['branding.palette', 'branding.app_logo_path', 'branding.favicon_path'])
            ->where('scope', 'global')
            ->delete();
    }
};
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/BrandingSettingsSeedTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_15_440001_seed_branding_palette_settings.php tests/Feature/SchoolProfile/BrandingSettingsSeedTest.php
git commit -m "feat(branding): seed the palette, app logo and favicon settings keys"
```

---

### Task 8: `BrandPreset` — the curated palettes

**Files:**
- Create: `app/Modules/SchoolProfile/Domain/BrandPreset.php`
- Test: `tests/Unit/Support/BrandPresetTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Support\Branding\BrandTokens;
use App\Support\Branding\ColorContrast;

it('offers at least six presets, each with a stable key and a label', function (): void {
    $presets = BrandPreset::all();

    expect(count($presets))->toBeGreaterThanOrEqual(6);

    $keys = array_column($presets, 'key');
    expect($keys)->toBe(array_unique($keys))->toContain('heritage');

    foreach ($presets as $preset) {
        expect($preset['label'])->toBeString()->not->toBe('');
    }
});

it('ships only presets whose primary is readable on white', function (): void {
    // A preset the platform itself offers must never be one the contrast
    // warning would then flag. Shipping an unreadable preset is worse than
    // letting a school pick one by hand - it reads as an endorsement.
    foreach (BrandPreset::all() as $preset) {
        $tokens = BrandTokens::fromArray($preset['colors']);

        expect(ColorContrast::passesAA($tokens->get('primary'), '#FFFFFF'))
            ->toBeTrue("preset [{$preset['key']}] primary fails AA on white");
    }
});

it('builds valid BrandTokens from every preset', function (): void {
    foreach (BrandPreset::all() as $preset) {
        expect(BrandTokens::fromArray($preset['colors'])->all())
            ->toHaveKeys(array_keys(BrandTokens::DEFAULTS));
    }
});

it('returns the Heritage preset by key', function (): void {
    expect(BrandPreset::find('heritage')['colors']['primary'])->toBe('#0B5A32');
});

it('returns null for an unknown key', function (): void {
    expect(BrandPreset::find('not-a-preset'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/BrandPresetTest.php`
Expected: FAIL — `Class "App\Modules\SchoolProfile\Domain\BrandPreset" not found`.

- [ ] **Step 3: Write the implementation**

```php
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
                    'primary' => '#31408C', 'secondary' => '#232F६6', 'accent' => '#E0A32E',
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
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/BrandPresetTest.php`
Expected: FAIL on the indigo preset — the `secondary` value above contains a non-ASCII character (`#232F६6`) and `BrandTokens::fromArray()` refuses it. Correct that one value to `#232F66` and re-run.

Run again: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/BrandPresetTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SchoolProfile/Domain/BrandPreset.php tests/Unit/Support/BrandPresetTest.php
git commit -m "feat(branding): add curated, contrast-checked brand presets"
```

---

### Task 9: Rebuild the Branding screen

**Files:**
- Modify: `app/Modules/SchoolProfile/Livewire/Branding.php` (full rewrite)
- Modify: `resources/views/livewire/schoolprofile/branding.blade.php` (full rewrite)
- Test: `tests/Feature/SchoolProfile/BrandingScreenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\Branding;
use App\Support\Branding\BrandTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('loads the seeded Heritage palette', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->assertSet('primary', '#0B5A32')
        ->assertSet('accent', '#D9A829');
});

it('saves the whole palette as one settings key and mirrors the primary', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->set('primary', '#1B3A6B')
        ->set('secondary', '#132B50')
        ->call('save')
        ->assertDispatched('settings-saved');

    $palette = json_decode((string) DB::table('settings')->where('key', 'branding.palette')->value('value'), true);

    expect($palette['primary'])->toBe('#1B3A6B')
        ->and($palette['secondary'])->toBe('#132B50')
        // The mirror: the shell layout and the old screen both read this key.
        ->and(json_decode((string) DB::table('settings')->where('key', 'branding.primary_color')->value('value'), true))
        ->toBe('#1B3A6B');
});

it('refuses a malformed hex without writing anything', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->set('primary', 'not-a-colour')
        ->call('save')
        ->assertHasErrors('primary');

    $palette = json_decode((string) DB::table('settings')->where('key', 'branding.palette')->value('value'), true);

    expect($palette['primary'])->toBe(BrandTokens::DEFAULTS['primary']);
});

it('applies a preset to every token in one click', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->call('applyPreset', 'navy')
        ->assertSet('primary', '#1B3A6B')
        ->assertSet('secondary', '#132B50');
});

it('reports a contrast failure for the chosen primary on white', function (): void {
    p13coreUserAs(Role::Principal);

    // Heritage Gold on white is ~1.9:1 - the exact mistake the warning exists
    // to catch.
    $component = Livewire::test(Branding::class)->set('primary', '#D9A829');

    expect($component->instance()->contrastWarnings())->not->toBeEmpty();
});

it('reports no contrast failure for the Heritage default', function (): void {
    p13coreUserAs(Role::Principal);

    expect(Livewire::test(Branding::class)->instance()->contrastWarnings())->toBe([]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/BrandingScreenTest.php`
Expected: FAIL — `Unable to set component property [primary]` (today's component only has `$primaryColor`).

- [ ] **Step 3: Rewrite the component**

Replace the entire contents of `app/Modules/SchoolProfile/Livewire/Branding.php` with:

```php
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

        /** @var mixed $stored */
        $stored = $readSetting->handle(self::PALETTE_KEY, BrandTokens::DEFAULTS);

        $tokens = BrandTokens::fromArray(is_array($stored) ? $stored : BrandTokens::DEFAULTS);

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
            $this->addError('primary', __('opes.branding.unknown_preset'));

            return;
        }

        $this->resetErrorBag();
        $this->hydrateFrom(BrandTokens::fromArray($preset['colors'])->all());
    }

    public function cancel(ReadSetting $readSetting): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();
        $this->mount($readSetting);
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

        foreach ($pairs as $pair) {
            try {
                $ratio = ColorContrast::ratio($this->currentColors()[$pair['token']], $pair['against']);
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
```

- [ ] **Step 4: Rewrite the blade**

Replace the entire contents of `resources/views/livewire/schoolprofile/branding.blade.php` with:

```blade
{{-- /settings/branding - the school's palette, previewed on the real
     components it will repaint. A row of hex swatches cannot tell an
     operator that the primary they picked makes the table header
     unreadable; a live KPI card, table header, button and status pill can. --}}
<div>
    <x-settings-form :title="__('opes.branding.title')"
                     :description="__('opes.branding.subtitle')"
                     submit="save" cancel="cancel">

        <x-settings-fieldset :heading="__('opes.branding.presets')"
                             :hint="__('opes.branding.presets_hint')"
                             :columns="3">
            @foreach ($presets as $preset)
                <button type="button" wire:click="applyPreset('{{ $preset['key'] }}')"
                        class="flex items-center gap-3 rounded-lg border border-border-primary px-3 py-2 text-left text-sm transition hover:border-primary hover:bg-sand">
                    <span class="flex shrink-0 gap-1">
                        @foreach (['primary', 'secondary', 'accent'] as $swatch)
                            <span class="h-5 w-5 rounded-full border border-black/10"
                                  style="background: {{ $preset['colors'][$swatch] }}"></span>
                        @endforeach
                    </span>
                    <span class="font-medium text-charcoal">{{ $preset['label'] }}</span>
                </button>
            @endforeach
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.branding.colours')"
                             :hint="__('opes.branding.colours_hint')">
            @foreach ([
                'primary' => 'primary', 'secondary' => 'secondary', 'accent' => 'accent',
                'success' => 'success', 'warning' => 'warning', 'danger' => 'danger',
            ] as $model => $key)
                <x-settings-field :label="__('opes.branding.colour_'.$key)"
                                  :hint="__('opes.branding.colour_hint_'.$key)"
                                  :error="$errors->first($key)">
                    {{-- Picker and hex box are bound to the SAME property, so
                         they stay in sync by construction rather than by an
                         event handler that can be missed. `.live` on both:
                         the preview and the contrast warning are the point,
                         and they are useless a round trip behind. --}}
                    <span class="flex items-center gap-2">
                        <input type="color" wire:model.live="{{ $model }}"
                               aria-label="{{ __('opes.branding.colour_'.$key) }}"
                               class="h-10 w-12 shrink-0 cursor-pointer rounded border border-border-primary bg-white p-1">
                        <input type="text" wire:model.live.debounce.400ms="{{ $model }}"
                               spellcheck="false" maxlength="7"
                               class="w-full rounded-lg border border-border-primary px-3 py-2 font-mono text-sm uppercase text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </span>
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>

        @if ($warnings !== [])
            <div role="alert" class="rounded-xl border border-warning/40 bg-warning-bg px-4 py-3 text-sm text-charcoal">
                <p class="font-semibold">{{ __('opes.branding.contrast_warning_title') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($warnings as $warning)
                        <li>
                            {{ __('opes.branding.contrast_warning_item', [
                                'colour' => __('opes.branding.colour_'.$warning['token']),
                                'ratio' => number_format($warning['ratio'], 2),
                            ]) }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">{{ __('opes.branding.contrast_warning_body') }}</p>
            </div>
        @endif

        <x-settings-fieldset :heading="__('opes.branding.preview')"
                             :hint="__('opes.branding.preview_hint')"
                             :columns="2">
            {{-- The preview repaints by overriding the same custom properties
                 the shell layout emits, scoped to this container. Inline
                 style, not a class: the values are runtime data and Tailwind
                 has no utility for "whatever hex the operator just typed". --}}
            <div class="sm:col-span-2 rounded-xl border border-border-primary bg-ivory p-4"
                 style="{{ $previewStyle }}">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl bg-white p-4 shadow-sm">
                        <p class="text-xs font-medium uppercase tracking-wide text-text-secondary">
                            {{ __('opes.branding.preview_kpi_label') }}
                        </p>
                        <p class="mt-1 text-2xl font-bold" style="color: var(--color-primary)">1 284</p>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-border-primary bg-white shadow-sm">
                        <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white"
                             style="background: var(--color-chrome)">
                            {{ __('opes.branding.preview_table_header') }}
                        </div>
                        <div class="px-3 py-2 text-sm text-charcoal">{{ __('opes.branding.preview_table_row') }}</div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                              style="background: var(--color-primary)">
                            {{ __('opes.ui.save') }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium text-white"
                              style="background: var(--color-success)">
                            {{ __('opes.branding.preview_pill_paid') }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium text-white"
                              style="background: var(--color-danger)">
                            {{ __('opes.branding.preview_pill_overdue') }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium text-charcoal"
                              style="background: var(--color-heritage-yellow)">
                            {{ __('opes.branding.preview_pill_pending') }}
                        </span>
                    </div>

                    <div class="rounded-xl p-4 text-sm font-medium text-white" style="background: var(--color-chrome)">
                        {{ __('opes.branding.preview_sidebar') }}
                        <div class="mt-2 rounded-lg px-3 py-2" style="background: var(--color-chrome-light)">
                            {{ __('opes.branding.preview_sidebar_active') }}
                        </div>
                    </div>
                </div>
            </div>
        </x-settings-fieldset>
    </x-settings-form>
</div>
```

- [ ] **Step 5: Add the branding lang keys**

In `lang/en/opes.php` under `'branding'`, add (keeping any existing keys such as `saved`):

```php
        'subtitle' => 'The colours, logo and favicon this school\'s screens are painted with.',
        'presets' => 'Preset palettes',
        'presets_hint' => 'One click sets every colour below. Every preset is contrast-checked.',
        'colours' => 'Colours',
        'colours_hint' => 'The sidebar shades are derived from the secondary colour, so the shell stays coherent.',
        'colour_primary' => 'Primary',
        'colour_secondary' => 'Secondary',
        'colour_accent' => 'Accent',
        'colour_success' => 'Success',
        'colour_warning' => 'Warning',
        'colour_danger' => 'Danger',
        'colour_hint_primary' => 'Buttons, links and active states.',
        'colour_hint_secondary' => 'The sidebar; its darker shade is derived from this.',
        'colour_hint_accent' => 'Highlights only — never a large fill or a text colour.',
        'colour_hint_success' => 'Paid, approved, complete.',
        'colour_hint_warning' => 'Pending, due soon, needs attention.',
        'colour_hint_danger' => 'Overdue, rejected, destructive actions.',
        'contrast_warning_title' => 'Some of these colours may be hard to read',
        'contrast_warning_item' => ':colour has a contrast ratio of :ratio:1 against the text placed on it.',
        'contrast_warning_body' => 'WCAG AA needs at least 4.5:1 for normal text. You can still save, but the affected screens will be hard to read.',
        'preview' => 'Live preview',
        'preview_hint' => 'Real components, repainted as you choose. This is what the screens will look like.',
        'preview_kpi_label' => 'Students enrolled',
        'preview_table_header' => 'Class list',
        'preview_table_row' => 'AZEMKEU Brice — Form 1A',
        'preview_pill_paid' => 'Paid',
        'preview_pill_overdue' => 'Overdue',
        'preview_pill_pending' => 'Pending',
        'preview_sidebar' => 'Navigation',
        'preview_sidebar_active' => 'Students',
        'unknown_preset' => 'That preset does not exist.',
```

Add the French equivalents in `lang/fr/opes.php`: `'subtitle' => "Les couleurs, le logo et le favicon des écrans de l'établissement."`, `'presets' => 'Palettes prédéfinies'`, `'presets_hint' => "Un clic définit toutes les couleurs ci-dessous. Chaque palette est vérifiée en contraste."`, `'colours' => 'Couleurs'`, `'colours_hint' => "Les nuances de la barre latérale dérivent de la couleur secondaire."`, `'colour_primary' => 'Principale'`, `'colour_secondary' => 'Secondaire'`, `'colour_accent' => 'Accent'`, `'colour_success' => 'Succès'`, `'colour_warning' => 'Avertissement'`, `'colour_danger' => 'Danger'`, `'colour_hint_primary' => 'Boutons, liens et états actifs.'`, `'colour_hint_secondary' => "La barre latérale ; sa nuance foncée en dérive."`, `'colour_hint_accent' => "Mises en valeur uniquement — jamais un aplat ni un texte."`, `'colour_hint_success' => 'Payé, approuvé, terminé.'`, `'colour_hint_warning' => 'En attente, échéance proche.'`, `'colour_hint_danger' => 'En retard, rejeté, actions destructrices.'`, `'contrast_warning_title' => 'Certaines couleurs risquent d\'être illisibles'`, `'contrast_warning_item' => ':colour présente un contraste de :ratio:1 avec le texte qui s\'y affiche.'`, `'contrast_warning_body' => "WCAG AA exige au moins 4,5:1 pour un texte normal. Vous pouvez enregistrer, mais les écrans concernés seront difficiles à lire."`, `'preview' => 'Aperçu en direct'`, `'preview_hint' => "De vrais composants, repeints en direct."`, `'preview_kpi_label' => 'Élèves inscrits'`, `'preview_table_header' => 'Liste de classe'`, `'preview_table_row' => 'AZEMKEU Brice — 6e A'`, `'preview_pill_paid' => 'Payé'`, `'preview_pill_overdue' => 'En retard'`, `'preview_pill_pending' => 'En attente'`, `'preview_sidebar' => 'Navigation'`, `'preview_sidebar_active' => 'Élèves'`, `'unknown_preset' => "Cette palette n'existe pas."`.

- [ ] **Step 6: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/BrandingScreenTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/SchoolProfile/Livewire/Branding.php resources/views/livewire/schoolprofile/branding.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/BrandingScreenTest.php
git commit -m "feat(branding): multi-colour palette with presets, contrast checks and a live preview"
```

---

### Task 10: Paint the shell from the palette

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1-70`
- Test: `tests/Feature/Ui/ShellBrandingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Branding\BrandTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('emits every palette token as an unlayered custom property', function (): void {
    $user = p13coreUserAs(Role::Principal);

    app(WriteSetting::class)->handle(
        'branding.palette',
        BrandTokens::fromArray(['primary' => '#1B3A6B', 'secondary' => '#132B50'] + BrandTokens::DEFAULTS)->all(),
        $user->toAuditActor(),
    );

    $html = get('/settings')->assertOk()->getContent();

    expect($html)
        ->toContain('--color-primary: #1B3A6B')
        ->toContain('--color-chrome-light: #132B50')
        ->toContain('--color-heritage-yellow: #D9A829')
        // Unlayered. Tailwind 4 compiles utilities into @layer utilities and
        // unlayered CSS outranks every layered rule regardless of
        // specificity; wrapping this in @layer would ship a silent no-op.
        ->not->toContain('@layer');
});

it('renders the app logo in the shell when one is set', function (): void {
    $user = p13coreUserAs(Role::Principal);

    app(WriteSetting::class)->handle('branding.app_logo_path', 'branding/logo-abc123.png', $user->toAuditActor());

    get('/settings')->assertOk()->assertSee('branding/logo-abc123.png', false);
});

it('renders a favicon link when one is set', function (): void {
    $user = p13coreUserAs(Role::Principal);

    app(WriteSetting::class)->handle('branding.favicon_path', 'branding/icon-abc123.png', $user->toAuditActor());

    get('/settings')->assertOk()->assertSee('rel="icon"', false);
});

it('falls back to the Heritage defaults when the palette row is missing', function (): void {
    p13coreUserAs(Role::Principal);

    DB::table('settings')->where('key', 'branding.palette')->delete();

    get('/settings')->assertOk()->assertSee('--color-primary: #0B5A32', false);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/ShellBrandingTest.php`
Expected: FAIL — the layout emits only three variables derived from `branding.primary_color`, so `--color-heritage-yellow` is absent.

- [ ] **Step 3: Replace the layout's brand block**

In `resources/views/layouts/app.blade.php`, replace the `@php` brand block (the `use App\Modules\SchoolProfile\Livewire\Branding;` / `use App\Support\Branding\BrandPalette;` imports and the `$brandOverride` try/catch) with:

```php
    use App\Modules\SchoolProfile\Livewire\Branding;
    use App\Support\Branding\BrandTokens;
```

and

```php
    // The school's brand palette (/settings/branding), emitted as an
    // UNLAYERED :root override after the compiled stylesheet.
    //
    // Unlayered is load-bearing: Tailwind 4 compiles utilities into
    // @layer utilities, and unlayered CSS outranks every layered rule
    // regardless of specificity. A @layer components version of this block
    // measures correctly in devtools and repaints nothing.
    //
    // ReadSetting is cached (rememberForever, invalidated on write), so
    // this costs nothing beyond the first read per deploy. Wrapped
    // defensively: a hand-edited or stale palette must never take every
    // page in the app down over a cosmetic preference.
    $brandVariables = [];
    $appLogoPath = '';
    $faviconPath = '';

    try {
        $reader = app(ReadSetting::class);

        /** @var mixed $storedPalette */
        $storedPalette = $reader->handle(Branding::PALETTE_KEY, BrandTokens::DEFAULTS);

        $brandVariables = BrandTokens::fromArray(
            is_array($storedPalette) ? $storedPalette : BrandTokens::DEFAULTS
        )->toCssVariables();

        $appLogoPath = (string) $reader->handle('branding.app_logo_path', '');
        $faviconPath = (string) $reader->handle('branding.favicon_path', '');
    } catch (\Throwable) {
        $brandVariables = BrandTokens::defaults()->toCssVariables();
        $appLogoPath = '';
        $faviconPath = '';
    }

    // Only ever a relative path under branding/ on the `public` disk (the
    // uploader's own contract, mirrored by the settings validation_rule) -
    // so nothing hand-typed can become an arbitrary <img src> or icon href.
    $appLogoUrl = ($appLogoPath !== '' && str_starts_with($appLogoPath, 'branding/'))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($appLogoPath)
        : null;

    $faviconUrl = ($faviconPath !== '' && str_starts_with($faviconPath, 'branding/'))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($faviconPath)
        : null;
```

- [ ] **Step 4: Replace the `<style>` block and add the favicon link**

In the same file, replace the `@if ($brandOverride !== null) … @endif` `<style>` block in `<head>` with:

```blade
    @if ($faviconUrl !== null)
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    {{-- UNLAYERED on purpose - see the @php block above. --}}
    <style>
        :root {
            @foreach ($brandVariables as $brandVariableName => $brandVariableValue)
            {{ $brandVariableName }}: {{ $brandVariableValue }};
            @endforeach
        }
    </style>
```

- [ ] **Step 5: Render the app logo in the sidebar**

Still in `resources/views/layouts/app.blade.php`, find the inline `<svg class="h-16 w-16" viewBox="0 0 64 64" …>` crest mark in the sidebar (around line 110) and wrap it:

```blade
                @if ($appLogoUrl !== null)
                    {{-- The school's own logo replaces the built-in OPES mark
                         once one is uploaded. Height-constrained, width auto:
                         a school logo is any aspect ratio at all, and a fixed
                         square box would squash half of them. --}}
                    <img src="{{ $appLogoUrl }}" alt="{{ __('opes.branding.app_logo_alt') }}"
                         class="h-16 w-auto max-w-[200px] object-contain">
                @else
                    <svg class="h-16 w-16" viewBox="0 0 64 64" fill="none" stroke="var(--color-heritage-yellow)"
```

and close the conditional immediately after that `</svg>`:

```blade
                    </svg>
                @endif
```

**Read the surrounding lines before editing** — the existing SVG spans several lines including a `<text>` element; the `@else` must open before the `<svg` and the `@endif` must close after its matching `</svg>`.

- [ ] **Step 6: Add the alt-text lang key**

`lang/en/opes.php` under `'branding'`: `'app_logo_alt' => 'School logo'`. `lang/fr/opes.php`: `'app_logo_alt' => "Logo de l'établissement"`.

- [ ] **Step 7: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/ShellBrandingTest.php tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 8: Prove it visually — the screenshot is not optional**

Start the preview and check a real screen repaints. **Resize IMMEDIATELY before the screenshot, never straight after navigate/reload** — the pane loses its viewport after navigation and renders a tiny page, which previously produced a false bug report.

```
preview_start {name: "opes"}
navigate  http://localhost:8931/settings/branding
resize_window 1440x900        ← immediately before the screenshot, every time
computer screenshot
```

Set the primary to `#8A1F3D` (the Burgundy preset), save, then:

```
navigate  http://localhost:8931/students
resize_window 1440x900
computer screenshot
```

Expected: the sidebar and the primary buttons on `/students` are burgundy, not green. If they are still green, the override is being outranked — re-check that the `<style>` block is unlayered and sits **after** `@vite`.

- [ ] **Step 9: Build and commit**

```bash
npm run build
git add resources/views/layouts/app.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Ui/ShellBrandingTest.php
git commit -m "feat(branding): paint the app shell, logo and favicon from the brand palette"
```

---
# Phase 0b — Design system foundation

**Ordering note.** This phase comes *after* the branding screen on purpose, not before it. `BrandTokens` (Task 6) is already the single source of the six colours a school picks; Phase 0b builds the derived layer on top of it — the full 50→900 scales, the type/spacing/shadow/z-index/motion tokens, and the accessibility test that stops a future branding change shipping unreadable contrast. Building the derived layer first would have meant guessing at the shape of the thing it derives from.

**The `ui-design-system` skill supplies the algorithms this phase implements:** HSV colour-scale generation (fixed 95% value below step 500, exponential falloff above; saturation scaled `base × (0.3 + 0.7 × step/900)`), the 1.25 type ratio, the 8pt spacing grid, the sm/md/lg × primary/secondary/ghost variant matrix, WCAG AA at 4.5:1 normal / 3:1 large, and touch targets ≥ 44×44px. Its `scripts/design_token_generator.py` runs on this box (`python --version` → Python 3.13.0) and is used **once, at authoring time, to produce reference values a PHP test asserts against** — it can never run at request time, because the palette is chosen at runtime by an operator.

**Two repo-specific traps that must be stated in code comments, not just here:**

1. **The root font-size is 17px, not 16px** (`resources/css/app.css:175`). Every rem-based value in the skill's tables is computed against 16. An 8pt grid expressed in rem against a 17px root produces 8.5px steps — silently 6% off. **All spacing tokens in this phase are therefore declared in `px`, not `rem`**, so the 8pt grid is a real 8pt grid. Type sizes stay in rem (the existing `--text-base: 1rem` = 17px is deliberate and already shipped), and the 1.25 scale is computed against 17px, not 16px, so `text-lg` is 21.25px and not 20px. Tailwind's spacing *names* also lie at this root: `w-72` renders 306px and `w-56` renders 238px. Never reason about a pixel size from a utility name; measure it.
2. **Tailwind 4 layering.** Utilities compile into `@layer utilities`, and **unlayered CSS outranks every layered rule regardless of specificity.** Therefore: token *declarations* live unlayered in `resources/css/app.css`'s `@theme` block and in the layout's unlayered `<style>` override; component *treatments* that must beat a utility go unlayered in `app.css`; anything that must lose to a utility goes in `@layer components`. A previous agent's `@layer components` treatment shipped as a silent no-op that measured correctly in devtools.

**Icon decision (made here, so no later task re-opens it): keep the existing inline-SVG system; do not adopt lucide.** Evidence: `resources/views/components/opes-nav-icon.blade.php` already carries ~45 glyphs as raw path markup on a shared 24×24, `stroke-width` 1.6–2, `stroke="currentColor"` grid, with a documented dot fallback for unknown keys; `x-kpi-card`, `x-empty-state`, `x-portal-icon` and the shell chrome all draw the same way; there is no `lucide` dependency in `package.json`. Adopting lucide means a new npm dependency, a build step, and touching ~40 call sites — and a *partial* migration produces exactly the mixed-icon inconsistency this work is meant to remove. Instead, Task 12 writes the existing set's conventions down as an enforced contract and adds the missing glyphs. Where a new glyph is needed, it is **traced from the lucide glyph of the same name** so the family stays visually consistent with the icon language the user asked for, without the dependency.

---

### Task 11: `ColorScale` — the 50→900 ramp in PHP

**Files:**
- Create: `app/Support/Branding/ColorScale.php`
- Test: `tests/Unit/Support/ColorScaleTest.php`

- [ ] **Step 1: Capture the reference values from the skill's generator**

Run:

```bash
python "C:/Users/PC/.claude/plugins/cache/claude-code-skills/product-skills/2.3.3/skills/ui-design-system/scripts/design_token_generator.py" "#0B5A32" modern json > /tmp/heritage-tokens.json
python -c "import json;print(json.load(open('/tmp/heritage-tokens.json'))['colors']['primary'])"
```

Expected: a dict of ten hex values keyed `50,100,…,900`. **Paste those exact ten values into the test in Step 2** — they are the fixture the PHP implementation must reproduce.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\Branding\ColorScale;

/**
 * The PHP implementation must reproduce the ui-design-system skill's
 * generator exactly. That generator is a python script and cannot run at
 * request time - the palette is chosen at RUNTIME by an operator - so the
 * algorithm is reimplemented here and pinned to the script's output for the
 * platform's own brand colour.
 *
 * If this test fails after a refactor, the scale drifted from the documented
 * algorithm; do not "fix" it by editing the fixture.
 */
it('reproduces the reference ramp for Heritage Green', function (): void {
    // <-- Replace with the ten values printed in Step 1.
    $reference = [
        50 => '#EFF7F3', 100 => '#DCEDE4', 200 => '#B6DCC6',
        300 => '#8DCAA6', 400 => '#5FB884', 500 => '#0B5A32',
        600 => '#094A29', 700 => '#07381F', 800 => '#052614', 900 => '#02150B',
    ];

    expect(ColorScale::of('#0B5A32'))->toBe($reference);
});

it('keeps step 500 exactly as the colour supplied', function (): void {
    expect(ColorScale::of('#1B3A6B')[500])->toBe('#1B3A6B');
});

it('produces a monotonically darkening ramp', function (): void {
    $scale = ColorScale::of('#8A1F3D');
    $previous = null;

    foreach ($scale as $hex) {
        $sum = (int) hexdec(substr($hex, 1, 2))
            + (int) hexdec(substr($hex, 3, 2))
            + (int) hexdec(substr($hex, 5, 2));

        if ($previous !== null) {
            expect($sum)->toBeLessThanOrEqual($previous);
        }

        $previous = $sum;
    }
});

it('refuses a malformed hex', function (): void {
    ColorScale::of('#GGGGGG');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 3: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/ColorScaleTest.php`
Expected: FAIL — `Class "App\Support\Branding\ColorScale" not found`.

- [ ] **Step 4: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Support\Branding;

use InvalidArgumentException;

/**
 * The 50 -> 900 tint/shade ramp for one brand colour, in HSV.
 *
 * This is the ui-design-system skill's documented algorithm
 * (references/token-generation.md), reimplemented in PHP because the palette
 * is chosen at RUNTIME by an operator on /settings/branding and the skill's
 * python generator cannot run inside a request. ColorScaleTest pins this
 * implementation to that generator's output for the platform's own brand
 * colour, so the two cannot drift silently.
 *
 * Algorithm, verbatim:
 *   - hue is constant across the whole ramp;
 *   - value (brightness) is a fixed 0.95 below step 500, and above it decays
 *     as base_value * (1 - (step - 500) / 500), reaching ~20% at step 900;
 *   - saturation scales as base_saturation * (0.3 + 0.7 * step / 900);
 *   - step 500 is returned EXACTLY as supplied, so the colour a school picked
 *     is the colour it gets, not a re-quantised approximation of it.
 */
final class ColorScale
{
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
        // above still holds.
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

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
```

- [ ] **Step 5: Run the test and reconcile against the fixture**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/ColorScaleTest.php`
Expected: PASS, 4 tests. If the first test fails with a ±1-per-channel diff, that is rounding: compare the python generator's `int(round(...))` on the 0..255 channel with the PHP `(int) round(...)` — they agree, so a diff larger than ±1 means the HSV conversion is wrong. **Fix the implementation, never loosen the assertion.** A diff confined to steps 800/900 means the AA clamp fired; for `#0B5A32` it must not, so investigate rather than accept.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Branding/ColorScale.php tests/Unit/Support/ColorScaleTest.php
git commit -m "feat(design): add the HSV 50-900 colour scale generator"
```

---

### Task 12: The token layer in `app.css`, and the icon contract

**Files:**
- Modify: `resources/css/app.css` (append inside `@theme`)
- Modify: `resources/views/components/opes-nav-icon.blade.php` (header comment only)
- Create: `docs/superpowers/audits/2026-08-15-design-tokens.md`
- Test: `tests/Feature/Ui/DesignTokenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

/**
 * The token layer is a CONTRACT, not a stylesheet detail: Phase 7's role
 * dashboards are specified in terms of these names, so a rename silently
 * unstyles five screens.
 */
it('declares the full spacing, radius, shadow, z-index and motion scale', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    foreach ([
        '--space-1: 4px', '--space-2: 8px', '--space-3: 12px', '--space-4: 16px',
        '--space-6: 24px', '--space-8: 32px', '--space-12: 48px', '--space-16: 64px',
        '--radius-card', '--shadow-e1', '--shadow-e2', '--shadow-e3',
        '--z-sticky', '--z-modal', '--z-toast',
        '--motion-fast', '--motion-base', '--ease-standard',
        '--tap-target: 44px',
    ] as $token) {
        expect($css)->toContain($token);
    }
});

it('declares spacing in px, never rem, because the root is 17px', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // An 8pt grid expressed in rem against a 17px root is an 8.5pt grid.
    expect($css)->not->toMatch('/--space-\d+:\s*[\d.]+rem/');
});

it('keeps the 17px root', function (): void {
    expect((string) file_get_contents(base_path('resources/css/app.css')))
        ->toContain('font-size: 17px');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/DesignTokenTest.php`
Expected: FAIL — `--space-1: 4px` is absent.

- [ ] **Step 3: Append the token block to `app.css`**

Add inside the existing `@theme { … }` block in `resources/css/app.css`, after the KPI tints. **Note:** `--radius-xl` already exists there with a `1rem` value and is left untouched; the new radii are named for the surface they belong to so the two do not collide.

```css
    /* --------------------------------------------------------------------
       DESIGN TOKENS (2026-08-15). The scales every screen is composed from,
       so a card's padding, a heading's size and a panel's shadow are chosen
       ONCE here rather than re-picked per screen.

       SPACING IS DECLARED IN PIXELS ON PURPOSE. The root font-size of this
       app is 17px (see the `html` rule further down), not the 16px every
       published 8pt-grid table assumes. An 8pt grid expressed in rem against
       a 17px root is an 8.5pt grid - silently 6% off, compounding at the
       larger steps. Tailwind's spacing NAMES lie at this root too: `w-72`
       renders 306px, `w-56` renders 238px. Never reason about a pixel size
       from a utility name.

       Type stays in rem: --text-base: 1rem = 17px is the shipped, deliberate
       body size, and the 1.25 modular scale below is computed against 17,
       not 16 - so `lg` is 21.25px, not the 20px the generic table gives.
       -------------------------------------------------------------------- */

    /* 8pt grid. -1 and -3 are the half-steps the grid allows for dense
       controls (chip padding, icon gaps); everything structural uses
       8/16/24/32. */
    --space-0: 0px;
    --space-1: 4px;
    --space-2: 8px;
    --space-3: 12px;
    --space-4: 16px;
    --space-6: 24px;
    --space-8: 32px;
    --space-12: 48px;
    --space-16: 64px;

    /* Type: 1.25 modular scale against the 17px root. */
    --text-xs: 0.64rem;      /* 10.9px - metadata, table micro-labels */
    --text-sm: 0.8rem;       /* 13.6px - secondary text, helper copy */
    /* --text-base: 1rem (17px) is declared above and unchanged. */
    --text-lg: 1.25rem;      /* 21.25px - card titles */
    --text-xl: 1.5625rem;    /* 26.6px  - section headings */
    --text-2xl: 1.953rem;    /* 33.2px  - KPI numerals */
    --text-3xl: 2.441rem;    /* 41.5px  - page titles */

    /* Radii, named by the surface they belong to so a card and a chip
       cannot drift apart across screens. */
    --radius-chip: 999px;
    --radius-control: 8px;
    --radius-card: 12px;
    --radius-panel: 16px;

    /* Elevation. Three levels, deliberately: e1 is a resting card, e2 a
       hovered/raised one, e3 a floating surface (modal, toast, sticky save
       bar). A fourth level is how a UI stops reading as layered. Layered
       shadows - a tight contact shadow plus a wide ambient one. */
    --shadow-e1: 0 1px 2px rgba(1, 60, 31, 0.06), 0 1px 3px rgba(1, 60, 31, 0.08);
    --shadow-e2: 0 2px 4px rgba(1, 60, 31, 0.06), 0 4px 12px rgba(1, 60, 31, 0.10);
    --shadow-e3: 0 4px 8px rgba(1, 60, 31, 0.08), 0 16px 40px rgba(1, 60, 31, 0.14);

    /* Layering, named, so nobody reaches for z-50 and wonders why the toast
       is behind the modal. */
    --z-base: 0;
    --z-sticky: 20;
    --z-drawer: 30;
    --z-modal: 40;
    --z-toast: 50;

    /* Motion. Fast for a state change the finger caused, base for anything
       entering or leaving. Standard easing decelerates: things arrive
       quickly and settle. */
    --motion-fast: 120ms;
    --motion-base: 200ms;
    --motion-slow: 320ms;
    --ease-standard: cubic-bezier(0.2, 0, 0, 1);

    /* Minimum hit area for anything tappable (WCAG 2.5.5). Applied to
       icon-only controls, which are the ones that fail it. */
    --tap-target: 44px;
```

- [ ] **Step 4: Write down the icon contract**

Replace the header comment of `resources/views/components/opes-nav-icon.blade.php` (the `{{-- Small icon-per-nav-item set … --}}` block) with:

```blade
{{--
    THE platform icon set. Inline outline glyphs on a shared 24x24 viewBox,
    fill="none", stroke="currentColor", stroke-width 1.6-2, round caps and
    joins - the conventions the shell chrome, x-kpi-card and x-empty-state
    already draw with. A key with no entry falls back to a plain dot, so a
    future nav item never renders as a missing image.

    WHY NOT LUCIDE. The obvious answer to "we want a consistent icon
    language" is to adopt an icon library, and lucide is the right one to
    want. It is not the right one to ADOPT here: this set already carries
    ~45 glyphs on one consistent grid with no build step and no npm
    dependency, and switching means touching every call site. A PARTIAL
    migration - lucide on the new screens, these on the old - is precisely
    the mixed-icon inconsistency the change was meant to remove, and that is
    the realistic outcome of starting one.

    So: this set stays, and it is the ONLY icon source for staff screens. New
    glyphs are TRACED FROM THE LUCIDE GLYPH OF THE SAME NAME (lucide is also
    a 24x24, currentColor, round-cap outline set, so the tracing is
    faithful), which keeps the family consistent with the icon language the
    product is aiming at without taking the dependency.

    Rules for adding one:
      1. 24x24 viewBox, no fill, stroke inherits.
      2. Optical weight matched to its neighbours - trace, do not invent.
      3. Named for the DOMAIN CONCEPT ('fiscal_identity'), never the picture
         ('shield'), so a redraw is not a rename across every call site.
--}}
```

- [ ] **Step 5: Record the decisions**

Create `docs/superpowers/audits/2026-08-15-design-tokens.md`:

```markdown
# Design token decisions — 2026-08-15

Algorithms from the `product-skills:ui-design-system` skill
(`references/token-generation.md`, `references/component-architecture.md`,
`references/responsive-calculations.md`).

## Generated vs. picked

| Layer | Source | Where it lives |
|---|---|---|
| The six brand colours | Picked by the school on `/settings/branding` | settings key `branding.palette` (JSON) |
| The 50→900 ramp per colour | Derived, HSV algorithm | `App\Support\Branding\ColorScale` |
| Shell CSS variables | Derived from the palette | `BrandTokens::toCssVariables()`, emitted unlayered in the layout head |
| Spacing / type / radius / shadow / z-index / motion | Fixed, not brandable | `@theme` in `resources/css/app.css` |

The skill's `scripts/design_token_generator.py` produced the reference ramp
that `tests/Unit/Support/ColorScaleTest.php` pins the PHP implementation to.
It is an authoring-time tool only: the palette is chosen at runtime, so the
generator cannot be in the request path.

## The 17px trap

The root font-size is **17px**, not the 16px every published 8pt-grid and
modular-scale table assumes.

- **Spacing tokens are declared in `px`.** In rem against a 17px root an
  "8pt" grid is an 8.5pt grid — 6% off, compounding at the larger steps.
- **Type stays in rem** and the 1.25 scale is computed against 17px, so `lg`
  is 21.25px rather than the table's 20px.
- **Tailwind's spacing names lie**: `w-72` is 306px, `w-56` is 238px.
  Measure; never infer a pixel size from a utility name.

## The Tailwind 4 layering trap

Utilities compile into `@layer utilities`, and **unlayered CSS outranks every
layered rule regardless of specificity.**

| Kind of rule | Where it goes | Why |
|---|---|---|
| Token declarations | `@theme` / unlayered `:root` | The runtime `<style>` override MUST be unlayered to beat utilities that read them |
| Runtime brand override | Unlayered `<style>` in the layout head, after `@vite` | Must beat the compiled defaults |
| Treatment that must beat a utility | Unlayered in `app.css` | e.g. the `.opes-app` form-control treatment |
| Treatment a utility should override | `@layer components` | So `class="rounded-none"` still wins |

A `@layer components` version of a treatment that needs to win ships as a
**silent no-op that measures correctly in devtools**. This has already
happened once in this codebase.

## Icons

The existing inline-SVG set (`x-opes-nav-icon`) is the only staff icon
source. Lucide is not adopted; new glyphs are traced from the lucide glyph of
the same name. Rationale in that component's header comment.

## Component variant matrix (ui-design-system Workflow 2)

Sizes, at the 17px root — heights are px, not utility names:

| Size | Height | Padding X | Font |
|---|---|---|---|
| sm | 32px | 12px | `--text-sm` |
| md | 40px | 16px | `--text-base` |
| lg | 48px | 20px | `--text-lg` |

Variants: `primary` (fill `--color-primary`, white text), `secondary`
(surface `--color-sand`, charcoal text, `--color-border-primary` border),
`ghost` (transparent, charcoal text, hover `--color-sand`). Icon-only
controls get `min-width`/`min-height` of `--tap-target` (44px) regardless of
visual size.
```

- [ ] **Step 6: Run the test and rebuild**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/DesignTokenTest.php`
Expected: PASS, 3 tests.

Run: `npm run build`
Expected: success. A CSS syntax error inside `@theme` fails the build loudly — that is the check.

- [ ] **Step 7: Commit**

```bash
git add resources/css/app.css resources/views/components/opes-nav-icon.blade.php docs/superpowers/audits/2026-08-15-design-tokens.md tests/Feature/Ui/DesignTokenTest.php
git commit -m "feat(design): add the spacing, type, elevation and motion token layer"
```

---

### Task 13: Contrast as a test, not a note

**Files:**
- Create: `tests/Feature/Ui/PaletteAccessibilityTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Support\Branding\BrandTokens;
use App\Support\Branding\ColorContrast;
use App\Support\Branding\ColorScale;

/**
 * The pairs this platform ACTUALLY renders, asserted against WCAG AA - so a
 * future palette change, preset addition or scale tweak cannot silently ship
 * a screen nobody can read.
 *
 * Only real combinations are listed. Asserting hypothetical pairs produces a
 * test that fails for reasons nobody can act on, which is how a suite gets
 * skipped.
 */
$textOnFill = [
    ['#FFFFFF', 'primary', 'the primary button and the table header'],
    ['#FFFFFF', 'secondary', 'the sidebar active surface'],
    ['#FFFFFF', 'success', 'the "Paid" status pill'],
    ['#FFFFFF', 'danger', 'the "Overdue" pill and destructive buttons'],
];

it('clears AA for white text on every solid fill in the default palette', function () use ($textOnFill): void {
    $tokens = BrandTokens::defaults();

    foreach ($textOnFill as [$text, $token, $where]) {
        expect(ColorContrast::ratio($text, $tokens->get($token)))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "{$where} fails AA");
    }
});

it('clears AA for white text on every solid fill in every shipped preset', function () use ($textOnFill): void {
    foreach (BrandPreset::all() as $preset) {
        $tokens = BrandTokens::fromArray($preset['colors']);

        foreach ($textOnFill as [$text, $token, $where]) {
            expect(ColorContrast::ratio($text, $tokens->get($token)))
                ->toBeGreaterThanOrEqual(
                    ColorContrast::AA_NORMAL,
                    "preset [{$preset['key']}]: {$where} fails AA",
                );
        }
    }
});

it('clears AA for charcoal body text on the KPI card washes', function (): void {
    // The KPI tints are ~4% saturation washes precisely so text contrast is
    // untouched; this is the assertion that keeps them that way.
    foreach (['#EAF6EC', '#EAF1FB', '#FFF5D9', '#FDECEC'] as $wash) {
        expect(ColorContrast::ratio('#14201A', $wash))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "charcoal on {$wash} fails AA");
    }
});

it('gives a usable text shade at step 800 of any brand ramp', function (): void {
    // Dashboards print a brand-tinted heading over a white card. Step 800 is
    // the ramp position reserved for that, so it must be readable for ANY
    // colour a school might pick - including a light one.
    foreach (['#0B5A32', '#1B3A6B', '#8A1F3D', '#D9A829', '#5FB884'] as $brand) {
        expect(ColorContrast::ratio(ColorScale::of($brand)[800], '#FFFFFF'))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "step 800 of {$brand} fails AA on white");
    }
});
```

- [ ] **Step 2: Run it**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Ui/PaletteAccessibilityTest.php`
Expected: PASS, 4 tests. The step-800 assertion passes because of the AA clamp already written into `ColorScale::of()` in Task 11; if it fails, that clamp is missing or was removed.

If the preset assertion fails for a preset, **fix the preset's hex, not the threshold** — Task 8's own test already refuses an unreadable preset primary, and this test extends the same rule to the other fills.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Ui/PaletteAccessibilityTest.php
git commit -m "test(design): assert WCAG AA for every palette pair the product renders"
```

---
# Phase 1 — Image uploads and document rendering

**The gap:** `SaveDocumentProfile` already validates and persists all five image path columns (`crest_path`, `logo_path`, `principal_signature_path`, `registrar_signature_path`, `school_stamp_path`) as `['nullable','string','max:255']`, `RenderDocument::captureSchoolChrome()` already puts all five into `$chrome['branding']`, and that chrome is already frozen into the snapshot/envelope at issue. Two things are missing: **the screen cannot upload**, and **only `crest_path` is rendered anywhere** (`school_header.blade.php:8-9`). Nothing draws the logo, the signatures or the stamp.

**The dompdf constraint, which shapes everything here:** `DompdfRenderer.php:39` sets `setIsRemoteEnabled(false)`. `<img src="/storage/x.png">` and any `http://` URL **will not load** — dompdf silently renders nothing where the image should be. Images must therefore be embedded as **base64 `data:` URIs** built at render time from the stored relative path.

**The reproducibility hazard, stated plainly and addressed by design.** The chrome frozen at issue contains the *path*. Reprints re-render from that frozen chrome and hash-compare against `content_hash`. If a school later replaced `branding/signature.png` with a different image **at the same path**, every document issued before the replacement would re-render with different bytes and throw `DocumentReproducibilityViolation` — permanently, for every certificate that school ever issued. That is a catastrophic, silent, unrecoverable failure mode.

## The eight uploadable assets — named once, so nothing is conflated

**There are TWO different logos, with different consumers, different sizes and different storage.** An implementer who conflates them ships a favicon on a certificate. They are:

| # | Asset | Consumer | Stored in | Rendered by | Guidance |
|---|---|---|---|---|---|
| 1 | **Document crest** | dompdf | `school_document_profiles.crest_path` | `documents/blocks/school_header.blade.php`, centred above the school name, 52pt tall | Square-ish, ≥ 300px, transparent PNG |
| 2 | **Document logo** | dompdf | `school_document_profiles.logo_path` | `school_header.blade.php`, floated right, 40pt tall — **rendered NOWHERE today; that is the gap, not a decision to carry forward** | Any aspect, ≥ 300px wide |
| 3 | **Principal signature** | dompdf | `.principal_signature_path` | `signature_block.blade.php`, above the `principal` rule, 34pt tall | Wide, transparent PNG, ink on white |
| 4 | **Registrar signature** | dompdf | `.registrar_signature_path` | `signature_block.blade.php`, above the `registrar` rule | As above |
| 5 | **School stamp** | dompdf | `.school_stamp_path` | `signature_block.blade.php`, beside the signatories, 70pt | Square, transparent PNG |
| 6 | **Watermark image** | dompdf | `.watermark_image_path` (Phase 2) | `documents/blocks/school_watermark.blade.php`, ≤55% page width | Square-ish, high contrast, mono |
| 7 | **Platform / app-shell logo** | **browser** | settings key `branding.app_logo_path` | `layouts/app.blade.php`, top of the sidebar, 64px tall | Wide or square, ≤ 2000px |
| 8 | **Favicon** | **browser** | settings key `branding.favicon_path` | `<link rel="icon">` in `layouts/app.blade.php` | **Square, ≤ 512px** |

**Why 7 and 8 are settings keys rather than columns on `school_document_profiles`:** that table is the *document* profile — every column in it is frozen into `render_envelope` on every issued document by `captureSchoolChrome()`. The app-shell logo and the favicon are never printed on anything and carry none of that reproducibility burden; putting them there would freeze two irrelevant strings into every certificate the school ever issues, forever. They belong with `branding.primary_color` and `branding.palette` in the cosmetic settings store, which is exactly what that store is for. (This is why Task 17's delete-on-replace is unconditional while Task 16's is deferred to after the commit: only assets 1–6 are frozen anywhere.)

**Shared upload rules, specified once in `StoredImage` and reused by all eight** (Task 14):

- **Disk:** `public` (`storage/app/public`); the `public/storage` symlink already exists. Confirm with `artisan storage:link`.
- **Directory:** `branding/`. `EmbeddedImage` refuses to resolve anything outside it, so a hand-edited path column cannot inline an arbitrary file into a PDF.
- **Allowed types:** `png`, `jpg`, `jpeg`, `webp`. **SVG is refused** — it is a script-capable document served from this app's own origin (stored XSS), and dompdf's SVG support is partial anyway.
- **Max size:** 2048 KB per asset. **Max dimension:** 2000px on the longest edge, except the favicon at 512px.
- **Filename:** `branding/{slot}-{16 hex of sha256}.{ext}` — content-hashed, which is what protects frozen reproducibility.
- **Delete-on-replace:** yes, guarded so it never deletes when the path is unchanged and never touches anything outside `branding/`.
- **Preview:** a 64×64 thumbnail beside every slot, showing the pending upload's `temporaryUrl()` when one exists, else the stored file, else an explicit "None".
- **Livewire:** `WithFileUploads` — the codebase's first use. Livewire parks the temp file under `livewire-tmp/` on the default disk and prunes it after 24h; the only rule a screen must honour is to move the file into permanent storage inside `save()` and release the `TemporaryUploadedFile` immediately (a temp file left on a public property is re-serialised into every subsequent request payload).

**The fix is in the filename, not the renderer:** `StoredImage` writes every upload under `branding/{slot}-{sha256-prefix}.{ext}`. Replacing an image produces a **different content hash and therefore a different path**, so the old path either still holds the old bytes or is gone. A frozen path can never silently change what it resolves to. Task 19 tests exactly this. (The old file is deleted on replace, so a *deleted* old path resolves to nothing and the render is byte-different anyway — which is why `EmbeddedImage` must resolve a missing file to `null` **deterministically**, and why deletion is deferred: see Task 14, Step 4.)

---

### Task 14: `StoredImage` — content-hashed storage

**Files:**
- Create: `app/Support/Storage/StoredImage.php`
- Test: `tests/Unit/Support/StoredImageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\Storage\StoredImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('stores under a content-hashed filename inside branding/', function (): void {
    $path = StoredImage::put('crest', UploadedFile::fake()->image('anything.png', 200, 200));

    expect($path)->toStartWith('branding/crest-')
        ->and($path)->toEndWith('.png')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('gives identical bytes the identical path', function (): void {
    $bytes = (string) file_get_contents(__FILE__);

    expect(StoredImage::putContents('crest', $bytes, 'png'))
        ->toBe(StoredImage::putContents('crest', $bytes, 'png'));
});

it('gives different bytes a DIFFERENT path', function (): void {
    // THE load-bearing property. A frozen document chrome stores the PATH; if
    // replacing an image reused the path, every document issued before the
    // replacement would re-render to different bytes and fail its
    // reproducibility check forever.
    expect(StoredImage::putContents('signature', 'first version', 'png'))
        ->not->toBe(StoredImage::putContents('signature', 'second version', 'png'));
});

it('keeps the slot in the filename so a stray file is identifiable', function (): void {
    expect(StoredImage::putContents('school_stamp', 'x', 'png'))
        ->toStartWith('branding/school-stamp-');
});

it('refuses an extension outside the allow-list', function (): void {
    StoredImage::putContents('crest', 'x', 'svg');
})->throws(InvalidArgumentException::class, 'svg');

it('deletes a previous path only when it differs from the new one', function (): void {
    $old = StoredImage::putContents('crest', 'old bytes', 'png');
    $new = StoredImage::putContents('crest', 'new bytes', 'png');

    StoredImage::forget($old, $new);

    expect(Storage::disk('public')->exists($old))->toBeFalse()
        ->and(Storage::disk('public')->exists($new))->toBeTrue();

    // Same path on both sides: a re-upload of identical bytes must NOT
    // delete the file it just wrote.
    StoredImage::forget($new, $new);

    expect(Storage::disk('public')->exists($new))->toBeTrue();
});

it('never deletes a path outside branding/', function (): void {
    Storage::disk('public')->put('documents/keep-me.pdf', 'x');

    StoredImage::forget('documents/keep-me.pdf', 'branding/crest-abc.png');

    expect(Storage::disk('public')->exists('documents/keep-me.pdf'))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/StoredImageTest.php`
Expected: FAIL — `Class "App\Support\Storage\StoredImage" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Branding images (crest, logo, signatures, stamp, favicon) on the `public`
 * disk, stored under a CONTENT-HASHED filename.
 *
 * The hash is not a cache-busting nicety - it is the whole reason this class
 * exists. RenderDocument freezes the school chrome, INCLUDING these paths,
 * onto every issued document, and a reprint re-renders from that frozen
 * chrome and compares the SHA-256 of the resulting PDF against the hash
 * recorded at issue. If replacing the principal's signature reused the path
 * `branding/principal_signature.png`, then every certificate issued before
 * the replacement would re-render with different bytes and throw
 * DocumentReproducibilityViolation - permanently, for every document that
 * school ever issued, with no way back.
 *
 * Content-hashing makes that impossible: different bytes, different path. A
 * frozen path either still resolves to the bytes it resolved to at issue, or
 * resolves to nothing at all (see EmbeddedImage, which renders a missing file
 * as no image AT ALL rather than a broken-image box, deterministically).
 *
 * SVG is deliberately NOT allowed. An SVG is a script-capable document, these
 * files are served from the app's own origin, and dompdf's SVG support is
 * partial anyway - an uploaded SVG would be both a stored-XSS surface and an
 * unreliable render.
 */
final class StoredImage
{
    public const DISK = 'public';

    public const DIRECTORY = 'branding';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** The longest edge an uploaded branding image may have, in pixels. */
    public const MAX_DIMENSION = 2000;

    /** The largest an uploaded branding image may be, in kilobytes. */
    public const MAX_KILOBYTES = 2048;

    public static function put(string $slot, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $contents = (string) file_get_contents($file->getRealPath());

        return self::putContents($slot, $contents, $extension);
    }

    public static function putContents(string $slot, string $contents, string $extension): string
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                "[{$extension}] is not an allowed branding image type; "
                .'allowed: '.implode(', ', self::ALLOWED_EXTENSIONS).'.'
            );
        }

        // 16 hex characters of SHA-256: 64 bits of collision resistance over
        // a handful of files per install, and a filename that still fits in
        // the 255-character column comfortably.
        $digest = substr(hash('sha256', $contents), 0, 16);

        $path = self::DIRECTORY.'/'.Str::slug($slot).'-'.$digest.'.'.$extension;

        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    /**
     * Delete the image a slot USED to hold, now that it holds $keep.
     *
     * Two guards, both load-bearing:
     *   - never delete when the two paths are equal (re-uploading identical
     *     bytes yields the same path, and deleting it would erase the image
     *     that was just "saved");
     *   - never delete anything outside branding/, so a hand-edited path
     *     column can never turn a settings save into a delete of an issued
     *     PDF.
     */
    public static function forget(?string $previous, ?string $keep): void
    {
        if ($previous === null || $previous === '' || $previous === $keep) {
            return;
        }

        if (! str_starts_with($previous, self::DIRECTORY.'/')) {
            return;
        }

        Storage::disk(self::DISK)->delete($previous);
    }
}
```

- [ ] **Step 4: Note the deletion-vs-reproducibility interaction in the class**

Append to the `forget()` docblock, above the two bullet guards:

```php
     * NOTE ON REPRODUCIBILITY. Deleting the previous file DOES change what a
     * document frozen against that path re-renders to - from "the old image"
     * to "no image". That is deliberate and is the SAFE direction of the
     * trade: the alternative (never deleting) leaves the disk accumulating
     * every image a school ever tried, and the unsafe direction (reusing the
     * path) makes the OLD documents silently re-render with the NEW image,
     * which is a forgery rather than a failure.
     *
     * A school that replaces a signature and then reprints an old
     * certificate gets an honest DocumentReproducibilityViolation (422)
     * rather than a certificate carrying a signature that was not on the
     * original. Where an install needs the old artefacts to keep reprinting,
     * the operator keeps the old file: call sites may skip forget() and the
     * frozen path keeps resolving. Task 19 asserts both halves of this.
```

- [ ] **Step 5: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/StoredImageTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Storage/StoredImage.php tests/Unit/Support/StoredImageTest.php
git commit -m "feat(storage): add content-hashed branding image storage"
```

---

### Task 15: `EmbeddedImage` — relative path to data URI

**Files:**
- Create: `app/Modules/Reporting/Domain/EmbeddedImage.php`
- Test: `tests/Unit/Support/EmbeddedImageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\EmbeddedImage;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('returns a base64 data URI for a stored image', function (): void {
    Storage::disk('public')->put('branding/crest-abc.png', 'PNGBYTES');

    expect(EmbeddedImage::dataUri('branding/crest-abc.png'))
        ->toBe('data:image/png;base64,'.base64_encode('PNGBYTES'));
});

it('maps each allowed extension to its mime type', function (): void {
    Storage::disk('public')->put('branding/a-1.jpg', 'J');
    Storage::disk('public')->put('branding/a-2.webp', 'W');

    expect(EmbeddedImage::dataUri('branding/a-1.jpg'))->toStartWith('data:image/jpeg;base64,')
        ->and(EmbeddedImage::dataUri('branding/a-2.webp'))->toStartWith('data:image/webp;base64,');
});

it('returns null for a missing file rather than a broken image', function (): void {
    // dompdf has remote assets disabled and renders nothing for an
    // unresolvable src; returning null lets the BLADE omit the <img>
    // entirely, which is a document with no crest rather than a document
    // with a hole in it.
    expect(EmbeddedImage::dataUri('branding/never-existed.png'))->toBeNull();
});

it('returns null for null, empty and non-branding paths', function (): void {
    Storage::disk('public')->put('documents/secret.pdf', 'x');

    expect(EmbeddedImage::dataUri(null))->toBeNull()
        ->and(EmbeddedImage::dataUri(''))->toBeNull()
        // A path column is operator-editable text; it must never be able to
        // inline an arbitrary file off the disk into a PDF.
        ->and(EmbeddedImage::dataUri('documents/secret.pdf'))->toBeNull()
        ->and(EmbeddedImage::dataUri('../../.env'))->toBeNull();
});

it('resolves the same bytes to the same URI every time', function (): void {
    Storage::disk('public')->put('branding/crest-abc.png', 'PNGBYTES');

    expect(EmbeddedImage::dataUri('branding/crest-abc.png'))
        ->toBe(EmbeddedImage::dataUri('branding/crest-abc.png'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/EmbeddedImageTest.php`
Expected: FAIL — `Class "App\Modules\Reporting\Domain\EmbeddedImage" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored branding path into a base64 `data:` URI a document template
 * can put in an <img src>.
 *
 * DompdfRenderer sets setIsRemoteEnabled(false) (deliberately - a template
 * able to reach the network is an injection surface, not a feature), so
 * `<img src="/storage/crest.png">` and every http URL resolve to NOTHING in a
 * rendered PDF. The image has to travel inside the HTML.
 *
 * DETERMINISM. Everything here is a pure function of the bytes on disk: the
 * same file always produces the same URI, and a missing file always produces
 * null. That is what lets an issued document's reprint reproduce its hash -
 * see StoredImage for why the PATH itself is content-hashed.
 *
 * SCOPE. Only paths inside StoredImage::DIRECTORY resolve. These paths come
 * from operator-editable text columns, and without this guard a
 * hand-typed `../../.env` would be base64-inlined into a printed PDF.
 */
final class EmbeddedImage
{
    private const MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public static function dataUri(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        if (! str_starts_with($relativePath, StoredImage::DIRECTORY.'/')) {
            return null;
        }

        if (str_contains($relativePath, '..')) {
            return null;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $mime = self::MIME[$extension] ?? null;

        if ($mime === null) {
            return null;
        }

        $disk = Storage::disk(StoredImage::DISK);

        if (! $disk->exists($relativePath)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($relativePath));
    }

    /**
     * Resolve a whole `branding` chrome block's paths to data URIs, keyed by
     * the SAME names the blades read, so a template asks for
     * `$school['branding']['crest_uri']` and never has to know about disks.
     *
     * The original `*_path` keys are left in place untouched: they are what
     * was FROZEN at issue and what a later audit reads back.
     *
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    public static function resolveBranding(array $branding): array
    {
        foreach ([
            'crest_path' => 'crest_uri',
            'logo_path' => 'logo_uri',
            'principal_signature_path' => 'principal_signature_uri',
            'registrar_signature_path' => 'registrar_signature_uri',
            'school_stamp_path' => 'school_stamp_uri',
            'watermark_image_path' => 'watermark_image_uri',
        ] as $pathKey => $uriKey) {
            /** @var mixed $value */
            $value = $branding[$pathKey] ?? null;

            $branding[$uriKey] = is_string($value) ? self::dataUri($value) : null;
        }

        return $branding;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/EmbeddedImageTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reporting/Domain/EmbeddedImage.php tests/Unit/Support/EmbeddedImageTest.php
git commit -m "feat(documents): embed branding images as data URIs for dompdf"
```

---

### Task 16: Upload the five document images

**Files:**
- Modify: `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`
- Modify: `resources/views/livewire/schoolprofile/document-profile.blade.php` (the marks fieldset)
- Test: `tests/Feature/SchoolProfile/DocumentImageUploadTest.php`

This is the codebase's **first** `WithFileUploads` usage — nothing else in the app uses the trait. Livewire stores the temporary file on the `livewire-tmp` directory of the default filesystem disk and cleans it up automatically after 24h; nothing extra is needed for that lifecycle, but the temp file must be moved into permanent storage inside `save()`, never held across requests.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\DocumentProfile;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('stores an uploaded crest and persists its content-hashed path', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('crest.png', 400, 400))
        ->call('save')
        ->assertHasNoErrors();

    $stored = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    expect($stored)->toStartWith('branding/crest-')
        ->and(Storage::disk('public')->exists($stored))->toBeTrue();
});

it('refuses a file that is not an allowed image type', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->create('crest.svg', 10, 'image/svg+xml'))
        ->call('save')
        ->assertHasErrors('crestUpload');

    expect(DB::table('school_document_profiles')->where('id', 1)->value('crest_path'))->toBeNull();
});

it('refuses an image larger than the size cap', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('huge.png', 400, 400)->size(StoredImage::MAX_KILOBYTES + 1))
        ->call('save')
        ->assertHasErrors('crestUpload');
});

it('refuses an image whose longest edge exceeds the dimension cap', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('wide.png', StoredImage::MAX_DIMENSION + 100, 200))
        ->call('save')
        ->assertHasErrors('crestUpload');
});

it('deletes the image a slot used to hold when it is replaced', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('one.png', 100, 100))
        ->call('save');

    $first = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('two.png', 220, 180))
        ->call('save');

    $second = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    expect($second)->not->toBe($first)
        ->and(Storage::disk('public')->exists($first))->toBeFalse()
        ->and(Storage::disk('public')->exists($second))->toBeTrue();
});

it('clears a slot when the operator removes the image', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('one.png', 100, 100))
        ->call('save');

    $path = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    Livewire::test(DocumentProfile::class)
        ->call('removeImage', 'crest')
        ->call('save');

    expect(DB::table('school_document_profiles')->where('id', 1)->value('crest_path'))->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('handles all five slots', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('a.png', 100, 100))
        ->set('logoUpload', UploadedFile::fake()->image('b.png', 100, 100))
        ->set('principalSignatureUpload', UploadedFile::fake()->image('c.png', 300, 100))
        ->set('registrarSignatureUpload', UploadedFile::fake()->image('d.png', 300, 100))
        ->set('schoolStampUpload', UploadedFile::fake()->image('e.png', 200, 200))
        ->call('save')
        ->assertHasNoErrors();

    $row = DB::table('school_document_profiles')->where('id', 1)->first();

    foreach ([
        'crest_path', 'logo_path', 'principal_signature_path',
        'registrar_signature_path', 'school_stamp_path',
    ] as $column) {
        expect($row->{$column})->toStartWith('branding/');
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/DocumentImageUploadTest.php`
Expected: FAIL — `Unable to set component property [crestUpload]`.

- [ ] **Step 3: Add the upload machinery to the component**

In `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`, add the imports:

```php
use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
```

Add the trait to the class, `final class DocumentProfile extends Component` body opening:

```php
    // The codebase's FIRST Livewire upload. Livewire parks the temp file on
    // the default disk under livewire-tmp/ and prunes it after 24h on its
    // own; the only rule this screen has to honour is that a temporary file
    // is moved into permanent storage inside save() and never carried across
    // a request.
    use WithFileUploads;
```

Add the five upload properties and the slot map after `public string $schoolStampPath = '';`:

```php
    public ?TemporaryUploadedFile $crestUpload = null;

    public ?TemporaryUploadedFile $logoUpload = null;

    public ?TemporaryUploadedFile $principalSignatureUpload = null;

    public ?TemporaryUploadedFile $registrarSignatureUpload = null;

    public ?TemporaryUploadedFile $schoolStampUpload = null;

    /**
     * slot => [upload property, path property, database column].
     *
     * One list rather than five near-identical blocks: the validation, the
     * store, the delete-on-replace and the preview all walk it, so a sixth
     * image is one line rather than four edits that can disagree.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const IMAGE_SLOTS = [
        'crest' => ['crestUpload', 'crestPath', 'crest_path'],
        'logo' => ['logoUpload', 'logoPath', 'logo_path'],
        'principal_signature' => ['principalSignatureUpload', 'principalSignaturePath', 'principal_signature_path'],
        'registrar_signature' => ['registrarSignatureUpload', 'registrarSignaturePath', 'registrar_signature_path'],
        'school_stamp' => ['schoolStampUpload', 'schoolStampPath', 'school_stamp_path'],
    ];
```

- [ ] **Step 4: Add validation, removal and the store step**

Add these methods to the same class:

```php
    /**
     * Livewire validation for the five upload slots.
     *
     * `image` plus an explicit mimes list plus a dimension cap, all three:
     * `image` alone admits SVG (a script-capable document served from this
     * app's own origin), the mimes list alone admits a 12 000 px scan that
     * makes every PDF 40 MB, and the dimension cap alone admits a renamed
     * executable.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rule = [
            'nullable',
            'image',
            'mimes:'.implode(',', StoredImage::ALLOWED_EXTENSIONS),
            'max:'.StoredImage::MAX_KILOBYTES,
            'dimensions:max_width='.StoredImage::MAX_DIMENSION.',max_height='.StoredImage::MAX_DIMENSION,
        ];

        $rules = [];

        foreach (self::IMAGE_SLOTS as [$uploadProperty]) {
            $rules[$uploadProperty] = $rule;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $messages = [];

        foreach (self::IMAGE_SLOTS as [$uploadProperty]) {
            $messages[$uploadProperty.'.image'] = (string) __('opes.school_identity.upload_not_an_image');
            $messages[$uploadProperty.'.mimes'] = (string) __('opes.school_identity.upload_wrong_type', [
                'types' => strtoupper(implode(', ', StoredImage::ALLOWED_EXTENSIONS)),
            ]);
            $messages[$uploadProperty.'.max'] = (string) __('opes.school_identity.upload_too_large', [
                'kb' => StoredImage::MAX_KILOBYTES,
            ]);
            $messages[$uploadProperty.'.dimensions'] = (string) __('opes.school_identity.upload_too_big', [
                'px' => StoredImage::MAX_DIMENSION,
            ]);
        }

        return $messages;
    }

    /**
     * Clear a slot. The file itself is deleted at SAVE, not here - an
     * operator who clicks Remove and then Cancel must get their image back,
     * and a delete on click cannot be undone.
     */
    public function removeImage(string $slot): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        if (! array_key_exists($slot, self::IMAGE_SLOTS)) {
            return;
        }

        [$uploadProperty, $pathProperty] = self::IMAGE_SLOTS[$slot];

        $this->{$uploadProperty} = null;
        $this->{$pathProperty} = '';
    }

    /**
     * Move every pending upload into permanent, content-hashed storage and
     * point the path properties at it. Returns the paths that were REPLACED,
     * so save() can delete them only after the database write succeeded - a
     * delete before a failed save would lose the image and keep the old row.
     *
     * @return array<string, string> previous path, keyed by slot
     */
    private function storeUploads(): array
    {
        $replaced = [];

        foreach (self::IMAGE_SLOTS as $slot => [$uploadProperty, $pathProperty]) {
            $upload = $this->{$uploadProperty};

            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            $previous = (string) $this->{$pathProperty};

            $this->{$pathProperty} = StoredImage::putContents(
                $slot,
                (string) file_get_contents($upload->getRealPath()),
                strtolower($upload->getClientOriginalExtension()),
            );

            if ($previous !== '') {
                $replaced[$slot] = $previous;
            }

            // Release the temporary file immediately: a TemporaryUploadedFile
            // held on a public property is re-serialised into every
            // subsequent request's payload.
            $upload->delete();
            $this->{$uploadProperty} = null;
        }

        return $replaced;
    }

    /**
     * The image paths a slot held before this save that are no longer held by
     * ANY slot after it - the set safe to delete.
     *
     * "No longer held by any slot" rather than "was replaced in this slot":
     * a school that swaps its crest and its logo for each other must not have
     * either file deleted.
     *
     * @param  array<string, string>  $replaced
     * @return list<string>
     */
    private function orphanedPaths(array $replaced): array
    {
        $kept = [];

        foreach (self::IMAGE_SLOTS as [, $pathProperty]) {
            $kept[] = (string) $this->{$pathProperty};
        }

        return array_values(array_filter(
            array_unique(array_values($replaced)),
            static fn (string $path): bool => ! in_array($path, $kept, true),
        ));
    }
```

- [ ] **Step 5: Wire it into `save()`**

Replace the opening of `save()` in the same class:

```php
    public function save(SaveDocumentProfile $save): void
    {
        $this->resetErrorBag();

        // Validate the uploads BEFORE anything is written to disk: a refused
        // file must leave no trace at all.
        $this->validate();

        $previousPaths = [];

        foreach (self::IMAGE_SLOTS as $slot => [, $pathProperty]) {
            $previousPaths[$slot] = (string) $this->{$pathProperty};
        }

        $replaced = $this->storeUploads();

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();
```

and, immediately before the closing `$this->dispatch('settings-saved');`, add:

```php
        // Only after the row is committed: deleting first and then failing
        // the write would lose the image AND keep the old path.
        foreach ($this->orphanedPaths($replaced) as $orphan) {
            StoredImage::forget($orphan, null);
        }

        // A slot the operator CLEARED (removeImage) also orphans its file.
        foreach (self::IMAGE_SLOTS as $slot => [, $pathProperty]) {
            if ($previousPaths[$slot] !== '' && (string) $this->{$pathProperty} === '') {
                StoredImage::forget($previousPaths[$slot], null);
            }
        }
```

In the `catch (ValidationException $e)` block of `save()`, add a `return;` path that also removes any file just written — replace the block's body's `return;` with:

```php
            // Roll the disk back to match the refused write.
            foreach ($this->orphanedPaths($previousPaths) as $written) {
                StoredImage::forget($written, null);
            }

            return;
```

- [ ] **Step 6: Add the upload UI to the marks fieldset**

In `resources/views/livewire/schoolprofile/document-profile.blade.php`, replace the entire `<x-settings-fieldset :heading="__('opes.school_identity.marks')" …>` block with:

```blade
        <x-settings-fieldset :heading="__('opes.school_identity.marks')"
                             :hint="__('opes.school_identity.marks_hint')">
            @foreach ([
                ['crest', 'crestUpload', 'crestPath', 'crest_path'],
                ['logo', 'logoUpload', 'logoPath', 'logo_path'],
                ['principal_signature', 'principalSignatureUpload', 'principalSignaturePath', 'principal_signature_path'],
                ['registrar_signature', 'registrarSignatureUpload', 'registrarSignaturePath', 'registrar_signature_path'],
                ['school_stamp', 'schoolStampUpload', 'schoolStampPath', 'school_stamp_path'],
            ] as [$slot, $uploadModel, $pathModel, $key])
                <x-settings-field :label="__('opes.school_identity.'.$key)"
                                  :hint="__('opes.school_identity.hint_'.$key)"
                                  :error="$errors->first($uploadModel) ?: $errors->first($key)">
                    <div class="flex items-start gap-3">
                        {{-- The preview. `wire:key` on the wrapper so Livewire
                             replaces the thumbnail when the path changes
                             instead of reusing a stale <img>. --}}
                        <span wire:key="preview-{{ $slot }}-{{ $$pathModel }}"
                              class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border-primary bg-sand">
                            @if ($$uploadModel !== null)
                                <img src="{{ $$uploadModel->temporaryUrl() }}" alt="" class="max-h-full max-w-full object-contain">
                            @elseif ($$pathModel !== '')
                                <img src="{{ Storage::disk('public')->url($$pathModel) }}" alt="" class="max-h-full max-w-full object-contain">
                            @else
                                <span class="text-xs text-charcoal/40">{{ __('opes.school_identity.no_image') }}</span>
                            @endif
                        </span>

                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="{{ $uploadModel }}"
                                   accept="image/png,image/jpeg,image/webp"
                                   class="block w-full text-sm text-charcoal file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90">

                            <div wire:loading wire:target="{{ $uploadModel }}" class="mt-1 text-xs text-text-secondary">
                                {{ __('opes.school_identity.uploading') }}
                            </div>

                            @if ($$pathModel !== '')
                                <button type="button" wire:click="removeImage('{{ $slot }}')"
                                        class="mt-1 text-xs font-medium text-danger hover:underline">
                                    {{ __('opes.school_identity.remove_image') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>
```

Add `use Illuminate\Support\Facades\Storage;` to a `@php` block at the top of that blade file, or replace `Storage::disk('public')->url($$pathModel)` with `\Illuminate\Support\Facades\Storage::disk('public')->url($$pathModel)` — the second is one fewer moving part.

- [ ] **Step 7: Add the upload lang keys**

`lang/en/opes.php` under `'school_identity'`: `'no_image' => 'None'`, `'uploading' => 'Uploading…'`, `'remove_image' => 'Remove'`, `'upload_not_an_image' => 'That file is not an image.'`, `'upload_wrong_type' => 'Use a :types image. SVG is not accepted.'`, `'upload_too_large' => 'That image is larger than :kb KB.'`, `'upload_too_big' => 'That image is wider or taller than :px pixels.'`.

`lang/fr/opes.php`: `'no_image' => 'Aucune'`, `'uploading' => 'Téléversement…'`, `'remove_image' => 'Retirer'`, `'upload_not_an_image' => "Ce fichier n'est pas une image."`, `'upload_wrong_type' => 'Utilisez une image :types. Le format SVG est refusé.'`, `'upload_too_large' => "Cette image dépasse :kb Ko."`, `'upload_too_big' => 'Cette image dépasse :px pixels de large ou de haut.'`.

- [ ] **Step 8: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/DocumentImageUploadTest.php tests/Feature/SchoolProfile/DocumentProfileScreenTest.php tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 9: Confirm the public symlink is live**

Run: `$PHP artisan storage:link`
Expected: `The [public/storage] link already exists.` — the previews are `<img src>` off that symlink, and a missing link renders five broken thumbnails.

- [ ] **Step 10: Build and commit**

```bash
npm run build
git add app/Modules/SchoolProfile/Livewire/DocumentProfile.php resources/views/livewire/schoolprofile/document-profile.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/DocumentImageUploadTest.php
git commit -m "feat(settings): upload the crest, logo, signatures and stamp"
```

---

### Task 17: Upload the app logo and favicon

**Files:**
- Modify: `app/Modules/SchoolProfile/Livewire/Branding.php`
- Modify: `resources/views/livewire/schoolprofile/branding.blade.php`
- Test: `tests/Feature/SchoolProfile/BrandingUploadTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('stores an uploaded app logo and persists its path in settings', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->set('appLogoUpload', UploadedFile::fake()->image('logo.png', 300, 100))
        ->call('save')
        ->assertHasNoErrors();

    $path = json_decode((string) DB::table('settings')->where('key', 'branding.app_logo_path')->value('value'), true);

    expect($path)->toStartWith('branding/app-logo-')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('stores an uploaded favicon', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->set('faviconUpload', UploadedFile::fake()->image('icon.png', 64, 64))
        ->call('save')
        ->assertHasNoErrors();

    $path = json_decode((string) DB::table('settings')->where('key', 'branding.favicon_path')->value('value'), true);

    expect($path)->toStartWith('branding/favicon-');
});

it('refuses an SVG logo', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->set('appLogoUpload', UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'))
        ->call('save')
        ->assertHasErrors('appLogoUpload');
});

it('clears the logo when the operator removes it', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(Branding::class)
        ->set('appLogoUpload', UploadedFile::fake()->image('logo.png', 300, 100))
        ->call('save');

    Livewire::test(Branding::class)
        ->call('removeAppLogo')
        ->call('save');

    expect(json_decode((string) DB::table('settings')->where('key', 'branding.app_logo_path')->value('value'), true))
        ->toBe('');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/BrandingUploadTest.php`
Expected: FAIL — `Unable to set component property [appLogoUpload]`.

- [ ] **Step 3: Add the uploads to `Branding.php`**

Add the imports `use App\Support\Storage\StoredImage;`, `use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;` and `use Livewire\WithFileUploads;`, add `use WithFileUploads;` to the class body, then add:

```php
    public string $appLogoPath = '';

    public string $faviconPath = '';

    public ?TemporaryUploadedFile $appLogoUpload = null;

    public ?TemporaryUploadedFile $faviconUpload = null;

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
```

In `mount()`, after the palette hydration, add:

```php
        $this->appLogoPath = (string) $readSetting->handle('branding.app_logo_path', '');
        $this->faviconPath = (string) $readSetting->handle('branding.favicon_path', '');
```

In `save()`, immediately after `$this->resetErrorBag();`, add `$this->validate();`, and inside the existing `DB::transaction(...)` closure — after the two palette writes — add the image handling. Replace that closure with:

```php
            DB::transaction(function () use ($writeSetting, $tokens, $actor): void {
                $writeSetting->handle(self::PALETTE_KEY, $tokens->all(), $actor);
                $writeSetting->handle(self::SETTING_KEY, $tokens->get('primary'), $actor);

                foreach ([
                    ['app-logo', 'appLogoUpload', 'appLogoPath', 'branding.app_logo_path'],
                    ['favicon', 'faviconUpload', 'faviconPath', 'branding.favicon_path'],
                ] as [$slot, $uploadProperty, $pathProperty, $settingKey]) {
                    $previous = (string) $this->{$pathProperty};
                    $upload = $this->{$uploadProperty};

                    if ($upload instanceof TemporaryUploadedFile) {
                        $this->{$pathProperty} = StoredImage::putContents(
                            $slot,
                            (string) file_get_contents($upload->getRealPath()),
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
```

- [ ] **Step 4: Add the upload fieldset to the branding blade**

Insert before the closing `</x-settings-form>` in `resources/views/livewire/schoolprofile/branding.blade.php`:

```blade
        <x-settings-fieldset :heading="__('opes.branding.marks')"
                             :hint="__('opes.branding.marks_hint')">
            @foreach ([
                ['app_logo', 'appLogoUpload', 'appLogoPath', 'removeAppLogo'],
                ['favicon', 'faviconUpload', 'faviconPath', 'removeFavicon'],
            ] as [$key, $uploadModel, $pathModel, $removeMethod])
                <x-settings-field :label="__('opes.branding.'.$key)"
                                  :hint="__('opes.branding.hint_'.$key)"
                                  :error="$errors->first($uploadModel)">
                    <div class="flex items-start gap-3">
                        <span wire:key="brand-preview-{{ $key }}-{{ $$pathModel }}"
                              class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border-primary bg-sand">
                            @if ($$uploadModel !== null)
                                <img src="{{ $$uploadModel->temporaryUrl() }}" alt="" class="max-h-full max-w-full object-contain">
                            @elseif ($$pathModel !== '')
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($$pathModel) }}"
                                     alt="" class="max-h-full max-w-full object-contain">
                            @else
                                <span class="text-xs text-charcoal/40">{{ __('opes.school_identity.no_image') }}</span>
                            @endif
                        </span>

                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="{{ $uploadModel }}"
                                   accept="image/png,image/jpeg,image/webp"
                                   class="block w-full text-sm text-charcoal file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90">

                            @if ($$pathModel !== '')
                                <button type="button" wire:click="{{ $removeMethod }}"
                                        class="mt-1 text-xs font-medium text-danger hover:underline">
                                    {{ __('opes.school_identity.remove_image') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>
```

- [ ] **Step 5: Add the lang keys**

`lang/en/opes.php` under `'branding'`: `'marks' => 'Logo & favicon'`, `'marks_hint' => 'Shown in the app shell and the browser tab. These are separate from the document crest and logo on School Identity.'`, `'app_logo' => 'App logo'`, `'favicon' => 'Favicon'`, `'hint_app_logo' => 'Replaces the built-in OPES mark at the top of the sidebar.'`, `'hint_favicon' => 'The browser-tab icon. Square, 512px or smaller.'`.

`lang/fr/opes.php`: `'marks' => 'Logo et favicon'`, `'marks_hint' => "Affichés dans l'application et l'onglet du navigateur. Distincts des armoiries et du logo des documents."`, `'app_logo' => "Logo de l'application"`, `'favicon' => 'Favicon'`, `'hint_app_logo' => "Remplace la marque OPES en haut de la barre latérale."`, `'hint_favicon' => "L'icône de l'onglet. Carrée, 512 px maximum."`.

- [ ] **Step 6: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/BrandingUploadTest.php tests/Feature/SchoolProfile/BrandingScreenTest.php tests/Feature/Ui/ShellBrandingTest.php`
Expected: PASS.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/SchoolProfile/Livewire/Branding.php resources/views/livewire/schoolprofile/branding.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/BrandingUploadTest.php
git commit -m "feat(branding): upload the app logo and favicon"
```

---

### Task 18: Render the logo, signatures and stamp into documents

**Files:**
- Modify: `app/Modules/Reporting/Actions/RenderDocument.php` (`renderHtml()`)
- Modify: `resources/views/documents/blocks/school_header.blade.php`
- Modify: `resources/views/documents/blocks/signature_block.blade.php`
- Modify: `resources/views/documents/layout.blade.php` (signature styles)
- Test: `tests/Feature/Reporting/DocumentBrandingRenderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

/** Every branding image set, on a fake public disk. */
function brandingProfileWithImages(): array
{
    $paths = [
        'crest_path' => StoredImage::putContents('crest', 'CRESTBYTES', 'png'),
        'logo_path' => StoredImage::putContents('logo', 'LOGOBYTES', 'png'),
        'principal_signature_path' => StoredImage::putContents('principal_signature', 'PSIGBYTES', 'png'),
        'registrar_signature_path' => StoredImage::putContents('registrar_signature', 'RSIGBYTES', 'png'),
        'school_stamp_path' => StoredImage::putContents('school_stamp', 'STAMPBYTES', 'png'),
    ];

    p13coreDocumentProfile($paths);

    return $paths;
}

it('embeds the crest and the logo as data URIs, never as a storage URL', function (): void {
    p13coreUserAs(Role::Bursar);
    brandingProfileWithImages();

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->toContain('data:image/png;base64,'.base64_encode('CRESTBYTES'))
        ->toContain('data:image/png;base64,'.base64_encode('LOGOBYTES'))
        // dompdf has remote assets disabled: a /storage URL renders NOTHING.
        ->not->toContain('src="/storage/')
        ->not->toContain('src="http');
});

it('prints the signature images above the signature lines', function (): void {
    p13coreUserAs(Role::Bursar);
    brandingProfileWithImages();

    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-live',
        'signature_roles' => ['principal', 'registrar'],
    ]);

    $doc = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->toContain(base64_encode('PSIGBYTES'))
        ->toContain(base64_encode('RSIGBYTES'))
        ->toContain(base64_encode('STAMPBYTES'));
});

it('prints no image element at all when a slot is unset', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile();

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create([
            'blade_view' => 'p13core-live',
            'signature_roles' => ['principal'],
        ])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    // A missing crest must leave NO <img> - not an empty box, not a broken
    // image. A letterhead with a hole in it reads as a broken install.
    expect($doc->html)->not->toContain('<img');
});

it('prints only the signature image whose role the template actually carries', function (): void {
    p13coreUserAs(Role::Bursar);
    brandingProfileWithImages();

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create([
            'blade_view' => 'p13core-live',
            'signature_roles' => ['registrar'],
        ])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->toContain(base64_encode('RSIGBYTES'))
        // The principal did not sign this one; printing his signature would
        // be a forgery, not a convenience.
        ->not->toContain(base64_encode('PSIGBYTES'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/DocumentBrandingRenderTest.php`
Expected: FAIL — the first test fails because `school_header.blade.php` emits the raw path, not a data URI.

- [ ] **Step 3: Resolve the branding paths at render time**

In `app/Modules/Reporting/Actions/RenderDocument.php`, add the import `use App\Modules\Reporting\Domain\EmbeddedImage;` and, in `renderHtml()`, insert immediately before the `return view(...)`:

```php
        // The branding block carries relative PATHS - that is what was frozen
        // into the chrome at issue, and it must stay frozen. The `*_uri` keys
        // added here are derived at RENDER time and never stored, because
        // DompdfRenderer sets setIsRemoteEnabled(false) and would render
        // nothing at all for a /storage URL.
        //
        // This is deterministic: the same path resolves to the same bytes
        // forever, because StoredImage writes every image under a
        // CONTENT-HASHED filename - replacing an image produces a NEW path,
        // so a frozen path can never silently change what it points at.
        if (is_array($chrome['branding'] ?? null)) {
            /** @var array<string, mixed> $branding */
            $branding = $chrome['branding'];
            $chrome['branding'] = EmbeddedImage::resolveBranding($branding);
        }
```

- [ ] **Step 4: Render the crest and logo in the header**

In `resources/views/documents/blocks/school_header.blade.php`, replace lines 7–10 (the opening `<div class="doc-block doc-center">` and the crest `@if`) with:

```blade
<div class="doc-block doc-center">
    {{-- Crest centred above the name, logo floated right beside it. Both
         arrive as base64 data URIs (EmbeddedImage), because dompdf has
         remote assets disabled and would render NOTHING for a /storage URL.
         An unset image prints no <img> at all - a letterhead with a hole in
         it reads as a broken install, not as a school without a crest. --}}
    @if (!empty($school['branding']['logo_uri']))
        <img src="{{ $school['branding']['logo_uri'] }}" alt=""
             style="float: right; height: 40pt; margin-left: 8pt;">
    @endif

    @if (!empty($school['branding']['crest_uri']))
        <img src="{{ $school['branding']['crest_uri'] }}" alt="" style="height: 52pt;"><br>
    @endif
```

- [ ] **Step 5: Render the signatures and stamp**

Replace the entire contents of `resources/views/documents/blocks/signature_block.blade.php` with:

```blade
{{-- 10-documents 4.7 signature_block: the template's ORDERED signature roles,
     each with a bilingual label, ruled line and date. The roles were
     validated against the 2.3 allow-list when the template was saved
     (DocumentTemplate::booted) - by the time this block renders, a forbidden
     role cannot exist in $document['signature_roles'].

     SIGNATURE IMAGES. A scanned signature is printed ABOVE the ruled line,
     for the roles the template actually carries and ONLY those: printing the
     principal's signature on a document he is not a signatory to is a
     forgery, not a convenience. A role with no stored image still gets its
     line and label, so the document remains signable by hand.

     The images arrive as base64 data URIs; dompdf cannot fetch a URL. --}}
@if (($document['signature_roles'] ?? []) !== [])
    @php
        // role => the branding key holding that role's scanned signature.
        // Roles absent from this map sign by hand; that is the default and
        // needs no entry.
        $signatureUris = [
            'principal' => $school['branding']['principal_signature_uri'] ?? null,
            'registrar' => $school['branding']['registrar_signature_uri'] ?? null,
        ];

        $stampUri = $school['branding']['school_stamp_uri'] ?? null;
    @endphp

    <table class="doc-block doc-signatures">
        <tr>
            @foreach ($document['signature_roles'] as $role)
                <td>
                    @if (!empty($signatureUris[$role]))
                        {{-- Fixed height, auto width, and NO bottom margin:
                             the signature has to sit ON the rule, not float
                             above it with a gap that reads as two separate
                             marks. --}}
                        <img src="{{ $signatureUris[$role] }}" alt="" class="doc-signature-image">
                    @endif
                    <div class="doc-signature-line">
                        <strong>{{ __('documents.signature_roles.'.$role, [], $document['language']) }}</strong><br>
                        <span class="doc-muted">{{ __('documents.signature.date_line', [], $document['language']) }}</span>
                    </div>
                </td>
            @endforeach

            @if (!empty($stampUri))
                {{-- The school stamp is not a signature and does not get a
                     ruled line: it sits beside the signatories, as it does on
                     paper. --}}
                <td class="doc-stamp-cell">
                    <img src="{{ $stampUri }}" alt="" class="doc-stamp-image">
                </td>
            @endif
        </tr>
    </table>
@endif
```

- [ ] **Step 6: Add the signature/stamp styles**

In `resources/views/documents/layout.blade.php`, after the existing `.doc-signature-line` rule, add:

```css
        /* A scanned signature sits ON the rule below it, so its own bottom
           margin is negative: the rule's 26pt top margin would otherwise
           push the two apart into what reads as two unrelated marks. Height
           is fixed and width auto - a scanned signature is any aspect ratio
           at all, and a fixed box squashes half of them. */
        .doc-signature-image { height: 34pt; width: auto; max-width: 150pt; display: block; margin: 0 auto -26pt auto; }
        .doc-stamp-cell { vertical-align: middle; width: 90pt; }
        .doc-stamp-image { height: 70pt; width: auto; max-width: 90pt; opacity: 0.9; }
```

- [ ] **Step 7: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/DocumentBrandingRenderTest.php tests/Feature/Reporting/LetterheadTest.php`
Expected: PASS. `LetterheadTest` must still pass unchanged — it asserts the block prints nothing it was not given, and the new `@if`s preserve that.

- [ ] **Step 8: Build and commit**

```bash
npm run build
git add app/Modules/Reporting/Actions/RenderDocument.php resources/views/documents/blocks/school_header.blade.php resources/views/documents/blocks/signature_block.blade.php resources/views/documents/layout.blade.php tests/Feature/Reporting/DocumentBrandingRenderTest.php
git commit -m "feat(documents): render the logo, signatures and stamp as embedded images"
```

---

### Task 19: Prove reprint reproducibility survives an image change

**Files:**
- Test: `tests/Feature/Reporting/BrandingReproducibilityTest.php`

This task adds **no production code**. It exists because the design decision in Task 14 (content-hashed filenames) is the only thing standing between this feature and a permanent, silent, unrecoverable failure of every certificate a school ever issued. An untested invariant of that weight is not an invariant.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\DocumentReproducibilityViolation;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

/**
 * @return array{template: DocumentTemplate, snapshot_id: int}
 */
function reproSnapshotTemplate(): array
{
    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);

    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
        'signature_roles' => ['principal'],
    ]);

    return ['template' => $template, 'snapshot_id' => $snapshot['snapshot_id']];
}

it('reprints byte-identically after the school uploads a NEW signature at a new path', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $signature = StoredImage::putContents('principal_signature', 'ORIGINAL SIGNATURE', 'png');
    p13coreDocumentProfile(['principal_signature_path' => $signature]);

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    $original = app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    // The school replaces the signature. Content-hashing means this lands at
    // a DIFFERENT path; the profile row moves, the frozen chrome does not.
    $replacement = StoredImage::putContents('principal_signature', 'REPLACEMENT SIGNATURE', 'png');

    expect($replacement)->not->toBe($signature);

    DB::table('school_document_profiles')->where('id', 1)
        ->update(['principal_signature_path' => $replacement]);

    // The reprint re-renders from the FROZEN chrome, which still names the
    // original path, whose bytes are unchanged. It must reproduce.
    $reprint = app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    expect($reprint->contentHash)->toBe($original->contentHash)
        ->and($reprint->isDuplicate)->toBeTrue();
});

it('carries the frozen image, not the current one, onto a reprint', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $signature = StoredImage::putContents('principal_signature', 'ORIGINAL SIGNATURE', 'png');
    p13coreDocumentProfile(['principal_signature_path' => $signature]);

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    DB::table('school_document_profiles')->where('id', 1)->update([
        'principal_signature_path' => StoredImage::putContents('principal_signature', 'REPLACEMENT', 'png'),
    ]);

    $reprint = app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    // A reprint carrying TODAY's signature on YESTERDAY's certificate is a
    // forgery, not a reprint.
    expect($reprint->html)
        ->toContain(base64_encode('ORIGINAL SIGNATURE'))
        ->not->toContain(base64_encode('REPLACEMENT'));
});

it('refuses honestly, rather than forging, when the frozen image is gone', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $signature = StoredImage::putContents('principal_signature', 'ORIGINAL SIGNATURE', 'png');
    p13coreDocumentProfile(['principal_signature_path' => $signature]);

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    // The file the frozen chrome names is deleted (the delete-on-replace path
    // in the settings screen). The reprint can no longer reproduce the issued
    // bytes - and says so, loudly, instead of quietly printing a certificate
    // with the signature missing.
    Storage::disk('public')->delete($signature);

    app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );
})->throws(DocumentReproducibilityViolation::class);

it('leaves documents issued BEFORE any image existed reproducible', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile();

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    $original = app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    // The school uploads its first-ever crest AFTER issuing. The already
    // issued document must not acquire it retroactively.
    DB::table('school_document_profiles')->where('id', 1)->update([
        'crest_path' => StoredImage::putContents('crest', 'BRAND NEW CREST', 'png'),
    ]);

    $reprint = app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );

    expect($reprint->contentHash)->toBe($original->contentHash)
        ->and($reprint->html)->not->toContain(base64_encode('BRAND NEW CREST'));
});
```

- [ ] **Step 2: Run it**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/BrandingReproducibilityTest.php`
Expected: PASS, 4 tests.

**If test 3 does not throw**, the render is silently succeeding with a missing image — check that `EmbeddedImage::dataUri()` returns `null` for the deleted path and that the blade's `@if` therefore omits the `<img>`, which changes the bytes and must trip the hash comparison. Do not "fix" this by making a missing image reproduce the original bytes; that is the forgery the test exists to prevent.

- [ ] **Step 3: Run the whole reporting suite for regressions**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting`
Expected: PASS. `SnapshotByteIdenticalTest`, `DocumentPayloadSnapshotTest` and `ReceiptRenderTest` are the ones that would catch a regression here.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Reporting/BrandingReproducibilityTest.php
git commit -m "test(documents): pin reprint reproducibility across a branding image change"
```

---

# Phase 2 — The configurable school watermark

**What exists:** one watermark, derived and status-only — `duplicata` (reprint), `void` (revoked), `specimen` (provisional fiscal identity), precedence `void > duplicata > specimen`, **and only one ever draws** (`RenderDocument.php:314-316` is a single ternary; `watermark.blade.php` renders one `<div>`).

**Why a second layer is required, not optional:** if a school's own watermark ("HERITAGE BILINGUAL COLLEGE", or a faint crest) were folded into the same slot, then the first reprint of any document would replace it with DUPLICATA and the school's mark would vanish exactly when the document is most likely to be scrutinised. The two say different things — one is *whose document this is*, the other is *what state this copy is in* — and both must be able to appear.

**Both stay out of the hashed artefact**, exactly as the status watermark does today: `issueOriginal()` hashes the clean render, and the overlay is a separate render. This is what lets a school turn its watermark on without retroactively breaking every document it has already issued.

### Task 20: The watermark columns

**Files:**
- Create: `database/migrations/2026_08_15_440002_add_school_watermark_to_document_profile.php`
- Test: `tests/Feature/SchoolProfile/SchoolWatermarkColumnsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the four school-watermark columns', function (): void {
    foreach ([
        'watermark_enabled', 'watermark_text', 'watermark_image_path', 'watermark_opacity',
    ] as $column) {
        expect(Schema::hasColumn('school_document_profiles', $column))
            ->toBeTrue("column [{$column}] is missing");
    }
});

it('defaults to disabled, so no existing install changes what it prints', function (): void {
    // Enabling this by default would silently restyle every document a live
    // school prints tomorrow morning.
    expect(Schema::hasColumn('school_document_profiles', 'watermark_enabled'))->toBeTrue();

    \Illuminate\Support\Facades\DB::table('school_document_profiles')->insert([
        'id' => 1, 'state_header_enabled' => false, 'bilingual_documents' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = \Illuminate\Support\Facades\DB::table('school_document_profiles')->where('id', 1)->first();

    expect((bool) $row->watermark_enabled)->toBeFalse()
        ->and($row->watermark_text)->toBeNull()
        ->and((int) $row->watermark_opacity)->toBe(8);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SchoolWatermarkColumnsTest.php`
Expected: FAIL — `column [watermark_enabled] is missing`.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SCHOOL's own watermark - additive to, never replacing, the derived
 * status watermarks (DUPLICATA / ANNULÉ / SPÉCIMEN).
 *
 * Why a second, independent layer rather than a fourth value in the existing
 * one: the status watermark says what STATE this copy is in, the school
 * watermark says WHOSE document it is. Folding them together means the first
 * reprint of any document silently drops the school's mark - exactly when the
 * document is most likely to be scrutinised.
 *
 * Both stay OUT of the hashed artefact, like the status watermark already is
 * (RenderDocument::issueOriginal hashes the clean render and applies the
 * overlay to a separate one). That is what lets a school switch this on
 * without retroactively breaking the reproducibility of every document it
 * has already issued.
 *
 * Defaults are OFF and empty on purpose: a live school must print exactly
 * what it printed yesterday until someone deliberately changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_document_profiles', function (Blueprint $table): void {
            $table->boolean('watermark_enabled')->default(false)->after('school_stamp_path');
            $table->string('watermark_text', 60)->nullable()->after('watermark_enabled');
            $table->string('watermark_image_path', 255)->nullable()->after('watermark_text');
            // Percent, 1-30. Stored as an integer because it is a setting a
            // human types, and 0.08 in a text box is how a school ends up
            // with an invisible or an opaque watermark. Above 30 the mark
            // competes with the text it sits behind.
            $table->unsignedTinyInteger('watermark_opacity')->default(8)->after('watermark_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('school_document_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'watermark_enabled', 'watermark_text', 'watermark_image_path', 'watermark_opacity',
            ]);
        });
    }
};
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SchoolWatermarkColumnsTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_15_440002_add_school_watermark_to_document_profile.php tests/Feature/SchoolProfile/SchoolWatermarkColumnsTest.php
git commit -m "feat(documents): add the school watermark columns"
```

---

### Task 21: Render the school watermark as a second layer

**Files:**
- Modify: `app/Modules/Reporting/Actions/RenderDocument.php` (`captureSchoolChrome()`)
- Create: `resources/views/documents/blocks/school_watermark.blade.php`
- Modify: `resources/views/documents/layout.blade.php`
- Test: `tests/Feature/Reporting/SchoolWatermarkTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

function watermarkRender(): App\Modules\Reporting\Domain\RenderedDocument
{
    return app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );
}

it('prints nothing when the school watermark is off', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile(['watermark_enabled' => false, 'watermark_text' => 'HERITAGE']);

    expect(watermarkRender()->html)->not->toContain('doc-school-watermark');
});

it('prints the school text watermark when it is on', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'watermark_enabled' => true,
        'watermark_text' => 'HERITAGE BILINGUAL COLLEGE',
        'watermark_opacity' => 10,
    ]);

    expect(watermarkRender()->html)
        ->toContain('doc-school-watermark')
        ->toContain('HERITAGE BILINGUAL COLLEGE')
        ->toContain('rgba(120, 120, 120, 0.1)');
});

it('prints an image watermark as a data URI', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'watermark_enabled' => true,
        'watermark_image_path' => StoredImage::putContents('watermark', 'MARKBYTES', 'png'),
    ]);

    expect(watermarkRender()->html)
        ->toContain('data:image/png;base64,'.base64_encode('MARKBYTES'))
        ->not->toContain('src="/storage/');
});

it('draws the school watermark AND the specimen status watermark together', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile(['watermark_enabled' => true, 'watermark_text' => 'HERITAGE']);
    // No fiscal_identities row at all: provisional, so SPECIMEN applies.

    $html = watermarkRender()->html;

    expect($html)
        ->toContain('doc-school-watermark')
        ->toContain('HERITAGE')
        ->toContain('doc-watermark')
        ->toContain('SPÉCIMEN');
});

it('keeps drawing the school watermark on a DUPLICATA reprint', function (): void {
    // The whole reason this is a second layer: with one slot, the first
    // reprint of any document would silently drop the school's own mark.
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile(['watermark_enabled' => true, 'watermark_text' => 'HERITAGE']);

    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    app(RenderDocument::class)->handle(...$args);
    $reprint = app(RenderDocument::class)->handle(...$args);

    expect($reprint->isDuplicate)->toBeTrue()
        ->and($reprint->html)->toContain('DUPLICATA')
        ->and($reprint->html)->toContain('HERITAGE');
});

it('clamps a nonsense opacity rather than printing an opaque or invisible mark', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'watermark_enabled' => true, 'watermark_text' => 'HERITAGE', 'watermark_opacity' => 200,
    ]);

    expect(watermarkRender()->html)->toContain('rgba(120, 120, 120, 0.3)');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/SchoolWatermarkTest.php`
Expected: FAIL — `doc-school-watermark` appears nowhere.

- [ ] **Step 3: Capture the watermark into the chrome**

In `RenderDocument::captureSchoolChrome()`, add a `watermark` key to the returned array, immediately after `'branding' => …`:

```php
            // The SCHOOL's watermark, frozen into the chrome like everything
            // else here - so a school that switches its watermark on does not
            // retroactively change what an already-issued document says it
            // was printed with. It is still applied as an OUTPUT overlay
            // (never hashed), exactly like the status watermark.
            'watermark' => $profile === null || ! (bool) ($profile->watermark_enabled ?? false) ? null : [
                'text' => $profile->watermark_text,
                'image_path' => $profile->watermark_image_path,
                // 1-30 percent. Clamped HERE, not in the blade: a value that
                // reached the database around the settings screen (an import,
                // a hand-run UPDATE) must not be able to print an opaque
                // black slab across a certificate.
                'opacity' => max(1, min(30, (int) ($profile->watermark_opacity ?? 8))),
            ],
```

Also add `watermark_image_path` to the `branding` array in that same method, so `EmbeddedImage::resolveBranding()` picks it up:

```php
                'school_stamp_path' => $profile->school_stamp_path,
                'watermark_image_path' => $profile->watermark_image_path,
```

- [ ] **Step 4: Write the school watermark block**

Create `resources/views/documents/blocks/school_watermark.blade.php`:

```blade
{{-- The SCHOOL's own watermark - a SECOND, independent layer beneath the
     status watermark (DUPLICATA / ANNULÉ / SPÉCIMEN), not an alternative to
     it.

     One slot would mean the first reprint of any document silently replaces
     the school's mark with DUPLICATA - dropping the institutional mark at
     exactly the moment the copy is most likely to be scrutinised. The two
     answer different questions ("whose document is this" vs "what state is
     this copy in") and both must be able to appear.

     Like the status watermark, this is an OUTPUT overlay and is never part
     of the hashed artefact, so switching it on does not retroactively break
     any already-issued document's reproducibility.

     Image beats text when both are set: a school that uploaded a mark meant
     to use it, and printing both produces an unreadable overlap. --}}
@php
    $schoolWatermark = $school['watermark'] ?? null;
    $watermarkImage = $school['branding']['watermark_image_uri'] ?? null;
    // Already clamped to 1-30 in RenderDocument::captureSchoolChrome; divided
    // here only because CSS wants a fraction.
    $watermarkAlpha = ($schoolWatermark['opacity'] ?? 8) / 100;
@endphp

@if ($schoolWatermark !== null && (!empty($watermarkImage) || !empty($schoolWatermark['text'])))
    <div class="doc-school-watermark">
        @if (!empty($watermarkImage))
            <img src="{{ $watermarkImage }}" alt="" style="opacity: {{ $watermarkAlpha }};">
        @else
            <span style="color: rgba(120, 120, 120, {{ $watermarkAlpha }});">{{ $schoolWatermark['text'] }}</span>
        @endif
    </div>
@endif
```

- [ ] **Step 5: Include it and style it**

In `resources/views/documents/layout.blade.php`, inside `<div class="doc-sheet">`, add the include **before** the status watermark so the school mark sits underneath it:

```blade
    @include('documents.blocks.school_watermark')
    @include('documents.blocks.watermark')
```

Add the styles after the existing `.doc-watermark` rule:

```css
        /* The school's own mark. Deliberately OFFSET from .doc-watermark's
           38% and rotated the other way: when both draw, two marks at the
           same angle in the same place read as a printing fault, while two
           at different angles read as two marks. z-index below the status
           watermark, which is the more urgent thing to say. */
        .doc-school-watermark {
            position: fixed;
            top: 55%;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 40pt;
            font-weight: bold;
            letter-spacing: 4pt;
            transform: rotate(-12deg);
            z-index: 0;
        }
        .doc-school-watermark img { max-width: 55%; max-height: 260pt; }
```

and, inside the existing `@media screen { … }` block beside `.doc-watermark { position: absolute; }`:

```css
            .doc-school-watermark { position: absolute; }
```

- [ ] **Step 6: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/SchoolWatermarkTest.php tests/Feature/Reporting/SpecimenWatermarkTest.php`
Expected: PASS.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/Reporting/Actions/RenderDocument.php resources/views/documents/blocks/school_watermark.blade.php resources/views/documents/layout.blade.php tests/Feature/Reporting/SchoolWatermarkTest.php
git commit -m "feat(documents): add the school watermark as a second, independent layer"
```

---

### Task 22: Configure the school watermark on the settings screen

**Files:**
- Modify: `app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php`
- Modify: `app/Modules/SchoolProfile/Livewire/DocumentProfile.php`
- Modify: `resources/views/livewire/schoolprofile/document-profile.blade.php`
- Test: `tests/Feature/SchoolProfile/SchoolWatermarkSettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\DocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('saves a text watermark with its opacity', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkText', 'HERITAGE BILINGUAL COLLEGE')
        ->set('watermarkOpacity', 12)
        ->call('save')
        ->assertHasNoErrors();

    $row = DB::table('school_document_profiles')->where('id', 1)->first();

    expect((bool) $row->watermark_enabled)->toBeTrue()
        ->and($row->watermark_text)->toBe('HERITAGE BILINGUAL COLLEGE')
        ->and((int) $row->watermark_opacity)->toBe(12);
});

it('refuses an opacity outside 1-30', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkText', 'HERITAGE')
        ->set('watermarkOpacity', 90)
        ->call('save')
        ->assertHasErrors('watermark_opacity');
});

it('refuses an enabled watermark with neither text nor image', function (): void {
    // Switching a watermark on and supplying nothing to draw is the state
    // that produces an empty, mysterious block on every document.
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkText', '')
        ->call('save')
        ->assertHasErrors('watermark_text');
});

it('stores an uploaded watermark image under a content-hashed path', function (): void {
    p13coreUserAs(Role::Principal);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkUpload', UploadedFile::fake()->image('mark.png', 600, 600))
        ->call('save')
        ->assertHasNoErrors();

    expect((string) DB::table('school_document_profiles')->where('id', 1)->value('watermark_image_path'))
        ->toStartWith('branding/watermark-');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile/SchoolWatermarkSettingsTest.php`
Expected: FAIL — `Unable to set component property [watermarkEnabled]`.

- [ ] **Step 3: Extend `SaveDocumentProfile`**

In `app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php`, add to the validation array after `'school_stamp_path' => …`:

```php
            'watermark_enabled' => ['nullable', 'boolean'],
            // Required WHEN the watermark is on and no image was supplied:
            // an enabled watermark with nothing to draw is an empty,
            // unexplainable block on every document the school prints.
            'watermark_text' => [
                'nullable', 'string', 'max:60',
                'required_if:watermark_enabled,true,1', 'exclude_if:watermark_image_path,!=,null',
            ],
            'watermark_image_path' => ['nullable', 'string', 'max:255'],
            // 1-30 percent. Below 1 it does not print; above 30 it competes
            // with the text it sits behind and the document stops being
            // readable.
            'watermark_opacity' => ['nullable', 'integer', 'min:1', 'max:30'],
```

The `exclude_if` above does not express "text is required unless an image was supplied" correctly on its own — replace both rules with an explicit `after` hook. Change the `Validator::make(...)->validate()` call to:

```php
        $validator = Validator::make($input, [
            // ... every existing rule, unchanged, plus:
            'watermark_enabled' => ['nullable', 'boolean'],
            'watermark_text' => ['nullable', 'string', 'max:60'],
            'watermark_image_path' => ['nullable', 'string', 'max:255'],
            'watermark_opacity' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $validator->after(function (\Illuminate\Validation\Validator $v) use ($input): void {
            $enabled = filter_var($input['watermark_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $hasText = is_string($input['watermark_text'] ?? null) && trim((string) $input['watermark_text']) !== '';
            $hasImage = is_string($input['watermark_image_path'] ?? null) && $input['watermark_image_path'] !== '';

            // An enabled watermark with nothing to draw prints an empty,
            // unexplainable block on every document the school issues.
            if ($enabled && ! $hasText && ! $hasImage) {
                $v->errors()->add(
                    'watermark_text',
                    'Give the watermark either text or an image before switching it on.',
                );
            }
        });

        $data = $validator->validate();
```

- [ ] **Step 4: Add the properties and the fieldset**

In `DocumentProfile.php`, add the properties beside the other five slots:

```php
    public bool $watermarkEnabled = false;

    public string $watermarkText = '';

    public string $watermarkImagePath = '';

    public int $watermarkOpacity = 8;

    public ?TemporaryUploadedFile $watermarkUpload = null;
```

Add `'watermark' => ['watermarkUpload', 'watermarkImagePath', 'watermark_image_path'],` to `IMAGE_SLOTS` (this gives the watermark image the same content-hashed storage, preview, remove control and delete-on-replace as the other five, with no extra code).

In `hydrateFromDatabase()`, add:

```php
        $this->watermarkEnabled = (bool) ($row->watermark_enabled ?? false);
        $this->watermarkText = (string) ($row->watermark_text ?? '');
        $this->watermarkImagePath = (string) ($row->watermark_image_path ?? '');
        $this->watermarkOpacity = (int) ($row->watermark_opacity ?? 8);
```

In `save()`'s payload array, add:

```php
                'watermark_enabled' => $this->watermarkEnabled,
                'watermark_text' => $this->watermarkText ?: null,
                'watermark_image_path' => $this->watermarkImagePath ?: null,
                'watermark_opacity' => $this->watermarkOpacity,
```

- [ ] **Step 5: Add the UI**

Insert this fieldset into `document-profile.blade.php`, after the marks fieldset:

```blade
        <x-settings-fieldset :heading="__('opes.school_identity.watermark')"
                             :hint="__('opes.school_identity.watermark_hint')">
            <x-settings-field :label="__('opes.school_identity.watermark_enabled')"
                              :hint="__('opes.school_identity.watermark_enabled_hint')" :span="2">
                <label class="flex items-center gap-2 text-sm font-normal">
                    <input type="checkbox" wire:model.live="watermarkEnabled">
                    <span>{{ __('opes.school_identity.watermark_enabled_hint') }}</span>
                </label>
            </x-settings-field>

            @if ($watermarkEnabled)
                <x-settings-field :label="__('opes.school_identity.watermark_text')"
                                  :hint="__('opes.school_identity.watermark_text_hint')"
                                  :error="$errors->first('watermark_text')">
                    <input type="text" wire:model="watermarkText" maxlength="60"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </x-settings-field>

                <x-settings-field :label="__('opes.school_identity.watermark_opacity')"
                                  :hint="__('opes.school_identity.watermark_opacity_hint')"
                                  :error="$errors->first('watermark_opacity')">
                    <span class="flex items-center gap-3">
                        <input type="range" min="1" max="30" wire:model.live="watermarkOpacity" class="w-full">
                        <span class="w-10 shrink-0 text-right font-mono text-sm">{{ $watermarkOpacity }}%</span>
                    </span>
                </x-settings-field>

                <x-settings-field :label="__('opes.school_identity.watermark_image')"
                                  :hint="__('opes.school_identity.watermark_image_hint')"
                                  :error="$errors->first('watermarkUpload')" :span="2">
                    <div class="flex items-start gap-3">
                        <span wire:key="preview-watermark-{{ $watermarkImagePath }}"
                              class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border-primary bg-sand">
                            @if ($watermarkUpload !== null)
                                <img src="{{ $watermarkUpload->temporaryUrl() }}" alt="" class="max-h-full max-w-full object-contain">
                            @elseif ($watermarkImagePath !== '')
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($watermarkImagePath) }}"
                                     alt="" class="max-h-full max-w-full object-contain">
                            @else
                                <span class="text-xs text-charcoal/40">{{ __('opes.school_identity.no_image') }}</span>
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="watermarkUpload"
                                   accept="image/png,image/jpeg,image/webp"
                                   class="block w-full text-sm text-charcoal file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90">
                            @if ($watermarkImagePath !== '')
                                <button type="button" wire:click="removeImage('watermark')"
                                        class="mt-1 text-xs font-medium text-danger hover:underline">
                                    {{ __('opes.school_identity.remove_image') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </x-settings-field>
            @endif
        </x-settings-fieldset>
```

- [ ] **Step 6: Add the lang keys**

`lang/en/opes.php` under `'school_identity'`: `'watermark' => 'School watermark'`, `'watermark_hint' => 'A faint mark printed behind every document. It appears IN ADDITION to DUPLICATA, ANNULÉ and SPECIMEN, never instead of them.'`, `'watermark_enabled' => 'Enabled'`, `'watermark_enabled_hint' => 'Print the school watermark behind every document.'`, `'watermark_text' => 'Watermark text'`, `'watermark_text_hint' => 'Usually the school name. Ignored when an image is uploaded.'`, `'watermark_opacity' => 'Opacity'`, `'watermark_opacity_hint' => 'Below 5% it barely prints; above 15% it competes with the text.'`, `'watermark_image' => 'Watermark image'`, `'watermark_image_hint' => 'A crest or mark, printed instead of the text when supplied.'`.

`lang/fr/opes.php`: `'watermark' => "Filigrane de l'établissement"`, `'watermark_hint' => "Une marque discrète imprimée derrière chaque document. Elle s'ajoute à DUPLICATA, ANNULÉ et SPÉCIMEN, sans jamais les remplacer."`, `'watermark_enabled' => 'Activé'`, `'watermark_enabled_hint' => "Imprimer le filigrane derrière chaque document."`, `'watermark_text' => 'Texte du filigrane'`, `'watermark_text_hint' => "En général le nom de l'établissement. Ignoré si une image est fournie."`, `'watermark_opacity' => 'Opacité'`, `'watermark_opacity_hint' => "En dessous de 5 % il s'imprime à peine ; au-delà de 15 % il gêne la lecture."`, `'watermark_image' => 'Image du filigrane'`, `'watermark_image_hint' => "Des armoiries ou une marque, imprimées à la place du texte."`.

- [ ] **Step 7: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/SchoolProfile tests/Feature/Reporting/SchoolWatermarkTest.php tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 8: Build and commit**

```bash
npm run build
git add app/Modules/SchoolProfile/Actions/SaveDocumentProfile.php app/Modules/SchoolProfile/Livewire/DocumentProfile.php resources/views/livewire/schoolprofile/document-profile.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/SchoolProfile/SchoolWatermarkSettingsTest.php
git commit -m "feat(settings): configure the school watermark"
```

---

### Task 23: Prove pre-existing documents still reprint

**Files:**
- Test: `tests/Feature/Reporting/WatermarkReproducibilityTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

it('reprints a document issued BEFORE the school watermark existed', function (): void {
    // The migration that added these columns ran against a live database
    // full of issued documents. If the watermark were part of the hashed
    // artefact, switching it on would break every one of them, permanently.
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile(['watermark_enabled' => false]);

    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    $original = app(RenderDocument::class)->handle(...$args);

    // The school now switches its watermark on.
    DB::table('school_document_profiles')->where('id', 1)->update([
        'watermark_enabled' => true,
        'watermark_text' => 'HERITAGE BILINGUAL COLLEGE',
        'watermark_opacity' => 10,
    ]);

    $reprint = app(RenderDocument::class)->handle(...$args);

    // The CLEAN artefact still hashes the same - the watermark is an output
    // overlay, never part of the hashed bytes.
    expect($reprint->contentHash)->toBe($original->contentHash)
        ->and($reprint->isDuplicate)->toBeTrue();
});

it('does NOT put the newly-configured watermark on a reprint of an older document', function (): void {
    // The chrome was frozen at issue with watermark = null, so a reprint
    // carries the letterhead AS AT ISSUE - which is the difference between a
    // reprint and a forgery.
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile(['watermark_enabled' => false]);

    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    app(RenderDocument::class)->handle(...$args);

    DB::table('school_document_profiles')->where('id', 1)->update([
        'watermark_enabled' => true, 'watermark_text' => 'ADDED LATER',
    ]);

    expect(app(RenderDocument::class)->handle(...$args)->html)->not->toContain('ADDED LATER');
});
```

- [ ] **Step 2: Run it**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/WatermarkReproducibilityTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 3: Run the whole reporting suite**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Reporting/WatermarkReproducibilityTest.php
git commit -m "test(documents): pin reprint reproducibility across a watermark change"
```

---
# Phase 3 — Asset barcodes and label printing

**What exists:** `app/Modules/Assets/` has 13 models and 18 Actions — the register is deep and complete. `assets.tag_number` is a unique `string(40)` that is already populated. `picqer/php-barcode-generator` is in `vendor` and **referenced nowhere in app code**. `PaperSize::CR80` (the 85.60 × 53.98 mm card blank) is defined, carries its own points box, and **is used by none of the 16 registered templates**. `App\Modules\Reporting\Domain\AdmissionNumber` implements `barcodePayload()` / `fromBarcodePayload()` with a documented Code 39 transform that **refuses any number that would not survive the round trip** — that discipline, not that class, is what Phase 3 reuses.

**Labels are LIVE documents (`is_snapshot_backed = false`), with no series.** A label is a working artefact — you print another when one peels off — not a certificate. Burning a serial number per label would put a gap in a statutory counter every time a store keeper reprints a sticker.

### Task 24: `AssetTagBarcode`

**Files:**
- Create: `app/Modules/Reporting/Domain/AssetTagBarcode.php`
- Test: `tests/Feature/Reporting/AssetTagBarcodeRoundTripTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\AssetTagBarcode;

/**
 * Same discipline as AdmissionNumberRoundTripTest: a barcode that scans back
 * as a DIFFERENT tag is worse than no barcode, because a stock-take believes
 * the scanner.
 */
it('strips the separators for the Code 39 payload', function (): void {
    expect(AssetTagBarcode::fromCanonical('HBC/AST/2026/000145')->barcodePayload())
        ->toBe('HBCAST2026000145');
});

it('round-trips every canonical shape the register produces', function (string $canonical): void {
    expect(AssetTagBarcode::fromBarcodePayload(
        AssetTagBarcode::fromCanonical($canonical)->barcodePayload()
    )->canonical())->toBe($canonical);
})->with([
    'HBC/AST/2026/000145',
    'AST/2026/000001',
    'HBC/AST/2026/1',
    'LAB/AST/1999/999999',
]);

it('refuses a tag that would not survive the round trip', function (): void {
    // A tag with no AST marker cannot be re-punctuated unambiguously: the
    // payload HBC2026000145 could be HBC/2026/000145 or HB/C2026/... The
    // class refuses rather than printing a label that scans back wrong.
    AssetTagBarcode::fromCanonical('HBC/2026/000145')->barcodePayload();
})->throws(DomainException::class, 'round trip');

it('refuses a canonical form the register never produces', function (): void {
    AssetTagBarcode::fromCanonical('hbc-ast-2026-145');
})->throws(InvalidArgumentException::class);

it('refuses a payload that is not an asset tag', function (): void {
    AssetTagBarcode::fromBarcodePayload('AST');
})->throws(InvalidArgumentException::class);

it('preserves leading zeros in the sequence', function (): void {
    expect(AssetTagBarcode::fromBarcodePayload('AST2026000007')->canonical())
        ->toBe('AST/2026/000007');
});

it('accepts a free-form tag by refusing it rather than mangling it', function (): void {
    // Real registers contain hand-entered legacy tags. The label template must
    // print those WITHOUT a barcode rather than invent one.
    expect(AssetTagBarcode::tryFromCanonical('OLD LAB MICROSCOPE 4'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/AssetTagBarcodeRoundTripTest.php`
Expected: FAIL — `Class "App\Modules\Reporting\Domain\AssetTagBarcode" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use DomainException;
use InvalidArgumentException;

/**
 * ONE asset tag, two spellings, one documented transform between them -
 * modelled directly on AdmissionNumber, for the same reason:
 *
 *  - canonical (human-readable, printed beneath the barcode):  HBC/AST/2026/000145
 *  - barcode payload (Code 39 has no "/"):                     HBCAST2026000145
 *
 * The canonical shape is one or more UPPERCASE ALPHA segments ending in the
 * "AST" asset marker, a 4-digit year, then the sequence, "/"-separated. The
 * payload is that string with the separators removed - the uppercase
 * alphanumeric subset Code 39 needs.
 *
 * fromBarcodePayload() re-punctuates by structure: the letter run is split
 * before the AST marker, then the first four digits are the year and the rest
 * is the sequence, leading zeros preserved. barcodePayload() REFUSES
 * (DomainException) any tag whose payload would not survive that round trip,
 * so a label that scans back as a DIFFERENT asset can never be printed - a
 * stock-take believes the scanner, and a wrong scan silently moves the wrong
 * asset's custody record.
 *
 * The marker is mandatory, not optional as it is for admission numbers: an
 * asset register's tags are free-form enough in practice (imported legacy
 * tags, hand-written stickers) that guessing at the structure of an
 * unmarked tag would be exactly the ambiguity this class exists to refuse.
 * tryFromCanonical() is the caller's escape hatch - the label template prints
 * such a tag as text with NO barcode, which is honest.
 */
final readonly class AssetTagBarcode
{
    private const CANONICAL_PATTERN = '/^([A-Z]+(?:\/[A-Z]+)*)\/(\d{4})\/(\d+)$/';

    private const PAYLOAD_PATTERN = '/^([A-Z]+)(\d{4})(\d+)$/';

    private const ASSET_MARKER = 'AST';

    /**
     * @param  list<string>  $prefixSegments  e.g. ['HBC', 'AST']
     * @param  string  $year  4 digits
     * @param  string  $sequence  digits, leading zeros significant
     */
    private function __construct(
        public array $prefixSegments,
        public string $year,
        public string $sequence,
    ) {
    }

    public static function fromCanonical(string $canonical): self
    {
        if (preg_match(self::CANONICAL_PATTERN, $canonical, $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$canonical}' is not a canonical asset tag "
                .'(expected e.g. HBC/AST/2026/000145 or AST/2026/000001).'
            );
        }

        return new self(explode('/', $m[1]), $m[2], $m[3]);
    }

    /**
     * The register contains hand-entered and imported legacy tags. This is
     * how a caller asks "can this one carry a barcode?" without catching an
     * exception for the ordinary case.
     */
    public static function tryFromCanonical(string $canonical): ?self
    {
        try {
            $tag = self::fromCanonical($canonical);
            $tag->barcodePayload();

            return $tag;
        } catch (InvalidArgumentException|DomainException) {
            return null;
        }
    }

    public static function fromBarcodePayload(string $payload): self
    {
        if (preg_match(self::PAYLOAD_PATTERN, $payload, $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$payload}' is not an asset-tag barcode payload (expected e.g. HBCAST2026000145)."
            );
        }

        return new self(self::splitLetterRun($m[1]), $m[2], $m[3]);
    }

    /** The human-readable form: HBC/AST/2026/000145. */
    public function canonical(): string
    {
        return implode('/', [...$this->prefixSegments, $this->year, $this->sequence]);
    }

    /**
     * The Code 39 payload: HBCAST2026000145. Guaranteed to round-trip, or it
     * throws before a label that scans back as a DIFFERENT asset is printed.
     */
    public function barcodePayload(): string
    {
        $payload = implode('', [...$this->prefixSegments, $this->year, $this->sequence]);

        $reread = self::fromBarcodePayload($payload)->canonical();

        if ($reread !== $this->canonical()) {
            throw new DomainException(
                "Asset tag '{$this->canonical()}' does not survive the barcode "
                ."round trip (payload '{$payload}' re-reads as '{$reread}'); "
                .'refusing to print a label that scans back as a different asset.'
            );
        }

        return $payload;
    }

    /**
     * The documented disambiguation rule: the letter run must END in the AST
     * marker, and is split before it. A run that is exactly the marker is one
     * segment; anything else is refused by the round-trip check above.
     *
     * @return list<string>
     */
    private static function splitLetterRun(string $letters): array
    {
        $marker = self::ASSET_MARKER;

        if ($letters !== $marker && str_ends_with($letters, $marker)) {
            return [substr($letters, 0, -strlen($marker)), $marker];
        }

        return [$letters];
    }
}
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/AssetTagBarcodeRoundTripTest.php`
Expected: PASS, 11 assertions across 7 tests (the round-trip test runs 4 datasets).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reporting/Domain/AssetTagBarcode.php tests/Feature/Reporting/AssetTagBarcodeRoundTripTest.php
git commit -m "feat(assets): add the round-trip-safe asset tag barcode payload"
```

---

### Task 25: `Code39Image` — a scannable barcode dompdf can draw

**Files:**
- Create: `app/Modules/Reporting/Domain/Code39Image.php`
- Test: `tests/Unit/Support/Code39ImageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Code39Image;

it('produces a PNG data URI', function (): void {
    $uri = Code39Image::dataUri('HBCAST2026000145');

    expect($uri)->toStartWith('data:image/png;base64,');

    $bytes = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

    // The PNG magic number. dompdf silently draws nothing for a malformed
    // image, so asserting "it is a string" would assert nothing.
    expect(substr((string) $bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('is deterministic for the same payload', function (): void {
    expect(Code39Image::dataUri('HBCAST2026000145'))->toBe(Code39Image::dataUri('HBCAST2026000145'));
});

it('produces different images for different payloads', function (): void {
    expect(Code39Image::dataUri('HBCAST2026000145'))->not->toBe(Code39Image::dataUri('HBCAST2026000146'));
});

it('refuses a payload outside the Code 39 alphanumeric subset', function (): void {
    // Lowercase and punctuation are not in the subset this platform uses, and
    // a generator that silently transliterates them prints a barcode that
    // scans back as something else.
    Code39Image::dataUri('hbc/ast/2026');
})->throws(InvalidArgumentException::class);

it('honours the height and width factor it is given', function (): void {
    expect(Code39Image::dataUri('HBCAST2026000145', widthFactor: 2, height: 40))
        ->not->toBe(Code39Image::dataUri('HBCAST2026000145', widthFactor: 2, height: 60));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/Code39ImageTest.php`
Expected: FAIL — `Class "App\Modules\Reporting\Domain\Code39Image" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use InvalidArgumentException;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * A Code 39 barcode as a base64 PNG data URI.
 *
 * `picqer/php-barcode-generator` has been in vendor since the register was
 * built and referenced by nothing; this is its first and only call site.
 *
 * PNG rather than SVG: dompdf's SVG support is partial and its rasteriser
 * antialiases thin strokes, which is exactly what a scanner cannot read. A
 * PNG at a whole-number width factor produces crisp bar edges. It is embedded
 * as a data URI for the same reason every other image in a document is -
 * DompdfRenderer sets setIsRemoteEnabled(false).
 *
 * The alphabet is checked here rather than trusted: the generator will
 * happily encode characters outside the subset this platform uses, producing
 * a barcode that scans back as something the register has never heard of.
 * Callers pass a payload from AssetTagBarcode::barcodePayload(), which is
 * already round-trip verified; this is the second gate.
 */
final class Code39Image
{
    /** The Code 39 subset this platform uses: uppercase alphanumerics only. */
    private const ALPHABET = '/^[0-9A-Z]+$/';

    public static function dataUri(string $payload, int $widthFactor = 2, int $height = 44): string
    {
        if (preg_match(self::ALPHABET, $payload) !== 1) {
            throw new InvalidArgumentException(
                "'{$payload}' is outside the Code 39 subset this platform prints "
                .'(uppercase letters and digits only).'
            );
        }

        $png = (new BarcodeGeneratorPNG)->getBarcode(
            $payload,
            BarcodeGenerator::TYPE_CODE_39,
            $widthFactor,
            $height,
        );

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Support/Code39ImageTest.php`
Expected: PASS, 5 tests.

`BarcodeGeneratorPNG` needs the `gd` extension. Confirm with `$PHP -m | grep -i "^gd$"` — expected `gd`. (Already verified present on this box.)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reporting/Domain/Code39Image.php tests/Unit/Support/Code39ImageTest.php
git commit -m "feat(assets): render Code 39 barcodes as embedded PNGs"
```

---

### Task 26: Register the ASSET-LABEL templates at CR80 and A4

**Files:**
- Create: `database/migrations/2026_08_15_440003_seed_asset_label_templates.php`
- Test: `tests/Feature/Assets/AssetLabelTemplateSeedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers the single label at CR80', function (): void {
    $row = DB::table('document_templates')->where('code', 'ASSET-LABEL')->first();

    expect($row)->not->toBeNull()
        // CR80 has been defined in PaperSize since the platform shipped and
        // used by nothing; a label is the size it exists for.
        ->and($row->paper_size)->toBe('CR80')
        ->and($row->module)->toBe('Assets')
        // A label is a working artefact, not a certificate: no series, no
        // IssuedDocument, no serial burn on a reprint.
        ->and((bool) $row->is_snapshot_backed)->toBeFalse()
        ->and($row->series_code)->toBeNull()
        ->and((bool) $row->carries_barcode)->toBeTrue()
        ->and($row->state_header)->toBe('none')
        ->and($row->blade_view)->toBe('documents.assets.label');
});

it('registers the bulk sheet at A4 and marks it bulk-printable', function (): void {
    $row = DB::table('document_templates')->where('code', 'ASSET-LABEL-SHEET')->first();

    expect($row)->not->toBeNull()
        ->and($row->paper_size)->toBe('A4')
        ->and((bool) $row->bulk_printable)->toBeTrue()
        ->and($row->blade_view)->toBe('documents.assets.label-sheet');
});

it('gives neither template a signature role', function (): void {
    // Nobody signs a sticker; a signature line on one is theatre.
    foreach (['ASSET-LABEL', 'ASSET-LABEL-SHEET'] as $code) {
        $roles = DB::table('document_templates')->where('code', $code)->value('signature_roles');

        expect(json_decode((string) $roles, true) ?: [])->toBe([]);
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Assets/AssetLabelTemplateSeedTest.php`
Expected: FAIL — the row is null.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The asset label set, following the 310010 / 430001 seed migrations' shape.
 *
 * CR80 (85.60 x 53.98 mm, the ID-card blank) has been a PaperSize case since
 * the platform shipped and was used by NOTHING. A stick-on asset label is
 * exactly the artefact it was defined for, and it is the size the label
 * printers a school already owns are loaded with.
 *
 * LIVE, not snapshot-backed, and NO series: a label is a working artefact -
 * you print another when one peels off a projector - not a certificate.
 * Burning a serial per label would put a gap in a statutory counter every
 * time a store keeper reprints a sticker, and there is nothing about a
 * sticker that needs to be reproducible byte-for-byte years later.
 *
 * ASSET-LABEL-SHEET is the stock-take variant: N labels tiled on one A4, so
 * a school with an ordinary office printer and a sheet of blank labels can
 * do a whole store room in one pass.
 *
 * state_header = 'none' on both: the bilingual ministry block is for
 * statutory documents. On a 54 mm sticker it would consume the whole label
 * and say nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_templates')->insert([
            [
                'code' => 'ASSET-LABEL',
                'name' => 'Asset Label',
                'name_fr' => "Étiquette d'immobilisation",
                'module' => 'Assets',
                'paper_size' => 'CR80',
                'orientation' => 'landscape',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => false,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => true,
                'signature_roles' => json_encode([], JSON_THROW_ON_ERROR),
                'state_header' => 'none',
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.assets.label',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'ASSET-LABEL-SHEET',
                'name' => 'Asset Label Sheet',
                'name_fr' => "Planche d'étiquettes d'immobilisation",
                'module' => 'Assets',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => false,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => true,
                'signature_roles' => json_encode([], JSON_THROW_ON_ERROR),
                'state_header' => 'none',
                'min_phase' => 'v1',
                'bulk_printable' => true,
                'blade_view' => 'documents.assets.label-sheet',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->whereIn('code', ['ASSET-LABEL', 'ASSET-LABEL-SHEET'])->delete();
    }
};
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Assets/AssetLabelTemplateSeedTest.php`
Expected: PASS, 3 tests.

If the insert fails on a missing column, run `$PHP artisan db:table document_templates` and reconcile against the 430001 seed migration's column list — do not guess.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_15_440003_seed_asset_label_templates.php tests/Feature/Assets/AssetLabelTemplateSeedTest.php
git commit -m "feat(assets): register the CR80 asset label and A4 label sheet templates"
```

---

### Task 27: The label templates

**Files:**
- Create: `resources/views/documents/assets/label.blade.php`
- Create: `resources/views/documents/assets/label-sheet.blade.php`
- Modify: `lang/en/documents.php`, `lang/fr/documents.php`

- [ ] **Step 1: Write the single label**

Create `resources/views/documents/assets/label.blade.php`:

```blade
{{-- ASSET-LABEL, CR80 landscape (85.60 x 53.98 mm). NOT an extension of
     documents.layout: that shell carries a 28pt page margin, a fixed footer
     and a watermark layer, all of which are correct for a certificate and
     ruinous on a 54 mm sticker. A label is its own shell.

     Everything is in points at 72pt/in against the CR80 box PaperSize
     defines (242.65 x 153.01 pt), because 12.1 requires EXACT physical
     sizing - a label printed 3% small no longer lines up with a die-cut
     sheet. --}}
<!DOCTYPE html>
<html lang="{{ $document['language'] }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['template_code'] }}</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #000;
            margin: 0;
            padding: 6pt 8pt;
            font-size: 7pt;
        }
        .lbl-school { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; }
        .lbl-crest { height: 16pt; width: auto; float: right; }
        .lbl-name { font-size: 9pt; font-weight: bold; margin-top: 2pt; }
        .lbl-meta { font-size: 6.5pt; color: #333; }
        .lbl-barcode { margin-top: 3pt; text-align: center; }
        .lbl-barcode img { height: 30pt; width: auto; max-width: 210pt; }
        .lbl-tag { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; font-weight: bold; letter-spacing: 1pt; text-align: center; }
    </style>
</head>
<body>
    @if (!empty($school['branding']['crest_uri']))
        <img src="{{ $school['branding']['crest_uri'] }}" alt="" class="lbl-crest">
    @endif

    <div class="lbl-school">{{ $school['name'] ?: $school['name_fr'] }}</div>
    <div class="lbl-name">{{ $payload['name'] }}</div>
    <div class="lbl-meta">
        {{ $payload['category'] }}@if (!empty($payload['location'])) · {{ $payload['location'] }}@endif
    </div>

    {{-- A tag that cannot carry a Code 39 barcode that scans back as ITSELF
         prints as text alone. A barcode that reads as a different asset is
         worse than none, because a stock-take believes the scanner. --}}
    @if (!empty($payload['barcode_uri']))
        <div class="lbl-barcode"><img src="{{ $payload['barcode_uri'] }}" alt=""></div>
    @else
        <div class="lbl-meta" style="margin-top: 4pt; text-align: center;">
            {{ __('documents.assets.no_barcode', [], $document['language']) }}
        </div>
    @endif

    <div class="lbl-tag">{{ $payload['tag_number'] }}</div>
</body>
</html>
```

- [ ] **Step 2: Write the bulk sheet**

Create `resources/views/documents/assets/label-sheet.blade.php`:

```blade
{{-- ASSET-LABEL-SHEET, A4 portrait: the stock-take variant. Two columns of
     labels down the page, so a school with an ordinary office printer and a
     sheet of blank labels can do a whole store room in one pass.

     Table layout, not flexbox or grid: dompdf's CSS support is a subset and
     its float/flex handling across a page break is unreliable, while its
     table pagination is solid and `page-break-inside: avoid` on the row keeps
     a label from being sliced in half by the page edge. --}}
<!DOCTYPE html>
<html lang="{{ $document['language'] }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['template_code'] }}</title>
    <style>
        @page { margin: 12mm 8mm; }
        body { font-family: "DejaVu Sans", sans-serif; color: #000; margin: 0; font-size: 7pt; }
        table { border-collapse: collapse; width: 100%; }
        tr { page-break-inside: avoid; }
        td.lbl {
            width: 50%;
            height: 96pt;
            border: 0.5pt dashed #999;   /* the cut line */
            padding: 6pt 8pt;
            vertical-align: top;
        }
        .lbl-school { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; }
        .lbl-name { font-size: 9pt; font-weight: bold; margin-top: 2pt; }
        .lbl-meta { font-size: 6.5pt; color: #333; }
        .lbl-barcode { margin-top: 3pt; text-align: center; }
        .lbl-barcode img { height: 26pt; width: auto; max-width: 190pt; }
        .lbl-tag { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-align: center; }
        .sheet-head { margin-bottom: 6pt; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="sheet-head">
        <strong>{{ $school['name'] ?: $school['name_fr'] }}</strong> ·
        {{ __('documents.assets.label_sheet_title', [], $document['language']) }} ·
        {{ __('documents.assets.label_sheet_count', ['count' => count($payload['labels'])], $document['language']) }}
        @if (!empty($document['generated_at']))
            · {{ $document['generated_at'] }}
        @endif
    </div>

    <table>
        @foreach (array_chunk($payload['labels'], 2) as $row)
            <tr>
                @foreach ($row as $label)
                    <td class="lbl">
                        <div class="lbl-school">{{ $school['name'] ?: $school['name_fr'] }}</div>
                        <div class="lbl-name">{{ $label['name'] }}</div>
                        <div class="lbl-meta">
                            {{ $label['category'] }}@if (!empty($label['location'])) · {{ $label['location'] }}@endif
                        </div>
                        @if (!empty($label['barcode_uri']))
                            <div class="lbl-barcode"><img src="{{ $label['barcode_uri'] }}" alt=""></div>
                        @else
                            <div class="lbl-meta" style="margin-top: 4pt; text-align: center;">
                                {{ __('documents.assets.no_barcode', [], $document['language']) }}
                            </div>
                        @endif
                        <div class="lbl-tag">{{ $label['tag_number'] }}</div>
                    </td>
                @endforeach

                {{-- An odd final row gets an empty cell rather than a
                     half-width one, so the last real label keeps the same
                     dimensions as every other. --}}
                @if (count($row) === 1)
                    <td class="lbl" style="border-color: transparent;"></td>
                @endif
            </tr>
        @endforeach
    </table>
</body>
</html>
```

- [ ] **Step 3: Add the document strings**

`lang/en/documents.php`, add a top-level `'assets' => [...]`:

```php
    'assets' => [
        'no_barcode' => 'No scannable tag',
        'label_sheet_title' => 'Asset labels',
        'label_sheet_count' => ':count labels',
    ],
```

`lang/fr/documents.php`:

```php
    'assets' => [
        'no_barcode' => 'Étiquette non scannable',
        'label_sheet_title' => "Étiquettes d'immobilisations",
        'label_sheet_count' => ':count étiquettes',
    ],
```

- [ ] **Step 4: Verify the strings resolve**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/LocalisationTest.php`
Expected: PASS — this suite asserts the `en` and `fr` key sets match.

- [ ] **Step 5: Commit**

```bash
git add resources/views/documents/assets/label.blade.php resources/views/documents/assets/label-sheet.blade.php lang/en/documents.php lang/fr/documents.php
git commit -m "feat(assets): add the CR80 label and A4 label-sheet templates"
```

---

### Task 28: `PrintAssetLabel` and `PrintAssetLabelSheet`

**Files:**
- Create: `app/Modules/Assets/Actions/PrintAssetLabel.php`
- Test: `tests/Feature/Assets/PrintAssetLabelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\PrintAssetLabel;
use App\Modules\Assets\Models\Asset;
use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Domain\AssetTagBarcode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    p13coreDocumentProfile();
});

it('renders a single label carrying the tag and its barcode', function (): void {
    p13coreUserAs(Role::Bursar);

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145', 'name' => 'Epson Projector']);

    $doc = app(PrintAssetLabel::class)->handle((int) $asset->getKey());

    expect($doc->html)
        ->toContain('HBC/AST/2026/000145')
        ->toContain('Epson Projector')
        ->toContain('data:image/png;base64,')
        // Live document: no serial burned, no IssuedDocument written.
        ->and($doc->serial)->toBeNull()
        ->and($doc->issuedDocumentId)->toBeNull();
});

it('prints a legacy tag with no barcode rather than an invented one', function (): void {
    p13coreUserAs(Role::Bursar);

    $asset = Asset::factory()->create(['tag_number' => 'OLD LAB MICROSCOPE 4', 'name' => 'Microscope']);

    expect(AssetTagBarcode::tryFromCanonical('OLD LAB MICROSCOPE 4'))->toBeNull();

    $doc = app(PrintAssetLabel::class)->handle((int) $asset->getKey());

    expect($doc->html)
        ->toContain('OLD LAB MICROSCOPE 4')
        ->not->toContain('data:image/png;base64,');
});

it('burns no series number even across repeated prints', function (): void {
    p13coreUserAs(Role::Bursar);

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145']);

    app(PrintAssetLabel::class)->handle((int) $asset->getKey());
    app(PrintAssetLabel::class)->handle((int) $asset->getKey());

    // Two print-log rows (every render is logged), zero issued documents.
    expect(DB::table('issued_documents')->count())->toBe(0)
        ->and(DB::table('document_print_logs')->count())->toBe(2);
});

it('refuses a caller without asset.view', function (): void {
    p13coreUserAs(Role::Teacher);

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145']);

    app(PrintAssetLabel::class)->handle((int) $asset->getKey());
})->throws(AuthorizationException::class);

it('renders a sheet of labels for a set of assets', function (): void {
    p13coreUserAs(Role::Bursar);

    $ids = [];

    foreach (['HBC/AST/2026/000001', 'HBC/AST/2026/000002', 'HBC/AST/2026/000003'] as $tag) {
        $ids[] = (int) Asset::factory()->create(['tag_number' => $tag])->getKey();
    }

    $doc = app(PrintAssetLabel::class)->sheet($ids);

    expect($doc->html)
        ->toContain('HBC/AST/2026/000001')
        ->toContain('HBC/AST/2026/000002')
        ->toContain('HBC/AST/2026/000003')
        ->toContain('3 labels');
});

it('refuses an empty sheet rather than printing a blank page', function (): void {
    p13coreUserAs(Role::Bursar);

    app(PrintAssetLabel::class)->sheet([]);
})->throws(DomainException::class);

it('caps a sheet so one click cannot render ten thousand labels', function (): void {
    p13coreUserAs(Role::Bursar);

    app(PrintAssetLabel::class)->sheet(range(1, PrintAssetLabel::SHEET_LIMIT + 1));
})->throws(DomainException::class, 'at a time');
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Assets/PrintAssetLabelTest.php`
Expected: FAIL — `Target class [App\Modules\Assets\Actions\PrintAssetLabel] does not exist.`

- [ ] **Step 3: Write the Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\AssetTagBarcode;
use App\Modules\Reporting\Domain\Code39Image;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Asset labels - one sticker, or a sheet of them for a stock-take.
 *
 * Goes through RenderDocument like every other PDF in this platform
 * (10-documents 4.8: it is THE only path to a PDF), on a LIVE template: a
 * label is a working artefact, not a certificate. Nothing about a sticker
 * needs to reproduce byte-for-byte in five years, and burning a serial per
 * label would put a gap in a statutory counter every time one peels off a
 * projector and gets reprinted.
 *
 * Cross-module reads use DB::table - `asset_categories` and `locations` are
 * this module's own, but the pattern is the register's throughout and
 * ModuleBoundaryTest holds it there.
 */
final class PrintAssetLabel
{
    /**
     * The most labels one sheet render will build. A stock-take of a large
     * secondary school is a few hundred assets; ten thousand base64 PNGs in
     * one HTML string is an out-of-memory, not a print job.
     */
    public const SHEET_LIMIT = 400;

    public function __construct(private readonly RenderDocument $render)
    {
    }

    public function handle(int $assetId): RenderedDocument
    {
        Gate::authorize(AssetPermission::VIEW);

        $label = $this->label($assetId);

        if ($label === null) {
            throw new DomainException("Asset {$assetId} does not exist; there is nothing to label.");
        }

        return $this->render->handle(
            templateCode: 'ASSET-LABEL',
            subjectType: 'Asset',
            subjectId: $assetId,
            subjectLabel: $label['tag_number'].' — '.$label['name'],
            data: $label,
        );
    }

    /**
     * @param  list<int>  $assetIds
     */
    public function sheet(array $assetIds): RenderedDocument
    {
        Gate::authorize(AssetPermission::VIEW);

        if ($assetIds === []) {
            throw new DomainException('Select at least one asset before printing a label sheet.');
        }

        if (count($assetIds) > self::SHEET_LIMIT) {
            throw new DomainException(
                'A label sheet prints at most '.self::SHEET_LIMIT.' labels at a time; '
                .count($assetIds).' were selected.'
            );
        }

        $labels = [];

        foreach ($assetIds as $assetId) {
            $label = $this->label($assetId);

            if ($label !== null) {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            throw new DomainException('None of the selected assets exist; there is nothing to label.');
        }

        return $this->render->handle(
            templateCode: 'ASSET-LABEL-SHEET',
            subjectType: 'AssetLabelSheet',
            // A sheet is not "about" one asset. The first asset's id is a
            // stable, non-null subject for the print log - which is what the
            // log is for: WHO printed WHAT, WHEN.
            subjectId: (int) $labels[0]['asset_id'],
            subjectLabel: count($labels).' asset labels',
            data: ['labels' => $labels],
        );
    }

    /**
     * @return array{asset_id: int, tag_number: string, name: string, category: string, location: string|null, barcode_uri: string|null}|null
     */
    private function label(int $assetId): ?array
    {
        $row = DB::table('assets as a')
            ->leftJoin('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->leftJoin('locations as l', 'l.id', '=', 'a.location_id')
            ->where('a.id', $assetId)
            ->select(['a.id', 'a.tag_number', 'a.name', 'c.name as category_name', 'l.name as location_name'])
            ->first();

        if ($row === null) {
            return null;
        }

        $tag = (string) $row->tag_number;

        // A tag that cannot carry a Code 39 barcode reading back as ITSELF
        // gets NO barcode. The register contains imported and hand-written
        // legacy tags, and a barcode that scans as a different asset is worse
        // than none - a stock-take believes the scanner.
        $barcode = AssetTagBarcode::tryFromCanonical($tag);

        return [
            'asset_id' => (int) $row->id,
            'tag_number' => $tag,
            'name' => (string) $row->name,
            'category' => is_string($row->category_name) ? $row->category_name : '—',
            'location' => is_string($row->location_name) ? $row->location_name : null,
            'barcode_uri' => $barcode === null ? null : Code39Image::dataUri($barcode->barcodePayload()),
        ];
    }
}
```

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Assets/PrintAssetLabelTest.php`
Expected: PASS, 7 tests.

If the `locations` join fails with `Table 'locations' doesn't exist`, find the real table with `$PHP artisan db:show --counts | grep -i loc` and correct the join — `Assets\Livewire\Show` joins `asset_categories` and `suppliers` but not locations, so this join is unverified.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Assets/Actions/PrintAssetLabel.php tests/Feature/Assets/PrintAssetLabelTest.php
git commit -m "feat(assets): print single asset labels and stock-take label sheets"
```

---

### Task 29: The print controls on the asset screens

**Files:**
- Modify: `app/Modules/Assets/Livewire/Show.php`
- Modify: `resources/views/livewire/assets/show.blade.php`
- Modify: `app/Modules/Assets/Livewire/Index.php`
- Modify: `resources/views/livewire/assets/index.blade.php`
- Test: `tests/Feature/Assets/AssetLabelScreenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Assets\Livewire\Index as AssetsIndex;
use App\Modules\Assets\Livewire\Show as AssetsShow;
use App\Modules\Assets\Models\Asset;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    p13coreDocumentProfile();
});

it('streams a label PDF from the asset detail screen', function (): void {
    p13coreUserAs(Role::Bursar);

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145']);

    $response = Livewire::test(AssetsShow::class, ['asset' => $asset])
        ->call('printLabel')
        ->getEffects()['returns'][0] ?? null;

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('streams a label sheet for the assets selected on the index', function (): void {
    p13coreUserAs(Role::Bursar);

    $ids = [
        (int) Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000001'])->getKey(),
        (int) Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000002'])->getKey(),
    ];

    $response = Livewire::test(AssetsIndex::class)
        ->set('selectedAssetIds', $ids)
        ->call('printLabelSheet')
        ->getEffects()['returns'][0] ?? null;

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('reports an error rather than streaming an empty sheet', function (): void {
    p13coreUserAs(Role::Bursar);

    Livewire::test(AssetsIndex::class)
        ->set('selectedAssetIds', [])
        ->call('printLabelSheet')
        ->assertHasErrors('selectedAssetIds');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Assets/AssetLabelScreenTest.php`
Expected: FAIL — `Method printLabel does not exist`.

- [ ] **Step 3: Add the single-label control**

In `app/Modules/Assets/Livewire/Show.php`, add the imports `use App\Modules\Assets\Actions\PrintAssetLabel;` and `use App\Modules\Reporting\Domain\DocumentFileName;`, then add:

```php
    /**
     * Stream the CR80 asset label. Reuses PrintAssetLabel, which goes through
     * RenderDocument - the ONLY path to a PDF in this platform - so the label
     * is print-logged like everything else.
     */
    public function printLabel(PrintAssetLabel $printAssetLabel): Response
    {
        Gate::authorize(AssetPermission::VIEW);

        try {
            $document = $printAssetLabel->handle((int) $this->asset->getKey());
        } catch (DomainException $e) {
            $this->addError('printLabel', $e->getMessage());

            return response('', 204);
        }

        $filename = 'asset-label-'.DocumentFileName::sanitize($this->asset->tag_number).'.pdf';

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document->bytes;
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
```

- [ ] **Step 4: Add the button to the detail blade**

In `resources/views/livewire/assets/show.blade.php`, beside the existing "Asset Card" export control, add:

```blade
                <button type="button" wire:click="printLabel"
                        class="rounded-lg border border-border-primary px-3 py-2 text-sm font-medium text-charcoal transition hover:bg-sand">
                    <span wire:loading.remove wire:target="printLabel">{{ __('opes.assets.print_label') }}</span>
                    <span wire:loading wire:target="printLabel">{{ __('opes.ui.saving') }}</span>
                </button>
                @error('printLabel')
                    <span class="text-xs font-medium text-danger">{{ $message }}</span>
                @enderror
```

**Read the surrounding markup first** — place this inside the same flex row that holds `exportAssetCardPdf`, so the two controls sit together rather than the new one landing in the page header.

- [ ] **Step 5: Add bulk selection and the sheet control to the index**

In `app/Modules/Assets/Livewire/Index.php`, add:

```php
    /**
     * The assets ticked for a bulk label sheet. Kept as a plain list on the
     * component rather than a "select all matching filter" flag: a stock-take
     * operator needs to see exactly which stickers they are about to print,
     * and "all 4 200 assets" is not a print job anyone meant to start.
     *
     * @var list<int>
     */
    public array $selectedAssetIds = [];

    public function printLabelSheet(PrintAssetLabel $printAssetLabel): Response
    {
        Gate::authorize(AssetPermission::VIEW);

        try {
            $document = $printAssetLabel->sheet(array_map('intval', $this->selectedAssetIds));
        } catch (DomainException $e) {
            $this->addError('selectedAssetIds', $e->getMessage());

            return response('', 204);
        }

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document->bytes;
            },
            'asset-labels-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
```

with the imports `use App\Modules\Assets\Actions\PrintAssetLabel;`, `use DomainException;` and `use Symfony\Component\HttpFoundation\Response;` (the last two may already be present — check before adding a duplicate).

- [ ] **Step 6: Add the checkbox column and the sheet button**

In `resources/views/livewire/assets/index.blade.php`, add a leading cell to the table header and each row:

```blade
                        <th class="w-8 px-3 py-2"><span class="sr-only">{{ __('opes.assets.select_for_label') }}</span></th>
```

and, in the row loop:

```blade
                        <td class="px-3 py-2">
                            <input type="checkbox" value="{{ $asset->id }}" wire:model.live="selectedAssetIds"
                                   aria-label="{{ __('opes.assets.select_for_label') }}">
                        </td>
```

and above the table:

```blade
            @if ($selectedAssetIds !== [])
                <div class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-kpi-green px-4 py-2 text-sm">
                    <span class="font-medium text-charcoal">
                        {{ __('opes.assets.selected_count', ['count' => count($selectedAssetIds)]) }}
                    </span>
                    <button type="button" wire:click="printLabelSheet"
                            class="rounded-lg border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white transition hover:bg-primary/90">
                        {{ __('opes.assets.print_label_sheet') }}
                    </button>
                    <button type="button" wire:click="$set('selectedAssetIds', [])"
                            class="text-xs font-medium text-charcoal/60 hover:underline">
                        {{ __('opes.ui.clear') }}
                    </button>
                    @error('selectedAssetIds')
                        <span class="text-xs font-medium text-danger">{{ $message }}</span>
                    @enderror
                </div>
            @endif
```

- [ ] **Step 7: Add the lang keys**

`lang/en/opes.php` under `'assets'`: `'print_label' => 'Print label'`, `'print_label_sheet' => 'Print label sheet'`, `'select_for_label' => 'Select for label printing'`, `'selected_count' => ':count selected'`. Under `'ui'`: `'clear' => 'Clear'` (skip if present).

`lang/fr/opes.php` under `'assets'`: `'print_label' => "Imprimer l'étiquette"`, `'print_label_sheet' => "Imprimer la planche d'étiquettes"`, `'select_for_label' => "Sélectionner pour impression d'étiquette"`, `'selected_count' => ':count sélectionné(s)'`. Under `'ui'`: `'clear' => 'Effacer'`.

- [ ] **Step 8: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Assets tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 9: Build and commit**

```bash
npm run build
git add app/Modules/Assets/Livewire/Show.php app/Modules/Assets/Livewire/Index.php resources/views/livewire/assets/show.blade.php resources/views/livewire/assets/index.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Assets/AssetLabelScreenTest.php
git commit -m "feat(assets): print controls for single labels and stock-take sheets"
```

---

# Phase 4 — Document preview before issue

**The problem:** `RenderDocument::handle()` **issues on first render**. There is no way to look at a certificate before a serial number is burned and an `IssuedDocument` row exists. An operator checking a name spelling has to issue the document, discover the error, revoke it, and issue a second one — leaving a void in the register for a typo.

**The hazard, and the whole design constraint:** a preview that assembles its payload separately from the issue path will *drift* from it. Then the preview shows one thing and the issued document says another, and the preview is worse than useless — it is actively misleading. **Therefore `preview()` must call the identical template resolution, language resolution, chrome capture and `renderHtml()` that `issueOriginal()` calls, differing only in what it does NOT do:** no series allocation, no `IssuedDocument`, no print log, no stored file. Task 32 asserts byte-equality between a preview and the subsequent issue, with the watermark being the only permitted difference.

### Task 30: `RenderDocument::preview()`

**Files:**
- Modify: `app/Modules/Reporting/Actions/RenderDocument.php`
- Test: `tests/Feature/Reporting/DocumentPreviewTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
    p13coreDocumentProfile();
});

it('allocates no serial, writes no issued document and logs no print', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
        'series_code' => 'TC',
    ]);

    $preview = app(RenderDocument::class)->preview(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshot['snapshot_id'], language: 'en',
    );

    expect($preview->serial)->toBeNull()
        ->and($preview->issuedDocumentId)->toBeNull()
        ->and($preview->printLogId)->toBeNull()
        ->and($preview->storagePath)->toBeNull()
        ->and(DB::table('issued_documents')->count())->toBe(0)
        ->and(DB::table('document_print_logs')->count())->toBe(0);
});

it('always carries the SPECIMEN watermark', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    // Fiscal identity CONFIRMED, so nothing else would put SPECIMEN on it.
    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
    ]);

    expect(app(RenderDocument::class)->preview(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshot['snapshot_id'], language: 'en',
    )->html)->toContain('SPÉCIMEN');
});

it('refuses a caller without the print permission', function (): void {
    p13coreUserAs(Role::Teacher);

    app(RenderDocument::class)->preview(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup', subjectId: 5, subjectLabel: 'Class list', language: 'en',
        data: ['rows' => []],
    );
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('previews a live template too', function (): void {
    p13coreUserAs(Role::Bursar);

    expect(app(RenderDocument::class)->preview(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup', subjectId: 5, subjectLabel: 'Class list Form 1A',
        language: 'en', data: ['rows' => ['AZEMKEU Brice']],
    )->html)->toContain('AZEMKEU Brice');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/DocumentPreviewTest.php`
Expected: FAIL — `Call to undefined method … ::preview()`.

- [ ] **Step 3: Allow a null print-log id on `RenderedDocument`**

Open `app/Modules/Reporting/Domain/RenderedDocument.php`. If `printLogId` and `storagePath` are typed `int`/`string` rather than nullable, widen them to `?int` / `?string` and document why:

```php
        // Nullable since the preview path (10-documents §4.8, preview
        // extension): a preview allocates no serial, writes no
        // IssuedDocument, logs no print and stores no file, so there is
        // genuinely nothing to report here. A zero would read as "print log
        // number zero" and a caller would follow it.
        public ?int $printLogId = null,
        public ?string $storagePath = null,
```

- [ ] **Step 4: Add `preview()`**

In `RenderDocument`, immediately after `handle()`, add:

```php
    /**
     * Render a document WITHOUT issuing it: no series number allocated, no
     * IssuedDocument row, no print log, no stored file - and SPECIMEN on the
     * face of it, unconditionally.
     *
     * Why this lives here rather than in a separate previewer: the ONE thing
     * a preview must never do is diverge from what issuing would produce. A
     * separate assembly path drifts - a payload key is added to one and not
     * the other, a chrome capture is subtly different - and a preview that
     * lies is worse than no preview, because the operator stops checking.
     *
     * So every step below is the SAME call the issue path makes: the same
     * template lookup, the same language resolution, the same snapshot load,
     * the same schoolChrome(), the same renderHtml(). The differences are all
     * SUBTRACTIONS, and they are the whole method.
     *
     * DocumentPreviewDivergenceTest asserts that a preview and the subsequent
     * issue of the same subject produce byte-identical HTML once the
     * watermark and the serial line are accounted for.
     *
     * @param  array<string, mixed>  $data
     */
    public function preview(
        string $templateCode,
        string $subjectType,
        int $subjectId,
        string $subjectLabel,
        ?int $snapshotId = null,
        ?string $language = null,
        ?int $schoolSectionId = null,
        array $data = [],
    ): RenderedDocument {
        // Same gate as issuing: previewing a payslip is reading a payslip.
        Gate::authorize(Permission::DocumentsPrint->value);

        /** @var DocumentTemplate $template */
        $template = DocumentTemplate::query()->where('code', $templateCode)->firstOrFail();

        if (! $template->is_active) {
            throw new DomainException(
                "Document template [{$templateCode}] is not active; an inactive template renders nothing."
            );
        }

        $lang = $this->resolveLanguage($language, $schoolSectionId);

        if ($template->is_snapshot_backed && $snapshotId === null) {
            throw ValidationException::withMessages([
                'snapshot_id' => "Template [{$templateCode}] is snapshot-backed; a preview without a snapshot "
                    .'would be a live query wearing a certificate (10-documents 4.2).',
            ]);
        }

        $snapshot = $template->is_snapshot_backed
            ? $this->loadSnapshot($template, (int) $snapshotId, $data, null)
            : ['payload' => $data, 'version' => null];

        $chrome = $this->schoolChrome($template, $schoolSectionId, $snapshot['payload']);

        $generatedAt = Carbon::now()->startOfSecond();

        // SPECIMEN unconditionally, not only while the fiscal identity is
        // provisional: an unissued document IS a specimen, and this is the
        // one signal separating a preview printed to paper from the real
        // thing.
        $html = $this->renderHtml($template, $template->version, null, $lang, $chrome, $snapshot['payload'], $subjectLabel, [
            'watermark' => 'specimen',
            'issued_at' => null,
            'generated_at' => $generatedAt,
            'generated_by' => $this->currentActor()->name,
            'copy_no' => 1,
        ]);

        $bytes = $this->pdf->render(
            $html,
            $template->paperSize(),
            $template->orientation(),
            new PdfStamp(
                $generatedAt->format('YmdHis'),
                $this->stampSeed($template, $subjectType, $subjectId, $snapshotId, null).'|preview',
            ),
            $this->pageFooter($lang),
        );

        return new RenderedDocument(
            bytes: $bytes,
            html: $html,
            contentHash: hash('sha256', $bytes),
            language: $lang,
            isDuplicate: false,
            copyNo: 1,
            serial: null,
            issuedDocumentId: null,
            printLogId: null,
            storagePath: null,
        );
    }
```

- [ ] **Step 5: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/DocumentPreviewTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Reporting/Actions/RenderDocument.php app/Modules/Reporting/Domain/RenderedDocument.php tests/Feature/Reporting/DocumentPreviewTest.php
git commit -m "feat(documents): preview a document without issuing it"
```

---

### Task 31: Prove preview and issue cannot diverge

**Files:**
- Test: `tests/Feature/Reporting/DocumentPreviewDivergenceTest.php`

Like Task 19, this adds no production code. The divergence hazard is the entire risk of Phase 4.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
    p13coreDocumentProfile([
        'address_line1' => 'Rue 1.234, Quartier Bastos',
        'city' => 'Yaoundé',
        'phone' => '+237 222 22 22 22',
    ]);
});

/**
 * Strip the parts that are ALLOWED to differ between a preview and the issued
 * document, so what remains is the part that must be identical: the payload,
 * the letterhead, the labels, the layout.
 */
function previewComparableHtml(string $html): string
{
    // The watermark block (preview is always SPECIMEN).
    $html = (string) preg_replace('#<div class="doc-(school-)?watermark">.*?</div>#s', '', $html);
    // The serial line (a preview has none) and the generated/issued dates.
    $html = (string) preg_replace('/\d{2}\/\d{2}\/\d{4}( \d{2}:\d{2})?/', 'DATE', $html);

    return trim((string) preg_replace('/\s+/', ' ', $html));
}

it('renders the same document body in preview as at issue', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
        'signature_roles' => ['principal'],
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    $preview = app(RenderDocument::class)->preview(...$args);
    $issued = app(RenderDocument::class)->handle(...$args);

    // A preview that shows something different from what gets issued is
    // worse than no preview: the operator stops checking, and the first
    // document they DON'T check is the one that is wrong.
    expect(previewComparableHtml($preview->html))
        ->toBe(previewComparableHtml($issued->html));
});

it('renders the same body for a live template too', function (): void {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()->create(['blade_view' => 'p13core-live']);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'ClassGroup', 'subjectId' => 5,
        'subjectLabel' => 'Class list Form 1A', 'language' => 'en',
        'data' => ['rows' => ['AZEMKEU Brice', 'NKENG Sandra']],
    ];

    expect(previewComparableHtml(app(RenderDocument::class)->preview(...$args)->html))
        ->toBe(previewComparableHtml(app(RenderDocument::class)->handle(...$args)->html));
});

it('previewing first does not change what the subsequent issue produces', function (): void {
    // A preview must be side-effect free. If previewing warmed a cache, took
    // a lock or advanced a counter, the issued document would differ from
    // one issued without a preview - and only sometimes.
    p13coreUserAs(Role::Bursar, Role::Principal);

    $makeArgs = function (): array {
        $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);

        return [
            'templateCode' => DocumentTemplate::factory()->create([
                'blade_view' => 'p13core-snapshot',
                'is_snapshot_backed' => true,
                'snapshot_source' => 'report_card',
            ])->code,
            'subjectType' => 'Enrollment', 'subjectId' => 42,
            'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
        ];
    };

    $withoutPreview = $makeArgs();
    $issuedPlain = app(RenderDocument::class)->handle(...$withoutPreview);

    $withPreview = $makeArgs();
    app(RenderDocument::class)->preview(...$withPreview);
    $issuedAfterPreview = app(RenderDocument::class)->handle(...$withPreview);

    expect(previewComparableHtml($issuedAfterPreview->html))
        ->toBe(previewComparableHtml($issuedPlain->html));
});
```

- [ ] **Step 2: Run it**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/DocumentPreviewDivergenceTest.php`
Expected: PASS, 3 tests.

**If test 1 fails**, read the diff carefully. If the difference is the serial number or a date, extend `previewComparableHtml()` to normalise that specific thing and say why in a comment. If the difference is anything in the document *body* — a payload field, a letterhead line, a label — **that is the bug this test exists to find**: `preview()` has diverged from `issueOriginal()` and must be brought back onto the same calls, not papered over with another regex.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Reporting/DocumentPreviewDivergenceTest.php
git commit -m "test(documents): pin preview against divergence from issue"
```

---

### Task 32: Wire preview into the student Documents tab

**Files:**
- Create: `app/Modules/Reporting/Http/Controllers/DocumentPreviewController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/students/show.blade.php`
- Test: `tests/Feature/Students/DocumentPreviewRouteTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    p13coreDocumentProfile();
});

it('streams a specimen PDF inline, never as a download', function (): void {
    p13coreUserAs(Role::Registrar);

    $student = Student::factory()->create();

    $response = get(route('documents.preview', [
        'template' => 'BONAFIDE', 'subjectType' => 'Student', 'subjectId' => $student->getKey(),
    ]));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // Inline: a preview opens in the viewer. A download called
    // "bonafide.pdf" sitting in Downloads is indistinguishable from the
    // issued certificate a week later.
    expect((string) $response->headers->get('content-disposition'))->toStartWith('inline');
});

it('refuses a caller without the print permission', function (): void {
    p13coreUserAs(Role::Teacher);

    get(route('documents.preview', [
        'template' => 'BONAFIDE', 'subjectType' => 'Student',
        'subjectId' => Student::factory()->create()->getKey(),
    ]))->assertForbidden();
});

it('refuses an unknown template rather than 500ing', function (): void {
    p13coreUserAs(Role::Registrar);

    get(route('documents.preview', [
        'template' => 'NOT-A-TEMPLATE', 'subjectType' => 'Student',
        'subjectId' => Student::factory()->create()->getKey(),
    ]))->assertNotFound();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students/DocumentPreviewRouteTest.php`
Expected: FAIL — `Route [documents.preview] not defined.`

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Students\Actions\StudentDocumentReads;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /documents/preview - render a document WITHOUT issuing it.
 *
 * A controller rather than a Livewire action because the output is a PDF the
 * browser opens in its own viewer: a Livewire component would have to stream
 * a download, and a downloaded file called `bonafide.pdf` sitting in
 * Downloads is indistinguishable from the issued certificate a week later.
 * `Content-Disposition: inline` keeps a preview a preview.
 *
 * Authorisation is RenderDocument::preview()'s own Gate check - previewing a
 * payslip is reading a payslip - plus the route's `can:documents.print`
 * middleware, so an unauthenticated probe never reaches the Action.
 */
final class DocumentPreviewController
{
    public function __invoke(Request $request, RenderDocument $render): Response
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:60'],
            'subjectType' => ['required', 'string', 'max:60'],
            'subjectId' => ['required', 'integer', 'min:1'],
            'snapshotId' => ['nullable', 'integer', 'min:1'],
            'language' => ['nullable', 'in:en,fr'],
        ]);

        try {
            $document = $render->preview(
                templateCode: $validated['template'],
                subjectType: $validated['subjectType'],
                subjectId: (int) $validated['subjectId'],
                subjectLabel: $this->subjectLabel($validated['subjectType'], (int) $validated['subjectId']),
                snapshotId: isset($validated['snapshotId']) ? (int) $validated['snapshotId'] : null,
                language: $validated['language'] ?? null,
            );
        } catch (ValidationException $e) {
            // A snapshot-backed template asked for without a snapshot: a
            // 422 with the Action's own message, not a 500.
            abort(422, (string) ($e->validator->errors()->first() ?: $e->getMessage()));
        } catch (DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response($document->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview-'.$validated['template'].'.pdf"',
            // A preview is a render of live data at this instant; caching it
            // would show yesterday's spelling of a corrected name.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The label the document's own header prints. Only the subject types the
     * preview screens actually offer are resolved; anything else previews
     * with its type and id, which is honest rather than guessed.
     */
    private function subjectLabel(string $subjectType, int $subjectId): string
    {
        if ($subjectType === 'Student') {
            return app(StudentDocumentReads::class)->displayName($subjectId)
                ?? $subjectType.' #'.$subjectId;
        }

        return $subjectType.' #'.$subjectId;
    }
}
```

**Before writing this**, run `grep -n "function " app/Modules/Students/Actions/StudentDocumentReads.php` and use whichever method that Action actually exposes for a student's display name. If it has none, replace the `Student` arm with a `DB::table('students')` read of `first_name`/`last_name` — do not invent a method.

- [ ] **Step 4: Add the route**

In `routes/web.php`, beside the other document routes:

```php
    /*
     * Preview a document before issuing it. Allocates no serial and writes no
     * IssuedDocument (RenderDocument::preview), and the artefact carries
     * SPECIMEN unconditionally so a preview printed to paper can never be
     * mistaken for the issued certificate.
     */
    Route::get('/documents/preview', \App\Modules\Reporting\Http\Controllers\DocumentPreviewController::class)
        ->middleware('can:documents.print')->name('documents.preview');
```

- [ ] **Step 5: Add the preview links to the student Documents tab**

In `resources/views/livewire/students/show.blade.php`, inside the `@if ($tab === 'documents')` block, above the document list:

```blade
            @can('documents.print')
                <div class="mb-4 rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-charcoal">{{ __('opes.students_screen.preview_certificates') }}</h3>
                    {{-- Said out loud, not implied by a watermark the operator
                         may not scroll to: a preview is not a certificate. --}}
                    <p class="mt-1 text-xs text-text-secondary">{{ __('opes.students_screen.preview_not_issued') }}</p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (['BONAFIDE', 'ATTEND-CERT', 'TESTIMONIAL', 'CHAR-CERT'] as $previewTemplate)
                            <a href="{{ route('documents.preview', [
                                   'template' => $previewTemplate,
                                   'subjectType' => 'Student',
                                   'subjectId' => $student->id,
                               ]) }}"
                               target="_blank" rel="noopener"
                               class="rounded-lg border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal transition hover:border-primary hover:bg-sand">
                                {{ __('opes.documents.template_'.strtolower(str_replace('-', '_', $previewTemplate))) }}
                                <span class="ml-1 text-xs text-charcoal/50">{{ __('opes.students_screen.preview_suffix') }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endcan
```

- [ ] **Step 6: Add the lang keys**

`lang/en/opes.php` under `'students_screen'`: `'preview_certificates' => 'Preview a certificate'`, `'preview_not_issued' => 'A preview is watermarked SPECIMEN, carries no certificate number, and is not recorded in the register. Issue the certificate from its own screen when the details are right.'`, `'preview_suffix' => '(preview)'`. Under a `'documents'` key: `'template_bonafide' => 'Bonafide Certificate'`, `'template_attend_cert' => 'Attestation of Attendance'`, `'template_testimonial' => 'Testimonial'`, `'template_char_cert' => 'Character Certificate'`.

`lang/fr/opes.php`: `'preview_certificates' => 'Prévisualiser un certificat'`, `'preview_not_issued' => "Une prévisualisation porte le filigrane SPÉCIMEN, ne comporte aucun numéro et n'est pas enregistrée au registre. Émettez le certificat depuis son propre écran une fois les informations vérifiées."`, `'preview_suffix' => '(aperçu)'`, `'template_bonafide' => "Attestation d'inscription"`, `'template_attend_cert' => 'Attestation de présence'`, `'template_testimonial' => 'Attestation de scolarité et de conduite'`, `'template_char_cert' => 'Certificat de bonne conduite'`.

- [ ] **Step 7: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students tests/Feature/Reporting tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 8: Build and commit**

```bash
npm run build
git add app/Modules/Reporting/Http/Controllers/DocumentPreviewController.php routes/web.php resources/views/livewire/students/show.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Students/DocumentPreviewRouteTest.php
git commit -m "feat(documents): preview certificates from the student Documents tab"
```

---
# Phase 5 — Dead buttons and inert tabs

**The scope, verified:** `Students\Livewire\Students\Show::DISABLED_TABS` lists **seven** inert tabs — `overview`, `academic_records`, `attendance`, `examinations`, `fees`, `discipline`, `activity_log` — on the platform's most-visited screen. Ten blade files contain `cursor-not-allowed` or `aria-disabled`: `components/pagination.blade.php` and `layouts/app.blade.php` (legitimate — a disabled pagination arrow and the shell's roadmap items), plus `academics/settings`, `accounting/journal-entries/form`, `dashboard`, `fees/cashier`, `guardians/show`, `students/index`, `students/show`, `users/index`.

**The rule, stated once and applied consistently:**

> **Implement the tab if its data already exists in another module and can be read through `DB::table`. Remove the control if the data does not exist yet.**
>
> A tab that renders a plausible empty grid is *worse* than one that says it is not here: the first reads as "this child has no marks", the second as "this is not built". But that reasoning — which the component's own docblock gives, and which was correct when it was written — has expired for six of the seven tabs, because Assessment, Attendance, Fees, Welfare/Discipline and the activity log all shipped in the phases since. Leaving a tab disabled *when the data exists* is now the same lie in the other direction: it tells the operator the platform cannot show them something it can.
>
> Where a tab is implemented and the child genuinely has no rows, it gets a **designed empty state naming the reason and offering the action** — never a blank grid, never a card reading "—".

**The audit first, because six of these tabs need cross-module reads and getting the table names wrong is the whole risk.**

### Task 33: Audit every inert control

**Files:**
- Create: `docs/superpowers/audits/2026-08-15-inert-controls.md`

- [ ] **Step 1: Enumerate them**

Run:

```bash
grep -rn "cursor-not-allowed\|aria-disabled" resources/views --include=*.blade.php
```

Expected: matches in the ten files named above. Record every one.

- [ ] **Step 2: Confirm each candidate tab's source table exists**

Run:

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan db:show --counts
```

Expected: a table list with row counts. Confirm the presence of `assessment_marks` (or whatever Assessment's mark table is actually named), `attendance_records`, `attendance_registers`, `fee_invoices`, `fee_payments`, `discipline_cases`, `student_activity_logs`, `examination_entries`. **Write the exact names you observe into the audit file** — every later task in this phase reads them, and a guessed table name is a runtime 500 on the most-visited screen in the product.

- [ ] **Step 3: Write the audit**

Create `docs/superpowers/audits/2026-08-15-inert-controls.md`:

```markdown
# Inert controls audit — 2026-08-15

## The rule

**Implement if the data exists in another module and is readable via
`DB::table`. Remove the control if the data does not exist yet.**

`Students\Show`'s docblock argued that a disabled tab is more honest than a
plausible empty grid, and it was right in Phase 2. Assessment, Attendance,
Fees, Welfare/Discipline and the activity log have all shipped since. Leaving
those tabs disabled is now the same lie pointing the other way: it tells the
operator the platform cannot show them something it can.

Where a tab is implemented and a child genuinely has no rows, it shows a
**designed empty state naming the reason and offering the action** — never a
blank grid, never a card reading "—".

## Student profile tabs

| Tab | Source | Verdict | Task |
|---|---|---|---|
| `overview` | Composed from the other tabs' counts | **Implement** | 34 |
| `academic_records` | Assessment marks + published report cards | **Implement** | 36 |
| `attendance` | `attendance_records` / `attendance_registers` | **Implement** | 35 |
| `examinations` | Examination entries and results | **Implement** | 36 |
| `fees` | Fee invoices and payments | **Implement** | 35 |
| `discipline` | Welfare discipline cases | **Implement** | 35 |
| `activity_log` | `student_activity_logs` | **Implement** | 37 |

`activity_log` was excluded originally because "nothing writes to it yet".
`Students\Actions\LogStudentActivity` exists and is called; the tab renders
whatever is there, with an empty state when there is nothing — which is now a
true statement about this child rather than a claim about the platform.

## Other inert controls

| File | Control | Verdict |
|---|---|---|
| `components/pagination.blade.php` | Disabled prev/next at the ends | **Keep** — a correct disabled state, not a dead button |
| `layouts/app.blade.php` | Sidebar "arrives later" nav items | **Keep** — the shell's documented roadmap treatment, permission-and-route-agree-by-construction |
| `livewire/students/show.blade.php` | "Upload document" | **Implement** in Task 38 — the Livewire upload pattern now exists (Phase 1) |
| `livewire/students/show.blade.php` | Seven inert tabs | **Implement** — Tasks 34–37 |
| `livewire/students/index.blade.php` | (record verdict after reading) | |
| `livewire/guardians/show.blade.php` | (record verdict after reading) | |
| `livewire/users/index.blade.php` | (record verdict after reading) | |
| `livewire/dashboard.blade.php` | (record verdict after reading) | Superseded by Phase 7 |
| `livewire/fees/cashier.blade.php` | (record verdict after reading) | |
| `livewire/academics/settings/*.blade.php` | (record verdict after reading) | |
| `livewire/accounting/journal-entries/form.blade.php` | (record verdict after reading) | |

## Confirmed table names

(Fill in from `artisan db:show --counts` in Step 2 — every task in this phase
reads them, and a guessed name is a 500 on the most-visited screen.)
```

- [ ] **Step 4: Fill in the blanks**

Open each of the six unrecorded blade files, read the disabled control in context, and write the verdict and one-line reason into the table. A control whose data does not exist is **removed**, not left disabled.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/audits/2026-08-15-inert-controls.md
git commit -m "docs(audit): record every inert control and its verdict"
```

---

### Task 34: The Overview tab

**Files:**
- Modify: `app/Modules/Students/Livewire/Students/Show.php`
- Modify: `resources/views/livewire/students/show.blade.php`
- Test: `tests/Feature/Students/StudentOverviewTabTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('lists overview among the live tabs', function (): void {
    expect(Show::LIVE_TABS)->toContain('overview')
        ->and(Show::DISABLED_TABS)->not->toContain('overview');
});

it('selects the overview tab', function (): void {
    p13coreUserAs(Role::Registrar);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', 'overview')
        ->assertSet('tab', 'overview')
        ->assertOk();
});

it('shows a designed empty state, never a zero, where a child has no records', function (): void {
    p13coreUserAs(Role::Registrar);

    // 09-ui 3.3: "no fee has been collected" and "the figure has not been
    // recorded" are different facts, and printing 0 for the second is how a
    // screen starts lying.
    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', 'overview')
        ->assertSee(__('opes.students_screen.overview_no_attendance'))
        ->assertDontSee('0%');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students/StudentOverviewTabTest.php`
Expected: FAIL — `overview` is in `DISABLED_TABS`.

- [ ] **Step 3: Move the tab and add its read model**

In `app/Modules/Students/Livewire/Students/Show.php`, change the two constants:

```php
    /**
     * The tabs backed by data that EXISTS. Assessment, Attendance, Fees,
     * Welfare/Discipline and the activity log all shipped after the original
     * four-tab decision was made; leaving their tabs inert now tells the
     * operator the platform cannot show them something it can.
     *
     * @var list<string>
     */
    public const LIVE_TABS = [
        'overview', 'general', 'guardians', 'documents', 'medical',
        'academic_records', 'attendance', 'examinations', 'fees', 'discipline', 'activity_log',
    ];

    /**
     * Nothing is inert any more. The constant stays so the blade's loop and
     * any external reference keep working, and so re-introducing an unbuilt
     * tab has an obvious home.
     *
     * @var list<string>
     */
    public const DISABLED_TABS = [];
```

Add the overview read model:

```php
    /**
     * The Overview tab's summary. Every figure is nullable and NULL means
     * "not recorded", never zero (09-ui 3.3): a child with no register taken
     * has not been absent, and a 0% attendance figure on a profile is how a
     * screen starts lying about a person.
     *
     * Query-builder reads across five modules - ModuleBoundaryTest forbids
     * this module from importing their Models, and permits exactly this.
     *
     * @return array{attendance_rate: string|null, marks_count: int, outstanding_balance: int|null, discipline_cases: int, documents: int}
     */
    private function overviewSummary(): array
    {
        $studentId = (int) $this->student->getKey();

        $enrollmentIds = DB::table('enrollments')->where('student_id', $studentId)->pluck('id');

        // Attendance: the §9.6 formula the Attendance module itself uses -
        // (present + late) over (expected - suspended), from SUBMITTED
        // registers only.
        $attendanceRate = null;

        if ($enrollmentIds->isNotEmpty()) {
            $counts = DB::table('attendance_records as r')
                ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
                ->whereIn('r.enrollment_id', $enrollmentIds)
                ->whereIn('reg.status', ['submitted', 'amended'])
                ->selectRaw("SUM(r.status = 'present') as present")
                ->selectRaw("SUM(r.status = 'late') as late")
                ->selectRaw("SUM(r.status <> 'suspended') as counted")
                ->first();

            $counted = (int) ($counts->counted ?? 0);

            if ($counted > 0) {
                $attendanceRate = number_format(
                    (((int) ($counts->present ?? 0) + (int) ($counts->late ?? 0)) / $counted) * 100,
                    1,
                ).'%';
            }
        }

        $outstanding = null;

        if ($enrollmentIds->isNotEmpty()) {
            $invoiced = (int) DB::table('fee_invoices')
                ->whereIn('enrollment_id', $enrollmentIds)
                ->whereIn('status', ['issued', 'part_paid', 'overdue'])
                ->sum('total_amount');

            $paid = (int) DB::table('fee_invoices')
                ->whereIn('enrollment_id', $enrollmentIds)
                ->whereIn('status', ['issued', 'part_paid', 'overdue'])
                ->sum('paid_amount');

            $outstanding = max(0, $invoiced - $paid);
        }

        return [
            'attendance_rate' => $attendanceRate,
            'marks_count' => $enrollmentIds->isEmpty() ? 0 : DB::table('assessment_marks')
                ->whereIn('enrollment_id', $enrollmentIds)->count(),
            'outstanding_balance' => $outstanding,
            'discipline_cases' => DB::table('discipline_cases')->where('student_id', $studentId)->count(),
            'documents' => $this->student->documents()->notArchived()->count(),
        ];
    }
```

and pass it from `render()`:

```php
            'overviewSummary' => $tab === 'overview' ? $this->overviewSummary() : null,
```

**Before running the test**, replace `assessment_marks`, `fee_invoices`, `discipline_cases` and the invoice status/amount column names with the exact names recorded in Task 33's audit. Getting one wrong is a 500 on the most-visited screen in the product.

- [ ] **Step 4: Render the tab**

In `resources/views/livewire/students/show.blade.php`, remove the `@foreach (StudentShow::DISABLED_TABS as $disabledTab)` block entirely (with `DISABLED_TABS` now empty it renders nothing, but leaving dead markup is how the next reader concludes the tabs are still inert), and add before the General block:

```blade
    {{-- ── Overview ────────────────────────────────────────────────────── --}}
    @if ($tab === 'overview')
        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-kpi-card :label="__('opes.students_screen.overview_attendance')"
                        :value="$overviewSummary['attendance_rate']"
                        :sub="$overviewSummary['attendance_rate'] === null ? __('opes.students_screen.overview_no_attendance') : null"
                        :href="route('students.show', ['student' => $student->id, 'tab' => 'attendance'])"
                        tone="green"/>

            <x-kpi-card :label="__('opes.students_screen.overview_marks')"
                        :value="$overviewSummary['marks_count'] > 0 ? $overviewSummary['marks_count'] : null"
                        :sub="$overviewSummary['marks_count'] === 0 ? __('opes.students_screen.overview_no_marks') : null"
                        :href="route('students.show', ['student' => $student->id, 'tab' => 'academic_records'])"
                        tone="blue"/>

            <x-kpi-card :label="__('opes.students_screen.overview_balance')"
                        :value="$overviewSummary['outstanding_balance'] === null ? null : number_format($overviewSummary['outstanding_balance'], 0, '.', ' ').' FCFA'"
                        :sub="$overviewSummary['outstanding_balance'] === null ? __('opes.students_screen.overview_no_fees') : null"
                        :href="route('students.show', ['student' => $student->id, 'tab' => 'fees'])"
                        tone="amber"/>

            <x-kpi-card :label="__('opes.students_screen.overview_discipline')"
                        :value="$overviewSummary['discipline_cases'] > 0 ? $overviewSummary['discipline_cases'] : null"
                        :sub="$overviewSummary['discipline_cases'] === 0 ? __('opes.students_screen.overview_no_discipline') : null"
                        :href="route('students.show', ['student' => $student->id, 'tab' => 'discipline'])"
                        tone="pink"/>

            <x-kpi-card :label="__('opes.students_screen.overview_documents')"
                        :value="$overviewSummary['documents'] > 0 ? $overviewSummary['documents'] : null"
                        :sub="$overviewSummary['documents'] === 0 ? __('opes.students_screen.overview_no_documents') : null"
                        :href="route('students.show', ['student' => $student->id, 'tab' => 'documents'])"
                        tone="purple"/>
        </div>
    @endif
```

- [ ] **Step 5: Add the lang keys**

`lang/en/opes.php` under `'students_screen'`: `'overview_attendance' => 'Attendance rate'`, `'overview_no_attendance' => 'No register taken yet'`, `'overview_marks' => 'Marks recorded'`, `'overview_no_marks' => 'No marks entered yet'`, `'overview_balance' => 'Outstanding fees'`, `'overview_no_fees' => 'No invoice issued yet'`, `'overview_discipline' => 'Discipline cases'`, `'overview_no_discipline' => 'No case recorded'`, `'overview_documents' => 'Documents on file'`, `'overview_no_documents' => 'No document uploaded'`, `'tab_overview' => 'Overview'` (keep the existing value if present).

`lang/fr/opes.php`: `'overview_attendance' => 'Taux de présence'`, `'overview_no_attendance' => "Aucun appel effectué"`, `'overview_marks' => 'Notes enregistrées'`, `'overview_no_marks' => 'Aucune note saisie'`, `'overview_balance' => 'Solde de scolarité'`, `'overview_no_fees' => "Aucune facture émise"`, `'overview_discipline' => 'Cas disciplinaires'`, `'overview_no_discipline' => 'Aucun cas enregistré'`, `'overview_documents' => 'Documents au dossier'`, `'overview_no_documents' => 'Aucun document téléversé'`.

- [ ] **Step 6: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students`
Expected: PASS. Existing tests that assert a disabled tab will fail — **update those assertions**, they are pinning the behaviour this task removes.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/Students/Livewire/Students/Show.php resources/views/livewire/students/show.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Students/StudentOverviewTabTest.php
git commit -m "feat(students): implement the Overview tab"
```

---

### Task 35: The Attendance, Fees and Discipline tabs

**Files:**
- Modify: `app/Modules/Students/Livewire/Students/Show.php`
- Modify: `resources/views/livewire/students/show.blade.php`
- Test: `tests/Feature/Students/StudentRecordTabsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('renders each record tab with a designed empty state when there is nothing', function (string $tab, string $emptyKey): void {
    p13coreUserAs(Role::Registrar);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', $tab)
        ->assertSet('tab', $tab)
        ->assertOk()
        ->assertSee(__($emptyKey));
})->with([
    ['attendance', 'opes.students_screen.attendance_empty'],
    ['fees', 'opes.students_screen.fees_empty'],
    ['discipline', 'opes.students_screen.discipline_empty'],
]);

it('caps each list and reports the true total beside the cap', function (): void {
    // 00-core 6.2 rule 8: no unbounded collection query in a view. The screen
    // already caps at TAB_LIST_LIMIT and reports the real total; the new tabs
    // must not be the exception.
    p13coreUserAs(Role::Registrar);

    $component = Livewire::test(Show::class, ['student' => Student::factory()->create()]);

    foreach (['attendance', 'fees', 'discipline'] as $tab) {
        $component->call('selectTab', $tab)->assertOk();
    }

    expect(Show::LIVE_TABS)->toContain('attendance', 'fees', 'discipline');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students/StudentRecordTabsTest.php`
Expected: FAIL — the empty-state strings do not exist and the tabs render nothing.

- [ ] **Step 3: Add the three read models**

In `Show.php`:

```php
    /**
     * The student's attendance rows, newest first. Bounded by
     * TAB_LIST_LIMIT with the true total reported beside it (00-core 6.2
     * rule 8) rather than carrying a third paginator onto this screen.
     *
     * SUBMITTED registers only, matching what the Attendance module itself
     * shows: a draft register is a teacher's working state, not a fact about
     * a child.
     *
     * @return Collection<int, \stdClass>
     */
    private function attendanceRows(): Collection
    {
        return DB::table('attendance_records as r')
            ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
            ->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'reg.class_group_id')
            ->where('e.student_id', $this->student->getKey())
            ->whereIn('reg.status', ['submitted', 'amended'])
            ->orderByDesc('reg.date')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['r.id', 'reg.date', 'r.status', 'r.remark', 'cg.name as class_name'])
            ->get();
    }

    private function attendanceTotal(): int
    {
        return DB::table('attendance_records as r')
            ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
            ->join('enrollments as e', 'e.id', '=', 'r.enrollment_id')
            ->where('e.student_id', $this->student->getKey())
            ->whereIn('reg.status', ['submitted', 'amended'])
            ->count();
    }

    /**
     * Fee invoices and their settlement state. Amounts are integer minor
     * units throughout this platform (NumericPolicyTest), formatted in the
     * view, never divided here.
     *
     * @return Collection<int, \stdClass>
     */
    private function feeRows(): Collection
    {
        return DB::table('fee_invoices as i')
            ->join('enrollments as e', 'e.id', '=', 'i.enrollment_id')
            ->where('e.student_id', $this->student->getKey())
            ->orderByDesc('i.issued_on')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['i.id', 'i.invoice_no', 'i.issued_on', 'i.due_on', 'i.status',
                'i.total_amount', 'i.paid_amount'])
            ->get();
    }

    private function feeTotal(): int
    {
        return DB::table('fee_invoices as i')
            ->join('enrollments as e', 'e.id', '=', 'i.enrollment_id')
            ->where('e.student_id', $this->student->getKey())
            ->count();
    }

    /**
     * Discipline cases. Gated on the caller's own discipline permission
     * rather than on students.view: a conduct record is not ordinary
     * directory data, and a front-desk clerk looking up a phone number has no
     * business reading it.
     *
     * @return Collection<int, \stdClass>
     */
    private function disciplineRows(): Collection
    {
        if (! Gate::allows(Permission::DisciplineView->value)) {
            return new Collection();
        }

        return DB::table('discipline_cases')
            ->where('student_id', $this->student->getKey())
            ->orderByDesc('occurred_on')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['id', 'reference', 'occurred_on', 'category', 'severity', 'status', 'summary'])
            ->get();
    }

    public function canViewDiscipline(): bool
    {
        return Gate::allows(Permission::DisciplineView->value);
    }
```

and in `render()`:

```php
            'attendanceRows' => $tab === 'attendance' ? $this->attendanceRows() : new Collection(),
            'attendanceTotal' => $tab === 'attendance' ? $this->attendanceTotal() : 0,
            'feeRows' => $tab === 'fees' ? $this->feeRows() : new Collection(),
            'feeTotal' => $tab === 'fees' ? $this->feeTotal() : 0,
            'disciplineRows' => $tab === 'discipline' ? $this->disciplineRows() : new Collection(),
            'canViewDiscipline' => $this->canViewDiscipline(),
```

**Reconcile every table and column name against Task 33's audit before running.** `Permission::DisciplineView` must exist — check with `grep -n "DisciplineView\|DisciplineManage" app/Modules/Identity/Domain/Permission.php` and use whatever read permission is actually defined.

- [ ] **Step 4: Render the three tabs**

Append to `resources/views/livewire/students/show.blade.php`:

```blade
    {{-- ── Attendance ──────────────────────────────────────────────────── --}}
    @if ($tab === 'attendance')
        @if ($attendanceRows->isEmpty())
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.attendance_empty')"/>
            </div>
        @else
            <div class="mt-4 min-w-0 overflow-x-auto rounded-xl border border-border-primary bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-sand text-xs uppercase tracking-wide text-charcoal/60">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.attendance_date') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.attendance_class') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.attendance_status') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.attendance_remark') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($attendanceRows as $row)
                            <tr>
                                <td class="px-4 py-2">{{ $row->date }}</td>
                                <td class="px-4 py-2">{{ $row->class_name ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <x-status-pill :status="$row->status"/>
                                </td>
                                <td class="px-4 py-2 text-charcoal/70">{{ $row->remark ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($attendanceTotal > $listLimit)
                <p class="mt-2 text-xs text-text-secondary">
                    {{ __('opes.students_screen.showing_capped', ['shown' => $listLimit, 'total' => $attendanceTotal]) }}
                </p>
            @endif
        @endif
    @endif

    {{-- ── Fees ────────────────────────────────────────────────────────── --}}
    @if ($tab === 'fees')
        @if ($feeRows->isEmpty())
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.fees_empty')"/>
            </div>
        @else
            <div class="mt-4 min-w-0 overflow-x-auto rounded-xl border border-border-primary bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-sand text-xs uppercase tracking-wide text-charcoal/60">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.fees_invoice') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.fees_issued') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.fees_status') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('opes.students_screen.fees_total') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('opes.students_screen.fees_balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($feeRows as $row)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row->invoice_no }}</td>
                                <td class="px-4 py-2">{{ $row->issued_on }}</td>
                                <td class="px-4 py-2"><x-status-pill :status="$row->status"/></td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ number_format((int) $row->total_amount, 0, '.', ' ') }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums font-medium">
                                    {{ number_format(max(0, (int) $row->total_amount - (int) $row->paid_amount), 0, '.', ' ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($feeTotal > $listLimit)
                <p class="mt-2 text-xs text-text-secondary">
                    {{ __('opes.students_screen.showing_capped', ['shown' => $listLimit, 'total' => $feeTotal]) }}
                </p>
            @endif
        @endif
    @endif

    {{-- ── Discipline ──────────────────────────────────────────────────── --}}
    @if ($tab === 'discipline')
        @if (! $canViewDiscipline)
            {{-- Said, not hidden: a conduct record is not ordinary directory
                 data, and an operator who cannot see it should know it exists
                 rather than conclude the child has a clean record. --}}
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.discipline_forbidden')"/>
            </div>
        @elseif ($disciplineRows->isEmpty())
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.discipline_empty')"/>
            </div>
        @else
            <div class="mt-4 space-y-3">
                @foreach ($disciplineRows as $row)
                    <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs text-charcoal/60">{{ $row->reference }}</span>
                            <x-status-pill :status="$row->status"/>
                            <span class="text-xs text-text-secondary">{{ $row->occurred_on }}</span>
                        </div>
                        <p class="mt-2 text-sm font-medium text-charcoal">{{ $row->category }} · {{ $row->severity }}</p>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->summary }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
```

- [ ] **Step 5: Add the lang keys**

`lang/en/opes.php` under `'students_screen'`: `'attendance_empty' => 'No register has been taken for this student yet.'`, `'attendance_date' => 'Date'`, `'attendance_class' => 'Class'`, `'attendance_status' => 'Status'`, `'attendance_remark' => 'Remark'`, `'fees_empty' => 'No invoice has been issued to this student yet.'`, `'fees_invoice' => 'Invoice'`, `'fees_issued' => 'Issued'`, `'fees_status' => 'Status'`, `'fees_total' => 'Total'`, `'fees_balance' => 'Balance'`, `'discipline_empty' => 'No discipline case has been recorded for this student.'`, `'discipline_forbidden' => 'Discipline records are restricted. Ask a discipline master if you need this information.'`, `'showing_capped' => 'Showing the most recent :shown of :total.'`.

`lang/fr/opes.php`: `'attendance_empty' => "Aucun appel n'a encore été fait pour cet élève."`, `'attendance_date' => 'Date'`, `'attendance_class' => 'Classe'`, `'attendance_status' => 'Statut'`, `'attendance_remark' => 'Observation'`, `'fees_empty' => "Aucune facture n'a encore été émise à cet élève."`, `'fees_invoice' => 'Facture'`, `'fees_issued' => 'Émise le'`, `'fees_status' => 'Statut'`, `'fees_total' => 'Total'`, `'fees_balance' => 'Solde'`, `'discipline_empty' => "Aucun cas disciplinaire n'a été enregistré pour cet élève."`, `'discipline_forbidden' => "Les dossiers disciplinaires sont restreints. Adressez-vous au surveillant général."`, `'showing_capped' => 'Affichage des :shown plus récents sur :total.'`.

- [ ] **Step 6: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/Students/Livewire/Students/Show.php resources/views/livewire/students/show.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Students/StudentRecordTabsTest.php
git commit -m "feat(students): implement the Attendance, Fees and Discipline tabs"
```

---

### Task 36: The Academic Records and Examinations tabs

**Files:**
- Modify: `app/Modules/Students/Livewire/Students/Show.php`
- Modify: `resources/views/livewire/students/show.blade.php`
- Test: `tests/Feature/Students/StudentAcademicTabsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('renders the academic records tab with an empty state', function (): void {
    p13coreUserAs(Role::Registrar);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', 'academic_records')
        ->assertOk()
        ->assertSee(__('opes.students_screen.academic_empty'));
});

it('renders the examinations tab with an empty state', function (): void {
    p13coreUserAs(Role::Registrar);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', 'examinations')
        ->assertOk()
        ->assertSee(__('opes.students_screen.examinations_empty'));
});

it('shows only PUBLISHED results, never a draft mark', function (): void {
    // A mark a teacher has not submitted is a working figure. Showing it on a
    // student profile - where a guardian may be looking over a shoulder -
    // publishes it by accident.
    expect(Show::LIVE_TABS)->toContain('academic_records', 'examinations');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students/StudentAcademicTabsTest.php`
Expected: FAIL — the empty-state strings do not exist.

- [ ] **Step 3: Add the read models**

In `Show.php`:

```php
    /**
     * Published report cards - the academic record a school will actually
     * stand behind. Report-card snapshots exist only for PUBLISHED periods,
     * which is what makes this the right source: an unpublished period's
     * marks are a working figure, and showing them on a profile a guardian
     * may be reading over a shoulder publishes them by accident.
     *
     * @return Collection<int, \stdClass>
     */
    private function academicRows(): Collection
    {
        return DB::table('report_card_snapshots as s')
            ->join('enrollments as e', 'e.id', '=', 's.enrollment_id')
            ->leftJoin('assessment_periods as p', 'p.id', '=', 's.assessment_period_id')
            ->where('e.student_id', $this->student->getKey())
            ->orderByDesc('s.id')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['s.id', 's.created_at', 'p.name as period_name', 's.snapshot_version'])
            ->get();
    }

    /**
     * Examination entries and their published results.
     *
     * @return Collection<int, \stdClass>
     */
    private function examinationRows(): Collection
    {
        return DB::table('examination_entries as x')
            ->join('enrollments as e', 'e.id', '=', 'x.enrollment_id')
            ->leftJoin('examinations as ex', 'ex.id', '=', 'x.examination_id')
            ->where('e.student_id', $this->student->getKey())
            ->orderByDesc('ex.starts_on')
            ->limit(self::TAB_LIST_LIMIT)
            ->select(['x.id', 'ex.name as examination_name', 'ex.starts_on', 'x.status', 'x.result'])
            ->get();
    }
```

and in `render()`:

```php
            'academicRows' => $tab === 'academic_records' ? $this->academicRows() : new Collection(),
            'examinationRows' => $tab === 'examinations' ? $this->examinationRows() : new Collection(),
```

**Reconcile `report_card_snapshots`, `assessment_periods`, `examination_entries` and `examinations` (and their columns) against Task 33's audit before running.** `report_card_snapshots` is confirmed to exist — `P13CoreHelpers::p13coreSnapshotRow()` inserts into it — but `examination_entries` is not; if it does not exist, **remove the examinations tab from `LIVE_TABS` instead of inventing a source**, and record that in the audit. That is the rule this phase is built on.

- [ ] **Step 4: Render the tabs**

Append to `resources/views/livewire/students/show.blade.php`:

```blade
    {{-- ── Academic records ────────────────────────────────────────────── --}}
    @if ($tab === 'academic_records')
        @if ($academicRows->isEmpty())
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.academic_empty')"/>
            </div>
        @else
            <div class="mt-4 space-y-2">
                @foreach ($academicRows as $row)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-charcoal">{{ $row->period_name ?? '—' }}</p>
                            <p class="text-xs text-text-secondary">
                                {{ __('opes.students_screen.academic_published', ['date' => $row->created_at]) }}
                            </p>
                        </div>
                        @can('documents.print')
                            <a href="{{ route('documents.preview', [
                                   'template' => 'RPT-CARD',
                                   'subjectType' => 'Enrollment',
                                   'subjectId' => $student->id,
                                   'snapshotId' => $row->id,
                               ]) }}" target="_blank" rel="noopener"
                               class="rounded-lg border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal transition hover:border-primary hover:bg-sand">
                                {{ __('opes.students_screen.preview_suffix') }}
                            </a>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ── Examinations ────────────────────────────────────────────────── --}}
    @if ($tab === 'examinations')
        @if ($examinationRows->isEmpty())
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.examinations_empty')"/>
            </div>
        @else
            <div class="mt-4 min-w-0 overflow-x-auto rounded-xl border border-border-primary bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-sand text-xs uppercase tracking-wide text-charcoal/60">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.examinations_name') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.examinations_date') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.examinations_status') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('opes.students_screen.examinations_result') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($examinationRows as $row)
                            <tr>
                                <td class="px-4 py-2">{{ $row->examination_name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->starts_on ?? '—' }}</td>
                                <td class="px-4 py-2"><x-status-pill :status="$row->status"/></td>
                                <td class="px-4 py-2">{{ $row->result ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
```

- [ ] **Step 5: Add the lang keys**

`lang/en/opes.php`: `'academic_empty' => 'No report card has been published for this student yet.'`, `'academic_published' => 'Published :date'`, `'examinations_empty' => 'This student is not entered for any examination.'`, `'examinations_name' => 'Examination'`, `'examinations_date' => 'Date'`, `'examinations_status' => 'Status'`, `'examinations_result' => 'Result'`.

`lang/fr/opes.php`: `'academic_empty' => "Aucun bulletin n'a encore été publié pour cet élève."`, `'academic_published' => 'Publié le :date'`, `'examinations_empty' => "Cet élève n'est inscrit à aucun examen."`, `'examinations_name' => 'Examen'`, `'examinations_date' => 'Date'`, `'examinations_status' => 'Statut'`, `'examinations_result' => 'Résultat'`.

- [ ] **Step 6: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/Students/Livewire/Students/Show.php resources/views/livewire/students/show.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Students/StudentAcademicTabsTest.php
git commit -m "feat(students): implement the Academic Records and Examinations tabs"
```

---

### Task 37: The Activity Log tab

**Files:**
- Modify: `app/Modules/Students/Livewire/Students/Show.php`
- Modify: `resources/views/livewire/students/show.blade.php`
- Test: `tests/Feature/Students/StudentActivityLogTabTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Actions\LogStudentActivity;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('shows an empty state for a student with no logged activity', function (): void {
    p13coreUserAs(Role::Registrar);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->call('selectTab', 'activity_log')
        ->assertOk()
        ->assertSee(__('opes.students_screen.activity_empty'));
});

it('lists logged activity newest first', function (): void {
    p13coreUserAs(Role::Registrar);

    $student = Student::factory()->create();

    // Written through the module's own Action, never a raw insert: the tab
    // must render what the platform actually writes.
    app(LogStudentActivity::class)->handle((int) $student->getKey(), 'profile_updated', 'Phone number corrected.');

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'activity_log')
        ->assertOk()
        ->assertSee('Phone number corrected.');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students/StudentActivityLogTabTest.php`
Expected: FAIL — the empty-state string does not exist.

**Before Step 3**, run `grep -n "function handle" -A 12 app/Modules/Students/Actions/LogStudentActivity.php` and adjust the test's call to that Action's real signature. Do not change the Action to fit the test.

- [ ] **Step 3: Add the read model**

In `Show.php`:

```php
    /**
     * The student activity log (07-students 8.3).
     *
     * This tab was excluded from the original four on the reasoning that
     * "nothing writes to student_activity_logs yet", and an always-empty log
     * presented as a feature claims a completeness the module does not have.
     * LogStudentActivity now exists and is called, so the log renders what is
     * there - and an empty one is now a true statement about THIS CHILD
     * rather than a claim about the platform, which is exactly the
     * distinction the original decision was protecting.
     *
     * @return Collection<int, StudentActivityLog>
     */
    private function activityRows(): Collection
    {
        return StudentActivityLog::query()
            ->where('student_id', $this->student->getKey())
            ->orderByDesc('created_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }
```

with `use App\Modules\Students\Models\StudentActivityLog;` — this is a Students-owned model, so Eloquent is correct here and no boundary rule is in play. **Confirm the class exists** with `ls app/Modules/Students/Models/ | grep -i activity`; if it does not, use `DB::table('student_activity_logs')` instead.

In `render()`:

```php
            'activityRows' => $tab === 'activity_log' ? $this->activityRows() : new Collection(),
```

- [ ] **Step 4: Render the tab**

```blade
    {{-- ── Activity log ────────────────────────────────────────────────── --}}
    @if ($tab === 'activity_log')
        @if ($activityRows->isEmpty())
            <div class="mt-4">
                <x-empty-state :message="__('opes.students_screen.activity_empty')"/>
            </div>
        @else
            <ol class="mt-4 space-y-2">
                @foreach ($activityRows as $entry)
                    <li class="flex gap-3 rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-charcoal">
                                {{ __('opes.students_screen.activity_'.$entry->event, [], null) }}
                            </p>
                            <p class="text-sm text-charcoal/70">{{ $entry->description }}</p>
                            <p class="mt-0.5 text-xs text-text-secondary">{{ $entry->created_at }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    @endif
```

- [ ] **Step 5: Add the lang keys**

`lang/en/opes.php`: `'activity_empty' => 'Nothing has been recorded against this student yet.'`, plus one key per event in the taxonomy `LogStudentActivity` actually writes — read them from that Action and add `'activity_<event>' => '…'` for each (e.g. `'activity_profile_updated' => 'Profile updated'`, `'activity_enrolled' => 'Enrolled'`, `'activity_withdrawn' => 'Withdrawn'`).

`lang/fr/opes.php`: `'activity_empty' => "Rien n'a encore été enregistré pour cet élève."`, plus the French label for each event key added above.

- [ ] **Step 6: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 7: Build and commit**

```bash
npm run build
git add app/Modules/Students/Livewire/Students/Show.php resources/views/livewire/students/show.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Students/StudentActivityLogTabTest.php
git commit -m "feat(students): implement the Activity Log tab"
```

---

### Task 38: Make the "Upload document" button real

**Files:**
- Modify: `app/Modules/Students/Livewire/Students/Show.php`
- Modify: `resources/views/livewire/students/show.blade.php`
- Test: `tests/Feature/Students/StudentDocumentUploadTest.php`

The blade's own comment says the control is inert "rather than a file input that would write somewhere unspecified". Phase 1 specified exactly where.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('stores an uploaded student document and lists it', function (): void {
    p13coreUserAs(Role::Registrar);

    $student = Student::factory()->create();

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'documents')
        ->set('documentUpload', UploadedFile::fake()->create('birth-certificate.pdf', 120, 'application/pdf'))
        ->set('documentTitle', 'Birth certificate')
        ->call('saveDocument')
        ->assertHasNoErrors()
        ->assertSee('Birth certificate');
});

it('refuses a document without a title', function (): void {
    p13coreUserAs(Role::Registrar);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->set('documentUpload', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
        ->set('documentTitle', '')
        ->call('saveDocument')
        ->assertHasErrors('documentTitle');
});

it('refuses a caller who may not manage students', function (): void {
    p13coreUserAs(Role::Teacher);

    Livewire::test(Show::class, ['student' => Student::factory()->create()])
        ->set('documentUpload', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
        ->set('documentTitle', 'Something')
        ->call('saveDocument')
        ->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students/StudentDocumentUploadTest.php`
Expected: FAIL — `Unable to set component property [documentUpload]`.

- [ ] **Step 3: Read `StudentDocument` before writing anything**

Run: `$PHP artisan db:table student_documents` and `grep -n "fillable" -A 15 app/Modules/Students/Models/StudentDocument.php`.
Record the real column names; Step 4's `create()` array must match them exactly.

- [ ] **Step 4: Add the upload**

In `Show.php`, add `use Livewire\WithFileUploads;` and `use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;`, add `use WithFileUploads;` to the class body, then:

```php
    public ?TemporaryUploadedFile $documentUpload = null;

    public string $documentTitle = '';

    /**
     * Attach a document to the student record. The control was inert with a
     * comment saying a file input "would write somewhere unspecified"; the
     * Phase 1 upload work specified it - the `public` disk, under a
     * per-student directory, with the same size and type discipline the
     * branding uploads use.
     *
     * PDFs and images only: this is a birth certificate or a transfer letter,
     * not an arbitrary file store, and an unrestricted upload on a
     * registrar's screen is the widest attack surface in the product.
     */
    public function saveDocument(): void
    {
        Gate::authorize(Permission::StudentsManage->value);

        $this->validate([
            'documentUpload' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'documentTitle' => ['required', 'string', 'max:160'],
        ], [
            'documentUpload.required' => (string) __('opes.students_screen.document_required'),
            'documentUpload.mimes' => (string) __('opes.students_screen.document_wrong_type'),
            'documentUpload.max' => (string) __('opes.students_screen.document_too_large'),
            'documentTitle.required' => (string) __('opes.students_screen.document_title_required'),
        ]);

        $upload = $this->documentUpload;

        if (! $upload instanceof TemporaryUploadedFile) {
            return;
        }

        $path = $upload->store('student-documents/'.$this->student->getKey(), 'public');

        StudentDocument::query()->create([
            'student_id' => (int) $this->student->getKey(),
            'title' => $this->documentTitle,
            'file_path' => $path,
            'mime_type' => $upload->getMimeType(),
            'size_bytes' => $upload->getSize(),
            'uploaded_by' => (int) auth()->id(),
        ]);

        $upload->delete();
        $this->reset(['documentUpload', 'documentTitle']);

        session()->flash('status', __('opes.students_screen.document_saved'));
    }
```

- [ ] **Step 5: Replace the inert control**

In `resources/views/livewire/students/show.blade.php`, replace the `<span aria-disabled="true" title="{{ __('opes.students_screen.upload_disabled') }}" …>` block (and its stale comment) with:

```blade
                @can('students.manage')
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-charcoal">{{ __('opes.students_screen.document_title') }}</span>
                            <input type="text" wire:model="documentTitle"
                                   class="rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        </label>
                        <input type="file" wire:model="documentUpload" accept=".pdf,image/png,image/jpeg,image/webp"
                               class="block text-sm text-charcoal file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90">
                        <button type="button" wire:click="saveDocument"
                                class="rounded-lg border border-primary bg-primary px-3 py-2 text-sm font-medium text-white transition hover:bg-primary/90">
                            {{ __('opes.students_screen.upload_document') }}
                        </button>
                    </div>
                    @error('documentUpload') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror
                    @error('documentTitle') <p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p> @enderror
                @endcan
```

- [ ] **Step 6: Add the lang keys**

`lang/en/opes.php`: `'document_title' => 'Title'`, `'document_saved' => 'Document attached.'`, `'document_required' => 'Choose a file to attach.'`, `'document_wrong_type' => 'Attach a PDF or an image.'`, `'document_too_large' => 'That file is larger than 5 MB.'`, `'document_title_required' => 'Give the document a title.'`. Remove the now-unused `'upload_disabled'` key from **both** lang files.

`lang/fr/opes.php`: `'document_title' => 'Intitulé'`, `'document_saved' => 'Document joint.'`, `'document_required' => 'Choisissez un fichier à joindre.'`, `'document_wrong_type' => 'Joignez un PDF ou une image.'`, `'document_too_large' => 'Ce fichier dépasse 5 Mo.'`, `'document_title_required' => 'Donnez un intitulé au document.'`.

- [ ] **Step 7: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Students tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 8: Build and commit**

```bash
npm run build
git add app/Modules/Students/Livewire/Students/Show.php resources/views/livewire/students/show.blade.php lang/en/opes.php lang/fr/opes.php tests/Feature/Students/StudentDocumentUploadTest.php
git commit -m "feat(students): make the document upload control real"
```

---

# Phase 6 — The unused paper sizes

**The evidence, gathered from `docs/specs/10-documents.md`:** `PaperSize` defines `A4, A5, A3, CR80, LETTER, POS80`. Before this plan, only A4, A5 and POS80 were used by the 16 registered templates. Phase 3 put **CR80** to work on the asset label. That leaves **A3** and **LETTER**.

`grep -n "A3\|LETTER" docs/specs/10-documents.md` returns **six** documents specified as *A3 landscape*: §6.3 the per-sequence broadsheet, §7 the seating plan, the admission register, the class register, an HR staff list, and §9.1's configurable tabular report. **LETTER appears only in the `paper_size` enum line (§101) and nowhere in any document specification.**

### Task 39: Wire A3 and record the LETTER decision

**Files:**
- Create: `database/migrations/2026_08_15_440010_seed_broadsheet_template.php`
- Create: `resources/views/documents/assessment/broadsheet.blade.php`
- Create: `docs/superpowers/audits/2026-08-15-paper-sizes.md`
- Test: `tests/Feature/Reporting/PaperSizeCoverageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\PaperSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers the class broadsheet at A3 landscape', function (): void {
    $row = DB::table('document_templates')->where('code', 'BROADSHEET')->first();

    expect($row)->not->toBeNull()
        ->and($row->paper_size)->toBe('A3')
        ->and($row->orientation)->toBe('landscape')
        ->and((bool) $row->bulk_printable)->toBeTrue();
});

it('leaves no paper size defined-but-unused except LETTER', function (): void {
    // A size in the enum that no template uses is either a gap or dead code.
    // This test forces the question to be answered rather than accumulated.
    $used = DB::table('document_templates')->distinct()->pluck('paper_size')->all();

    $unused = array_values(array_diff(
        array_map(static fn (PaperSize $size): string => $size->value, PaperSize::cases()),
        array_map(static fn (mixed $size): string => (string) $size, $used),
    ));

    // LETTER is deliberately retained and unused - see
    // docs/superpowers/audits/2026-08-15-paper-sizes.md. Anything ELSE
    // showing up here is an unanswered question.
    expect($unused)->toBe(['LETTER']);
});

it('gives A3 a real points box through dompdf', function (): void {
    expect(PaperSize::A3->dompdfSize())->toBe('a3')
        ->and(PaperSize::Letter->dompdfSize())->toBe('letter');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/PaperSizeCoverageTest.php`
Expected: FAIL — no `BROADSHEET` row, and the unused list contains `A3` as well as `LETTER`.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §6.3 - the class broadsheet: every student down
 * the page, every subject across it, one row per child.
 *
 * A3 LANDSCAPE, which the spec states explicitly ("A3 landscape variant for
 * wide per-sequence francophone bulletins", §6.3 line 285) and which A4
 * genuinely cannot do: a francophone secondary class carries 12-16 subjects
 * plus coefficients, and on A4 the columns fall below the width at which a
 * printed figure can be read.
 *
 * A3 has been a PaperSize case since the platform shipped and was used by
 * NOTHING; this is the document it was defined for.
 *
 * LIVE, not snapshot-backed: a broadsheet is a class master's working sheet
 * during a marking period, reprinted whenever marks change, and the "Generated
 * on" footer says so. The archival record of a period's marks is the report
 * card snapshot (§6.1), which is snapshot-backed and already registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_templates')->insert([
            'code' => 'BROADSHEET',
            'name' => 'Class Broadsheet',
            'name_fr' => 'Tableau de notes de la classe',
            'module' => 'Assessment',
            'paper_size' => 'A3',
            'orientation' => 'landscape',
            'duplex' => 'none',
            'series_code' => null,
            'is_snapshot_backed' => false,
            'snapshot_source' => null,
            'carries_qr' => false,
            'carries_barcode' => false,
            'signature_roles' => json_encode(['class_master', 'principal'], JSON_THROW_ON_ERROR),
            'state_header' => 'optional',
            'min_phase' => 'v1',
            'bulk_printable' => true,
            'blade_view' => 'documents.assessment.broadsheet',
            'version' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->where('code', 'BROADSHEET')->delete();
    }
};
```

- [ ] **Step 4: Write the broadsheet template**

Create `resources/views/documents/assessment/broadsheet.blade.php`:

```blade
{{-- BROADSHEET, A3 landscape (§6.3). Students down, subjects across.

     Extends the shared shell so it carries the letterhead, the watermark
     layers and the page footer like every other document; only the table is
     specific to it. Type is 7pt and padding is tight because the point of A3
     here is to fit 16 subject columns at a size a human can still read - not
     to have more white space than A4. --}}
@extends('documents.layout')

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <div class="doc-block doc-center">
        <div style="font-size: 12pt; font-weight: bold;">
            {{ __('documents.assessment.broadsheet_title', [], $document['language']) }}
        </div>
        <div class="doc-muted">
            {{ $subject['label'] }}
            @if (!empty($payload['period_name'])) · {{ $payload['period_name'] }} @endif
        </div>
    </div>

    <table style="font-size: 7pt;">
        <thead>
            <tr>
                <th style="border: 0.5pt solid #333; padding: 2pt; text-align: left;">
                    {{ __('documents.assessment.broadsheet_student', [], $document['language']) }}
                </th>
                @foreach ($payload['subjects'] as $subjectColumn)
                    <th style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                        {{ $subjectColumn['short_name'] ?? $subjectColumn['name'] }}
                        @if (!empty($subjectColumn['coefficient']))
                            <br><span style="font-weight: normal;">×{{ $subjectColumn['coefficient'] }}</span>
                        @endif
                    </th>
                @endforeach
                <th style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                    {{ __('documents.assessment.broadsheet_average', [], $document['language']) }}
                </th>
                <th style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                    {{ __('documents.assessment.broadsheet_rank', [], $document['language']) }}
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payload['students'] as $student)
                <tr>
                    <td style="border: 0.5pt solid #333; padding: 2pt;">{{ $student['name'] }}</td>
                    @foreach ($payload['subjects'] as $subjectColumn)
                        <td style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                            {{-- An em dash, never a 0: "not marked" and "scored
                                 nothing" are different facts about a child. --}}
                            {{ $student['marks'][$subjectColumn['code']] ?? '—' }}
                        </td>
                    @endforeach
                    <td style="border: 0.5pt solid #333; padding: 2pt; text-align: center; font-weight: bold;">
                        {{ $student['average'] ?? '—' }}
                    </td>
                    <td style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                        {{ $student['rank'] ?? '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('documents.blocks.signature_block')
@endsection
```

**Check the `state_header` block's real filename first** — `ls resources/views/documents/blocks/` — and use whatever it is actually called; the include above assumes `state_header.blade.php`.

- [ ] **Step 5: Add the document strings**

`lang/en/documents.php` under a new `'assessment'` key (or the existing one): `'broadsheet_title' => 'Class Broadsheet'`, `'broadsheet_student' => 'Student'`, `'broadsheet_average' => 'Average'`, `'broadsheet_rank' => 'Rank'`.

`lang/fr/documents.php`: `'broadsheet_title' => 'Tableau de notes de la classe'`, `'broadsheet_student' => 'Élève'`, `'broadsheet_average' => 'Moyenne'`, `'broadsheet_rank' => 'Rang'`.

- [ ] **Step 6: Record the LETTER decision**

Create `docs/superpowers/audits/2026-08-15-paper-sizes.md`:

```markdown
# Paper size coverage — 2026-08-15

`App\Modules\Reporting\Domain\PaperSize` defines six sizes. Before this work
three were used by the 16 registered templates.

| Size | Status | Used by |
|---|---|---|
| A4 | In use | Most of the catalogue |
| A5 | In use | Compact receipts / vouchers |
| POS80 | In use | `FEE-RECEIPT-POS`, the 80 mm thermal receipt |
| **CR80** | **Now in use** | `ASSET-LABEL` (Phase 3) — the ID-card blank is exactly what a stick-on asset label is |
| **A3** | **Now in use** | `BROADSHEET` (Phase 6) — `docs/specs/10-documents.md` §6.3 specifies A3 landscape explicitly |
| LETTER | **Retained, deliberately unused** | — |

## Why A3 was wired

`grep -n "A3" docs/specs/10-documents.md` returns six documents specified as
A3 landscape: §6.3 the per-sequence broadsheet, §7 the seating plan, the
admission register, the class register, an HR staff list, and §9.1's
configurable tabular report. The broadsheet is the one whose data exists
today, so it is the one registered; the other five adopt the size without a
further migration when their modules reach them.

A3 is not cosmetic here. A francophone secondary class carries 12–16 subjects
plus coefficients; on A4 those columns fall below the width at which a printed
figure can be read.

## Why LETTER stays, unused

LETTER appears in `10-documents.md` **only** in the `paper_size` enum
declaration (§101) and in **no document specification at all**. That is
consistent with the product's market: Cameroon is an ISO-216 (A-series)
country, and every statutory document this platform prints — the ministry
header block, the tax attestations, the bulletins — is specified against A-series
sizes.

It is retained rather than removed because:

1. The `document_templates.paper_size` MySQL enum already contains it.
   Dropping a value from a MySQL enum is a table rebuild, on a column every
   document render reads, to remove something that costs nothing.
2. It is the escape hatch for a deployment outside the A-series world (a
   partner school, a US-accredited international section). A school that needs
   it changes one column; without the case it would need a migration and a
   deploy.

`tests/Feature/Reporting/PaperSizeCoverageTest.php` pins this: LETTER is the
**only** size permitted to be defined-but-unused. Any other size that becomes
unused fails that test, which forces the question to be answered rather than
accumulated.
```

- [ ] **Step 7: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Reporting/PaperSizeCoverageTest.php tests/Feature/LocalisationTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_15_440010_seed_broadsheet_template.php resources/views/documents/assessment/broadsheet.blade.php docs/superpowers/audits/2026-08-15-paper-sizes.md lang/en/documents.php lang/fr/documents.php tests/Feature/Reporting/PaperSizeCoverageTest.php
git commit -m "feat(documents): wire A3 for the class broadsheet and record the LETTER decision"
```

---
# Phase 7 — Role dashboards

**What exists, verified:** ONE shared screen for all 20 roles — `app/Modules/Operations/Livewire/Dashboard.php` (285 lines) plus `resources/views/livewire/dashboard.blade.php` (196 lines). Its five tiles are admin-centric (active users, roles configured, system health, last backup, today's attendance), each gated on `UserView` / `SettingView` / `BackupRun` / `AttendanceView`.

**The consequence, confirmed by audit:**
- **An Accountant lands on a dashboard with ZERO KPI cards** — `$tileCount` is 0, so the whole strip is hidden.
- **A Teacher gets one full-width card reading "—" plus a raw `LedgerIntegrityCheck` authorization exception rendered on screen.**

Gating admin tiles away from other roles was correct; the mistake was having nothing to put in their place. **The fix is role-appropriate content, not more gating.**

**What is kept:** the component already has the right bones — `quickActions()` is permission-filtered, `alerts()` is permission-filtered, `lastBackupAge()`, `todaysAttendanceRate()`, `signedInAs()` all work, and the blade already composes `x-kpi-card` with the `tone` system. Phase 7 extends that, it does not replace it.

**Module boundaries:** the dashboard lives in Operations and reads across Students, Assessment, Attendance, Fees, Accounting, Library, Welfare, Inventory, HR and Payroll. Every one of those reads is `DB::table` — `tests/Architecture/ModuleBoundaryTest.php` forbids importing another module's Model and permits exactly this.

## The design specification, in numbers

Everything below is expressed in the Phase 0b tokens. At the **17px root**, do not infer pixel sizes from Tailwind utility names.

**Page rhythm (desktop, ≥1280px):** page padding `--space-6` (24px); the greeting block, then a `--space-8` (32px) gap to the KPI grid, `--space-8` to the workflow section, `--space-8` to quick actions, `--space-8` to alerts.

**KPI grid:** `grid gap-4` (16px, `--space-4`); 4 columns at ≥1280px, 2 at ≥768px, 1 below. **Never more than 6 KPI cards for any role** — beyond six the eye stops reading and starts scanning, and the seventh is worse than absent.

**Card anatomy** (this is `x-kpi-card`, already built; these are the values it uses and must keep):
- Padding `px-4 py-3.5` (16px / 14px), radius `--radius-card` (12px), shadow `--shadow-e1` at rest and `--shadow-e2` on hover for a linked card, with a `-1px` translate.
- Icon badge: 44×44px circle (`h-11 w-11`) — which is also `--tap-target`, so a linked card's badge is a legal touch target. Icon inside: 20px, stroke 1.6, `currentColor`.
- Label: `--text-xs`, 600 weight, uppercase, `tracking-wide`, `charcoal/55`, in a **fixed two-line box** so a one-line label does not lift its numeral 20–40px above its neighbours'. This is already implemented and must not be "simplified" away.
- Value: `--text-2xl` (33.2px), 700 weight, `tracking-tight`, charcoal. **Null renders an em dash with an `aria` label, never `0`.**
- Sub-line: `--text-xs`, `charcoal/55`.
- Surface: the KPI wash for its tone (`--color-kpi-green` etc., ~4% saturation, contrast-asserted in Task 13).

**Tone assignment is semantic, not decorative:** green = a healthy count, blue = a neutral count, amber = something due, pink = something overdue or a risk, purple = a reference/archive count. Four identical-looking cards with four different badge colours is the bug the `tone` system was added to fix.

**Section headings:** `--text-sm`, 600, uppercase, `tracking-wide`, `charcoal/70`, with `--space-3` below.

**Quick-action cards:** a 3-across grid at ≥1024px, 2 at ≥640px, 1 below; each is a `--radius-card` bordered white surface, `--space-4` padding, `--shadow-e1`, hover to `--shadow-e2` with the border going `--color-primary`; a 40×40 icon badge, a 600-weight `--text-base` label, and an `--text-sm` `text-secondary` one-liner saying what it does. Minimum height 88px so a one-word and a two-line card match.

**Responsive:** 1440 → 4 KPI columns, 3 quick-action columns, sidebar visible. 768 → 2 KPI columns, 2 quick-action columns, sidebar collapsed to the drawer. 375 → 1 column throughout, cards full-bleed to the `--space-4` page padding, every tappable control ≥ `--tap-target`.

**The empty-state rule, applied per role:** where a role's dashboard would have no data at all, it renders **a designed empty state naming the reason and offering its primary action** — never a blank grid, never a card reading "—" with no explanation.

---

### Task 40: `RoleDashboard` — the per-role composition

**Files:**
- Create: `app/Modules/Operations/Domain/RoleDashboard.php`
- Test: `tests/Unit/Operations/RoleDashboardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Operations\Domain\RoleDashboard;

it('covers every role in the enum', function (): void {
    // A role with no entry lands on the empty dashboard this phase exists to
    // remove, so "we forgot one" must be a test failure, not a support call.
    foreach (Role::cases() as $role) {
        expect(RoleDashboard::for($role))->not->toBeNull("role [{$role->value}] has no dashboard profile");
    }
});

it('gives the portal roles no staff dashboard at all', function (): void {
    // Guardian and StaffPortal have their own portals and are aborted at
    // mount(); a staff profile for them would be dead configuration that
    // someone later "fixes" by removing the abort.
    expect(RoleDashboard::for(Role::Guardian)['panels'])->toBe([])
        ->and(RoleDashboard::for(Role::StaffPortal)['panels'])->toBe([]);
});

it('never gives a role more than six KPI panels', function (): void {
    foreach (Role::cases() as $role) {
        expect(count(RoleDashboard::for($role)['panels']))
            ->toBeLessThanOrEqual(6, "role [{$role->value}] has too many KPI panels to read");
    }
});

it('gives an accountant a finance-shaped dashboard', function (): void {
    $panels = RoleDashboard::for(Role::Accountant)['panels'];

    // The defect this phase fixes: an Accountant used to land on a dashboard
    // with ZERO KPI cards.
    expect($panels)->not->toBe([])
        ->and($panels)->toContain('unposted_entries', 'open_periods');
});

it('gives a teacher a teaching-shaped dashboard', function (): void {
    $panels = RoleDashboard::for(Role::Teacher)['panels'];

    expect($panels)->toContain('my_classes', 'registers_not_taken', 'marks_due')
        // A Teacher has no business reading the ledger; this is the other
        // half of the defect - a raw LedgerIntegrityCheck authorization
        // exception used to render on their dashboard.
        ->and($panels)->not->toContain('unposted_entries');
});

it('names a permission for every panel and every quick action', function (): void {
    foreach (Role::cases() as $role) {
        $profile = RoleDashboard::for($role);

        foreach ($profile['panels'] as $panel) {
            expect(RoleDashboard::panelPermission($panel))
                ->toBeString("panel [{$panel}] has no permission");
        }
    }
});

it('gives every non-portal role at least one quick action', function (): void {
    foreach (Role::cases() as $role) {
        if (in_array($role, [Role::Guardian, Role::StaffPortal], true)) {
            continue;
        }

        expect(RoleDashboard::for($role)['quick_actions'])
            ->not->toBe([], "role [{$role->value}] has nothing it can do from its own dashboard");
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Operations/RoleDashboardTest.php`
Expected: FAIL — `Class "App\Modules\Operations\Domain\RoleDashboard" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use App\Modules\Identity\Domain\Role;

/**
 * WHICH dashboard each role lands on.
 *
 * One dashboard for twenty roles was the original design, and it produced two
 * concrete defects: an Accountant landed on a screen with ZERO KPI cards
 * (every tile was gated on an identity or operations permission they do not
 * hold), and a Teacher landed on one card reading "—" beside a raw
 * LedgerIntegrityCheck authorization exception. Gating the admin tiles away
 * was right; having nothing to put in their place was not.
 *
 * This class is the map from role to CONTENT. It is pure metadata - the
 * component does the reading, because these panels cross ten modules and
 * DomainPurityTest keeps database access out of Domain.
 *
 * Two rules the tests enforce:
 *   - every Role case has an entry, so "we forgot one" is a red test rather
 *     than a support call from the one deployment that granted it;
 *   - at most SIX panels per role. Past six the eye stops reading a KPI strip
 *     and starts scanning it, and the seventh card is worse than absent.
 *
 * Every panel and every quick action names the permission that opens it, and
 * the component filters on that permission. A card that 403s when clicked is
 * worse than no card: it teaches the operator that the screen lies.
 */
final class RoleDashboard
{
    /**
     * panel key => the permission required to see it.
     *
     * @var array<string, string>
     */
    private const PANEL_PERMISSIONS = [
        // Identity / operations
        'active_users' => 'user.view',
        'roles_configured' => 'user.view',
        'system_health' => 'setting.view',
        'last_backup' => 'backup.run',
        'go_live_blockers' => 'setting.view',
        // Students / admissions
        'enrolment_count' => 'students.view',
        'admissions_pipeline' => 'admissions.view',
        'documents_pending' => 'students.view',
        // Teaching
        'my_classes' => 'timetable.view',
        'my_timetable_today' => 'timetable.view',
        'registers_not_taken' => 'attendance.view',
        'marks_due' => 'assessment.enter_marks',
        'attendance_rate' => 'attendance.view',
        // Assessment administration
        'periods_open' => 'assessment.view',
        'unpublished_periods' => 'assessment.publish',
        'examination_entries' => 'assessment.view',
        // Money
        'todays_collections' => 'fee.view',
        'unpaid_invoices' => 'fee.view',
        'cash_desk_state' => 'fee.collect',
        'aged_receivables' => 'fee.view',
        'cash_position' => 'ledger.view',
        'unposted_entries' => 'ledger.view',
        'open_periods' => 'ledger.view',
        // Procurement / stores
        'stock_below_reorder' => 'inventory.view',
        'pending_receipts' => 'procurement.view',
        'open_requisitions' => 'procurement.view',
        // HR / payroll
        'staff_count' => 'hr.view',
        'payroll_run_state' => 'payroll.view',
        'declarations_due' => 'payroll.view',
        // Welfare
        'open_discipline_cases' => 'discipline.view',
        'todays_consultations' => 'medical.view',
        'open_referrals' => 'medical.view',
        // Library
        'books_on_loan' => 'library.view',
        'overdue_loans' => 'library.view',
        'fines_due' => 'library.view',
        // Assets
        'assets_in_service' => 'asset.view',
        'maintenance_open' => 'asset.view',
    ];

    /**
     * quick-action key => [route name, permission].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const QUICK_ACTIONS = [
        'take_register' => ['attendance.take', 'attendance.take'],
        'enter_marks' => ['assessment.marks-entry', 'assessment.enter_marks'],
        'my_timetable' => ['timetable.index', 'timetable.view'],
        'collect_fees' => ['fees.cashier', 'fee.collect'],
        'new_invoice' => ['fees.invoices.index', 'fee.manage'],
        'record_payment' => ['fees.cashier', 'fee.collect'],
        'new_journal_entry' => ['accounting.journal-entries.index', 'ledger.post'],
        'trial_balance' => ['accounting.reports.trial-balance', 'ledger.view'],
        'new_admission' => ['admissions.wizard', 'admissions.manage'],
        'find_student' => ['students.index', 'students.view'],
        'new_requisition' => ['procurement.requisitions.index', 'procurement.manage'],
        'receive_goods' => ['procurement.goods-receipts.index', 'procurement.manage'],
        'stock_levels' => ['inventory.index', 'inventory.view'],
        'run_payroll' => ['payroll.index', 'payroll.manage'],
        'staff_directory' => ['hr.index', 'hr.view'],
        'log_consultation' => ['welfare.medical.index', 'medical.manage'],
        'log_discipline_case' => ['welfare.discipline.index', 'discipline.manage'],
        'library_desk' => ['library.index', 'library.view'],
        'asset_register' => ['assets.index', 'asset.view'],
        'add_user' => ['users.index', 'user.manage'],
        'go_live_setup' => ['operations.setup', 'setting.view'],
        'settings' => ['settings.index', 'setting.view'],
        'reports' => ['reports.hub', 'report.view'],
    ];

    /**
     * @return array{panels: list<string>, quick_actions: list<string>}
     */
    public static function for(Role $role): array
    {
        return match ($role) {
            // Portal principals never reach this screen (Dashboard::mount
            // aborts 403); an empty profile keeps that fact stated in one
            // more place rather than leaving dead configuration behind.
            Role::Guardian, Role::StaffPortal => ['panels' => [], 'quick_actions' => []],

            Role::SuperAdmin, Role::Administrator => [
                'panels' => ['active_users', 'system_health', 'last_backup', 'go_live_blockers', 'enrolment_count', 'cash_position'],
                'quick_actions' => ['add_user', 'go_live_setup', 'settings', 'reports'],
            ],

            Role::Principal, Role::VicePrincipal => [
                'panels' => ['enrolment_count', 'attendance_rate', 'unpaid_invoices', 'open_discipline_cases', 'unpublished_periods', 'go_live_blockers'],
                'quick_actions' => ['find_student', 'reports', 'log_discipline_case', 'settings'],
            ],

            Role::Registrar, Role::FrontDesk => [
                'panels' => ['admissions_pipeline', 'enrolment_count', 'documents_pending', 'attendance_rate'],
                'quick_actions' => ['new_admission', 'find_student', 'reports'],
            ],

            Role::Bursar => [
                'panels' => ['todays_collections', 'cash_desk_state', 'unpaid_invoices', 'aged_receivables', 'cash_position'],
                'quick_actions' => ['collect_fees', 'record_payment', 'new_invoice', 'reports'],
            ],

            Role::Accountant => [
                'panels' => ['cash_position', 'unposted_entries', 'open_periods', 'aged_receivables', 'unpaid_invoices'],
                'quick_actions' => ['new_journal_entry', 'trial_balance', 'reports'],
            ],

            Role::HrOfficer => [
                'panels' => ['staff_count', 'payroll_run_state', 'declarations_due'],
                'quick_actions' => ['staff_directory', 'reports'],
            ],

            Role::PayrollOfficer => [
                'panels' => ['payroll_run_state', 'declarations_due', 'staff_count'],
                'quick_actions' => ['run_payroll', 'reports'],
            ],

            Role::ExamsOfficer => [
                'panels' => ['periods_open', 'unpublished_periods', 'examination_entries', 'marks_due'],
                'quick_actions' => ['enter_marks', 'reports'],
            ],

            Role::ClassMaster => [
                'panels' => ['my_classes', 'registers_not_taken', 'marks_due', 'attendance_rate', 'open_discipline_cases'],
                'quick_actions' => ['take_register', 'enter_marks', 'my_timetable', 'find_student'],
            ],

            Role::Teacher => [
                'panels' => ['my_classes', 'my_timetable_today', 'registers_not_taken', 'marks_due'],
                'quick_actions' => ['take_register', 'enter_marks', 'my_timetable'],
            ],

            Role::DisciplineMaster => [
                'panels' => ['open_discipline_cases', 'attendance_rate', 'enrolment_count'],
                'quick_actions' => ['log_discipline_case', 'find_student'],
            ],

            Role::Librarian => [
                'panels' => ['books_on_loan', 'overdue_loans', 'fines_due'],
                'quick_actions' => ['library_desk', 'find_student'],
            ],

            Role::StoreKeeper => [
                'panels' => ['stock_below_reorder', 'pending_receipts', 'open_requisitions', 'assets_in_service', 'maintenance_open'],
                'quick_actions' => ['stock_levels', 'receive_goods', 'new_requisition', 'asset_register'],
            ],

            Role::Nurse => [
                'panels' => ['todays_consultations', 'open_referrals'],
                'quick_actions' => ['log_consultation', 'find_student'],
            ],

            Role::WelfareOfficer => [
                'panels' => ['open_referrals', 'open_discipline_cases', 'attendance_rate'],
                'quick_actions' => ['log_consultation', 'log_discipline_case', 'find_student'],
            ],
        };
    }

    public static function panelPermission(string $panel): ?string
    {
        return self::PANEL_PERMISSIONS[$panel] ?? null;
    }

    /**
     * @return array{0: string, 1: string}|null  [route name, permission]
     */
    public static function quickAction(string $key): ?array
    {
        return self::QUICK_ACTIONS[$key] ?? null;
    }
}
```

- [ ] **Step 4: Run the test and reconcile the permission strings**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Unit/Operations/RoleDashboardTest.php`
Expected: PASS, 7 tests.

Then reconcile **every** permission string above against the real enum:

Run: `$PHP -r "require 'vendor/autoload.php'; foreach (App\Modules\Identity\Domain\Permission::cases() as \$c) echo \$c->value, PHP_EOL;" | sort`

Expected: a list of permission strings. **Every value in `PANEL_PERMISSIONS` and `QUICK_ACTIONS` must appear in it.** Correct any that do not — a permission string the Gate has never heard of makes `Gate::allows()` return false, and the panel silently never renders for anyone, which is exactly the empty dashboard this phase is fixing.

- [ ] **Step 5: Reconcile the route names**

Run: `$PHP artisan route:list --columns=name | sort`
Expected: every route name in `QUICK_ACTIONS` appears. Correct any that do not.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Operations/Domain/RoleDashboard.php tests/Unit/Operations/RoleDashboardTest.php
git commit -m "feat(dashboard): map each role to its own KPI panels and quick actions"
```

---

### Task 41: `DashboardPanels` — the reads

**Files:**
- Create: `app/Modules/Operations/Actions/ReadDashboardPanels.php`
- Test: `tests/Feature/Operations/ReadDashboardPanelsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Operations\Actions\ReadDashboardPanels;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('returns a value, label key, tone, icon and route for a panel', function (): void {
    p13coreUserAs(Role::Principal);

    $panel = app(ReadDashboardPanels::class)->read('enrolment_count');

    expect($panel)->toHaveKeys(['key', 'value', 'sub', 'tone', 'icon', 'route']);
});

it('returns NULL as the value where nothing has been recorded, never zero', function (): void {
    // 09-ui 3.3: "no fee has been collected" and "the figure has not been
    // recorded" are different facts. A dashboard that prints 0 for the second
    // is lying, and it is the kind of lie nobody detects.
    p13coreUserAs(Role::Bursar);

    expect(app(ReadDashboardPanels::class)->read('todays_collections')['value'])->toBeNull();
});

it('returns null for a panel the caller may not see', function (): void {
    p13coreUserAs(Role::Teacher);

    expect(app(ReadDashboardPanels::class)->read('unposted_entries'))->toBeNull();
});

it('returns null for an unknown panel rather than throwing', function (): void {
    p13coreUserAs(Role::Principal);

    expect(app(ReadDashboardPanels::class)->read('not_a_panel'))->toBeNull();
});

it('reads every panel any role can be given without throwing', function (): void {
    // The whole risk of this Action is a wrong table or column name in one of
    // ~35 cross-module reads. This test walks all of them.
    p13coreUserAs(Role::SuperAdmin);

    $reader = app(ReadDashboardPanels::class);

    foreach (App\Modules\Identity\Domain\Role::cases() as $role) {
        foreach (App\Modules\Operations\Domain\RoleDashboard::for($role)['panels'] as $panel) {
            $reader->read($panel);
        }
    }
})->throwsNoExceptions();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Operations/ReadDashboardPanelsTest.php`
Expected: FAIL — `Target class [App\Modules\Operations\Actions\ReadDashboardPanels] does not exist.`

- [ ] **Step 3: Write the Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\RoleDashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Reads one dashboard panel's figure.
 *
 * Every read is a query builder read: this Action sits in Operations and
 * touches ten other modules, and ModuleBoundaryTest forbids importing their
 * Models while permitting exactly this.
 *
 * NULL, NOT ZERO. Each read returns null when the underlying thing has not
 * been recorded, and only returns 0 when zero is the true, measured answer.
 * "No register has been taken today" and "every child was absent" are
 * different facts about a school (09-ui §3.3), and x-kpi-card renders null as
 * an em dash with a screen-reader label rather than printing a figure the
 * operator cannot tell apart from a real one.
 *
 * Permission is checked HERE as well as in the component: a panel is a read
 * of another module's data, and a caller who may not open that module may not
 * read its summary either.
 */
final class ReadDashboardPanels
{
    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}|null
     */
    public function read(string $panel): ?array
    {
        $permission = RoleDashboard::panelPermission($panel);

        if ($permission === null || ! Gate::allows($permission)) {
            return null;
        }

        return match ($panel) {
            'active_users' => $this->panel($panel, DB::table('users')->whereNull('deleted_at')->count(), 'blue', 'staff', 'users.index'),
            'roles_configured' => $this->panel($panel, DB::table('roles')->count(), 'blue', 'staff', 'users.index'),
            'go_live_blockers' => $this->blockers(),
            'enrolment_count' => $this->panel($panel, DB::table('enrollments')->whereIn('status', ['pending', 'active', 'suspended'])->count(), 'green', 'students', 'students.index'),
            'admissions_pipeline' => $this->panel($panel, DB::table('admissions')->whereNotIn('status', ['enrolled', 'rejected', 'withdrawn'])->count(), 'blue', 'admissions', 'admissions.index'),
            'documents_pending' => $this->panel($panel, DB::table('student_documents')->whereNull('verified_at')->count(), 'amber', 'system_documentation', 'students.index'),
            'my_classes' => $this->myClasses(),
            'my_timetable_today' => $this->myTimetableToday(),
            'registers_not_taken' => $this->registersNotTaken(),
            'marks_due' => $this->marksDue(),
            'attendance_rate' => $this->attendanceRate(),
            'periods_open' => $this->panel($panel, DB::table('assessment_periods')->where('is_open', true)->count(), 'blue', 'results', 'assessment.results.index'),
            'unpublished_periods' => $this->panel($panel, DB::table('assessment_periods')->where('is_open', false)->whereNotExists(
                static fn ($q) => $q->select(DB::raw(1))->from('period_publications')
                    ->whereColumn('period_publications.assessment_period_id', 'assessment_periods.id'),
            )->count(), 'amber', 'results', 'assessment.results.index'),
            'examination_entries' => $this->panel($panel, DB::table('examinations')->count(), 'blue', 'examinations', 'assessment.examinations.index'),
            'todays_collections' => $this->todaysCollections(),
            'unpaid_invoices' => $this->panel($panel, DB::table('fee_invoices')->whereIn('status', ['issued', 'part_paid', 'overdue'])->count(), 'amber', 'finance', 'fees.invoices.index'),
            'cash_desk_state' => $this->cashDeskState(),
            'aged_receivables' => $this->agedReceivables(),
            'cash_position' => $this->cashPosition(),
            'unposted_entries' => $this->panel($panel, DB::table('journal_entries')->where('status', 'draft')->count(), 'amber', 'ledger', 'accounting.journal-entries.index'),
            'open_periods' => $this->panel($panel, DB::table('fiscal_years')->where('status', 'open')->count(), 'blue', 'ledger', 'accounting.year-end.console'),
            'stock_below_reorder' => $this->panel($panel, DB::table('inventory_items')->whereColumn('quantity_on_hand', '<', 'reorder_level')->count(), 'pink', 'assets', 'inventory.index'),
            'pending_receipts' => $this->panel($panel, DB::table('purchase_orders')->whereIn('status', ['approved', 'part_received'])->count(), 'amber', 'assets', 'procurement.goods-receipts.index'),
            'open_requisitions' => $this->panel($panel, DB::table('requisitions')->whereIn('status', ['draft', 'submitted', 'approved'])->count(), 'blue', 'assets', 'procurement.requisitions.index'),
            'staff_count' => $this->panel($panel, DB::table('staff_members')->where('status', 'active')->count(), 'green', 'staff', 'hr.index'),
            'payroll_run_state' => $this->payrollRunState(),
            'declarations_due' => $this->panel($panel, DB::table('payroll_runs')->where('status', 'posted')->whereNull('declared_at')->count(), 'amber', 'payroll', 'payroll.index'),
            'open_discipline_cases' => $this->panel($panel, DB::table('discipline_cases')->whereNotIn('status', ['closed', 'dismissed'])->count(), 'pink', 'students', 'welfare.discipline.index'),
            'todays_consultations' => $this->panel($panel, DB::table('medical_visits')->whereDate('occurred_at', today())->count(), 'green', 'students', 'welfare.medical.index'),
            'open_referrals' => $this->panel($panel, DB::table('medical_visits')->whereNotNull('referred_to')->whereNull('resolved_at')->count(), 'amber', 'students', 'welfare.medical.index'),
            'books_on_loan' => $this->panel($panel, DB::table('library_loans')->whereNull('returned_on')->count(), 'blue', 'books', 'library.index'),
            'overdue_loans' => $this->panel($panel, DB::table('library_loans')->whereNull('returned_on')->whereDate('due_on', '<', today())->count(), 'pink', 'books', 'library.index'),
            'fines_due' => $this->panel($panel, (int) DB::table('library_loans')->whereNull('fine_paid_at')->sum('fine_amount'), 'amber', 'books', 'library.index'),
            'assets_in_service' => $this->panel($panel, DB::table('assets')->where('status', 'in_service')->count(), 'green', 'assets', 'assets.index'),
            'maintenance_open' => $this->panel($panel, DB::table('asset_maintenance_requests')->whereNotIn('status', ['closed', 'cancelled'])->count(), 'amber', 'assets', 'assets.index'),
            // System health has its own display slot on the blade, so it
            // carries a string rather than a numeral.
            'system_health' => $this->panel($panel, null, 'green', 'setup', null),
            'last_backup' => $this->panel($panel, null, 'amber', 'setup', 'operations.backups.index'),
            default => null,
        };
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function panel(string $key, int|string|null $value, string $tone, string $icon, ?string $routeName, ?string $sub = null): array
    {
        return [
            'key' => $key,
            // Zero is a real, measured answer for a COUNT and is shown as 0.
            // The null-not-zero rule applies to figures that were never
            // recorded - see the per-panel readers below.
            'value' => $value,
            'sub' => $sub,
            'tone' => $tone,
            'icon' => $icon,
            // Never offer a link to a route that does not exist yet: the
            // card would 404 on click, which is worse than a card that is
            // not clickable.
            'route' => $routeName !== null && Route::has($routeName) ? $routeName : null,
        ];
    }

    private function blockers(): array
    {
        $count = DB::table('setup_checklist_items')->where('is_complete', false)->count();

        return $this->panel('go_live_blockers', $count, $count === 0 ? 'green' : 'pink', 'setup', 'operations.setup');
    }

    private function myClasses(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('my_classes', null, 'blue', 'classes', 'timetable.index');
        }

        $count = DB::table('timetable_lessons')
            ->where('staff_member_id', $staffId)
            ->distinct()
            ->count('class_group_id');

        return $this->panel('my_classes', $count, 'blue', 'classes', 'timetable.index');
    }

    private function myTimetableToday(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('my_timetable_today', null, 'green', 'timetable', 'timetable.index');
        }

        $count = DB::table('timetable_lessons')
            ->where('staff_member_id', $staffId)
            ->where('day_of_week', (int) today()->dayOfWeekIso)
            ->count();

        return $this->panel('my_timetable_today', $count, 'green', 'timetable', 'timetable.index');
    }

    private function registersNotTaken(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('registers_not_taken', null, 'amber', 'attendance', 'attendance.index');
        }

        $expected = DB::table('timetable_lessons')
            ->where('staff_member_id', $staffId)
            ->where('day_of_week', (int) today()->dayOfWeekIso)
            ->pluck('class_group_id')
            ->unique();

        if ($expected->isEmpty()) {
            // No lessons today: "nothing to take" is not "you are behind".
            return $this->panel('registers_not_taken', null, 'green', 'attendance', 'attendance.index');
        }

        $taken = DB::table('attendance_registers')
            ->whereDate('date', today())
            ->whereIn('status', ['submitted', 'amended'])
            ->whereIn('class_group_id', $expected)
            ->pluck('class_group_id')
            ->unique();

        $outstanding = $expected->diff($taken)->count();

        return $this->panel('registers_not_taken', $outstanding, $outstanding === 0 ? 'green' : 'amber', 'attendance', 'attendance.index');
    }

    private function marksDue(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('marks_due', null, 'amber', 'results', 'assessment.marks-entry');
        }

        $count = DB::table('assessment_periods')->where('is_open', true)->count();

        return $this->panel('marks_due', $count, $count === 0 ? 'green' : 'amber', 'results', 'assessment.marks-entry');
    }

    private function attendanceRate(): array
    {
        $registerIds = DB::table('attendance_registers')
            ->whereDate('date', today())
            ->whereIn('status', ['submitted', 'amended'])
            ->pluck('id');

        if ($registerIds->isEmpty()) {
            // NULL, not 0%: zero registers is "not yet taken", not "nobody
            // came" (07-students §9, C5).
            return $this->panel('attendance_rate', null, 'green', 'attendance', 'attendance.index');
        }

        $totals = DB::table('attendance_registers')
            ->whereIn('id', $registerIds)
            ->selectRaw('SUM(expected_count) as expected, SUM(present_count) as present, SUM(late_count) as late')
            ->first();

        $suspended = DB::table('attendance_records')
            ->whereIn('attendance_register_id', $registerIds)
            ->where('status', 'suspended')
            ->count();

        $denominator = (int) ($totals->expected ?? 0) - $suspended;

        if ($denominator <= 0) {
            return $this->panel('attendance_rate', null, 'green', 'attendance', 'attendance.index');
        }

        $rate = ((int) ($totals->present ?? 0) + (int) ($totals->late ?? 0)) / $denominator * 100;

        return $this->panel(
            'attendance_rate',
            number_format($rate, 1).'%',
            $rate >= 90 ? 'green' : ($rate >= 75 ? 'amber' : 'pink'),
            'attendance',
            'attendance.index',
        );
    }

    private function todaysCollections(): array
    {
        $taken = DB::table('fee_payments')->whereDate('received_on', today())->exists();

        if (! $taken) {
            // No payment recorded today is NOT "zero francs collected" until
            // the desk has been opened - and printing 0 FCFA on a bursar's
            // dashboard at 08:00 reads as a bad day, not an empty one.
            return $this->panel('todays_collections', null, 'green', 'finance', 'fees.cashier');
        }

        $total = (int) DB::table('fee_payments')->whereDate('received_on', today())->sum('amount');

        return $this->panel(
            'todays_collections',
            number_format($total, 0, '.', ' ').' FCFA',
            'green',
            'finance',
            'fees.cashier',
        );
    }

    private function cashDeskState(): array
    {
        $open = DB::table('cash_desk_sessions')->whereNull('closed_at')->count();

        return $this->panel(
            'cash_desk_state',
            $open,
            $open === 0 ? 'blue' : 'green',
            'finance',
            'fees.cashier',
        );
    }

    private function agedReceivables(): array
    {
        $overdue = (int) DB::table('fee_invoices')
            ->whereIn('status', ['issued', 'part_paid', 'overdue'])
            ->whereDate('due_on', '<', today())
            ->sum(DB::raw('total_amount - paid_amount'));

        return $this->panel(
            'aged_receivables',
            number_format(max(0, $overdue), 0, '.', ' ').' FCFA',
            $overdue > 0 ? 'pink' : 'green',
            'finance',
            'fees.invoices.index',
        );
    }

    private function cashPosition(): array
    {
        $balance = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->where('a.is_cash_or_bank', true)
            ->sum(DB::raw('jl.debit - jl.credit'));

        return $this->panel(
            'cash_position',
            number_format((int) $balance, 0, '.', ' ').' FCFA',
            (int) $balance >= 0 ? 'green' : 'pink',
            'finance_dashboard',
            'accounting.reports.trial-balance',
        );
    }

    private function payrollRunState(): array
    {
        $status = DB::table('payroll_runs')->orderByDesc('id')->value('status');

        return $this->panel(
            'payroll_run_state',
            is_string($status) ? $status : null,
            'blue',
            'payroll',
            'payroll.index',
        );
    }

    /**
     * The staff_members row for the signed-in user, or null when the account
     * is not linked to one (an administrator account, a service account).
     */
    private function currentStaffId(): ?int
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        $id = DB::table('staff_members')->where('user_id', $userId)->value('id');

        return $id === null ? null : (int) $id;
    }
}
```

- [ ] **Step 4: Reconcile every table and column**

This Action makes ~35 cross-module reads and **the whole risk of Phase 7 is a wrong table or column name.** Before running the test:

Run: `$PHP artisan db:show --counts`

Check **every** table named above exists: `users`, `roles`, `setup_checklist_items`, `enrollments`, `admissions`, `student_documents`, `timetable_lessons`, `attendance_registers`, `attendance_records`, `assessment_periods`, `period_publications`, `examinations`, `fee_invoices`, `fee_payments`, `cash_desk_sessions`, `journal_entries`, `journal_lines`, `accounts`, `fiscal_years`, `inventory_items`, `purchase_orders`, `requisitions`, `staff_members`, `payroll_runs`, `discipline_cases`, `medical_visits`, `library_loans`, `assets`, `asset_maintenance_requests`.

For each that exists, run `$PHP artisan db:table <name>` and confirm the columns used. **Correct the query to match reality; never invent a column.** Where a table genuinely does not exist, delete that panel from `RoleDashboard` too, so the role gets one fewer card rather than a 500.

- [ ] **Step 5: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Operations/ReadDashboardPanelsTest.php`
Expected: PASS, 5 tests. The last test is the one that catches a wrong name — read its exception message and fix the query it names.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Operations/Actions/ReadDashboardPanels.php tests/Feature/Operations/ReadDashboardPanelsTest.php
git commit -m "feat(dashboard): read every role dashboard panel"
```

---

### Task 42: Compose the dashboard per role

**Files:**
- Modify: `app/Modules/Operations/Livewire/Dashboard.php`
- Test: `tests/Feature/Operations/RoleDashboardScreenTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('gives an accountant a dashboard with cards on it', function (): void {
    // The defect: an Accountant used to land on a page with ZERO KPI cards,
    // because every tile was gated on an identity or operations permission
    // they do not hold.
    p13coreUserAs(Role::Accountant);

    get('/dashboard')
        ->assertOk()
        ->assertSee(__('opes.dashboard.panel_cash_position'))
        ->assertSee(__('opes.dashboard.panel_unposted_entries'));
});

it('gives a teacher a teaching dashboard and no ledger error', function (): void {
    // The other half: a Teacher used to get one card reading "—" plus a raw
    // LedgerIntegrityCheck authorization exception rendered on the page.
    p13coreUserAs(Role::Teacher);

    $response = get('/dashboard')->assertOk();

    $response->assertSee(__('opes.dashboard.panel_my_classes'))
        ->assertDontSee('LedgerIntegrityCheck')
        ->assertDontSee('This action is unauthorized');
});

it('gives a nurse a welfare dashboard', function (): void {
    p13coreUserAs(Role::Nurse);

    get('/dashboard')->assertOk()->assertSee(__('opes.dashboard.panel_todays_consultations'));
});

it('gives a librarian a library dashboard', function (): void {
    p13coreUserAs(Role::Librarian);

    get('/dashboard')->assertOk()->assertSee(__('opes.dashboard.panel_books_on_loan'));
});

it('never renders a card the role cannot open', function (): void {
    p13coreUserAs(Role::Librarian);

    get('/dashboard')
        ->assertOk()
        ->assertDontSee(__('opes.dashboard.panel_unposted_entries'))
        ->assertDontSee(__('opes.dashboard.panel_active_users'));
});

it('renders a designed empty state rather than a blank grid', function (): void {
    // A role whose every panel is permission-filtered away still gets a
    // screen that says something and offers something.
    p13coreUserAs(Role::Teacher);

    get('/dashboard')->assertOk()->assertSee(__('opes.dashboard.greeting_role'));
});

it('still refuses a portal principal', function (): void {
    p13coreUserAs(Role::Guardian);

    get('/dashboard')->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Operations/RoleDashboardScreenTest.php`
Expected: FAIL — the Accountant sees no cards.

- [ ] **Step 3: Add the role composition to the component**

In `app/Modules/Operations/Livewire/Dashboard.php`, add the imports `use App\Modules\Operations\Actions\ReadDashboardPanels;`, `use App\Modules\Operations\Domain\RoleDashboard;` and `use Illuminate\Support\Facades\Route;`, then:

```php
    /**
     * The signed-in user's role, for dashboard composition.
     *
     * A user holding several roles gets the UNION of their panels, capped at
     * six and de-duplicated in panel order: a vice-principal who also teaches
     * needs both halves, and picking one role arbitrarily would hide half
     * their job from them.
     */
    private function dashboardRoles(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $roles = [];

        foreach ($user->getRoleNames() as $name) {
            $role = Role::tryFrom((string) $name);

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * The panels this user actually sees: their roles' union, permission
     * filtered by ReadDashboardPanels (which returns null for a panel the
     * caller may not read), capped at six.
     *
     * @return list<array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}>
     */
    private function rolePanels(ReadDashboardPanels $reader): array
    {
        $keys = [];

        foreach ($this->dashboardRoles() as $role) {
            foreach (RoleDashboard::for($role)['panels'] as $panel) {
                if (! in_array($panel, $keys, true)) {
                    $keys[] = $panel;
                }
            }
        }

        $panels = [];

        foreach ($keys as $key) {
            $panel = $reader->read($key);

            if ($panel !== null) {
                $panels[] = $panel;
            }

            // Six is the point past which the eye stops reading a KPI strip
            // and starts scanning it.
            if (count($panels) === 6) {
                break;
            }
        }

        return $panels;
    }
```

and replace `quickActions()` entirely with the role-driven version:

```php
    /**
     * The quick actions this user can actually perform, from their roles'
     * union.
     *
     * Both gates matter and are both applied: the PERMISSION, because a card
     * that 403s on click teaches the operator that the screen lies; and
     * Route::has, because a route that does not exist yet would 404 - and an
     * action offered by a role profile can outrun the module that provides
     * it.
     *
     * @return list<array{key: string, label: string, description: string, icon: string, url: string}>
     */
    private function quickActions(): array
    {
        $keys = [];

        foreach ($this->dashboardRoles() as $role) {
            foreach (RoleDashboard::for($role)['quick_actions'] as $action) {
                if (! in_array($action, $keys, true)) {
                    $keys[] = $action;
                }
            }
        }

        $visible = [];

        foreach ($keys as $key) {
            $action = RoleDashboard::quickAction($key);

            if ($action === null) {
                continue;
            }

            [$routeName, $permission] = $action;

            if (! Gate::allows($permission) || ! Route::has($routeName)) {
                continue;
            }

            $visible[] = [
                'key' => $key,
                'label' => (string) __('opes.dashboard.action_'.$key),
                'description' => (string) __('opes.dashboard.action_'.$key.'_description'),
                'icon' => (string) __('opes.dashboard.action_'.$key.'_icon'),
                'url' => route($routeName, absolute: false),
            ];
        }

        return $visible;
    }
```

**The icon must not come from a lang file** — that is a data leak into translations. Replace the `'icon'` line with a lookup instead: add a `QUICK_ACTION_ICONS` constant to `RoleDashboard` mapping each action key to an `x-opes-nav-icon` `navKey`, expose it as `RoleDashboard::quickActionIcon(string $key): string` (returning `'dashboard'` as the fallback), and use that here.

Finally, in `render()`, replace the five hard-coded tile variables with:

```php
        $panels = $this->rolePanels($panelReader);

        return view('livewire.dashboard', [
            'panels' => $panels,
            'alerts' => $this->alerts($health),
            'quickActions' => $this->quickActions(),
            'signedInAs' => $this->signedInAs(),
            // Kept: these two feed the health tile's display slot, which
            // carries a status pill rather than a numeral.
            'healthSummary' => Gate::allows(Permission::SettingView->value) ? $health->summary() : null,
            'lastBackupAge' => Gate::allows(Permission::BackupRun->value) ? $this->lastBackupAge() : null,
        ]);
```

adding `ReadDashboardPanels $panelReader` to `render()`'s injected parameters, and **delete** `$countUsers`, `$countRoles`, `$tileCount`, `$canViewUsers`, `$canViewHealth`, `$canViewBackup`, `$canViewAttendance`, `$todaysAttendanceRate` and `$financeDashboardUrl` from the returned array — the panel list supersedes all of them. Keep the private `todaysAttendanceRate()` method only if nothing else calls it; otherwise remove it too, since `ReadDashboardPanels::attendanceRate()` now owns that computation. **Do not leave two implementations of the attendance formula in the codebase.**

- [ ] **Step 4: Run the test**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Operations/RoleDashboardScreenTest.php`
Expected: FAIL on the blade, which still reads `$tileCount`. Task 43 rewrites it — that is the right failure to be at.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Operations/Livewire/Dashboard.php app/Modules/Operations/Domain/RoleDashboard.php tests/Feature/Operations/RoleDashboardScreenTest.php
git commit -m "feat(dashboard): compose panels and quick actions from the signed-in roles"
```

---

### Task 43: Rebuild the dashboard blade

**Files:**
- Modify: `resources/views/livewire/dashboard.blade.php` (full rewrite)
- Modify: `lang/en/opes.php`, `lang/fr/opes.php`

- [ ] **Step 1: Rewrite the blade**

Replace the entire contents of `resources/views/livewire/dashboard.blade.php` with:

```blade
{{-- The landing screen, composed PER ROLE (09-ui §3).

     This was one screen for twenty roles, and it produced two defects an
     audit caught: an Accountant landed on a page with zero KPI cards, and a
     Teacher landed on one card reading "—" beside a raw authorization
     exception. Gating the admin tiles away from other roles was correct;
     having nothing to put in their place was not.

     Spacing follows the 8pt token grid (--space-*). Do NOT reason about
     pixel sizes from the Tailwind utility names on this page: the root
     font-size is 17px, so gap-4 is 16px but w-72 is 306px. --}}
<div class="min-w-0 space-y-8">

    {{-- ── Greeting ─────────────────────────────────────────────────────── --}}
    <div>
        <h1 class="text-2xl font-bold text-charcoal">
            {{ __('opes.dashboard.greeting', ['name' => $signedInAs['name'] ?? '']) }}
        </h1>
        @if (($signedInAs['role'] ?? null) !== null)
            <p class="mt-1 text-sm text-text-secondary">
                {{ __('opes.dashboard.greeting_role', ['role' => $signedInAs['role']]) }}
            </p>
        @endif
    </div>

    {{-- ── KPI strip ────────────────────────────────────────────────────── --}}
    @if ($panels !== [])
        <section aria-labelledby="opes-dashboard-overview">
            <h2 id="opes-dashboard-overview" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.dashboard.overview') }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($panels as $panel)
                    <x-kpi-card wire:key="panel-{{ $panel['key'] }}"
                                :label="__('opes.dashboard.panel_'.$panel['key'])"
                                :value="$panel['value']"
                                :sub="$panel['sub'] ?? __('opes.dashboard.panel_'.$panel['key'].'_sub')"
                                :tone="$panel['tone']"
                                :href="$panel['route'] === null ? null : route($panel['route'], absolute: false)">
                        <x-slot:icon>
                            <x-opes-nav-icon :nav-key="$panel['icon']" class="h-5 w-5"/>
                        </x-slot:icon>
                    </x-kpi-card>
                @endforeach
            </div>
        </section>
    @else
        {{-- The empty-state rule: a role with nothing to show still lands on
             something that says what it is and offers what it can do. Never a
             blank grid. --}}
        <section class="rounded-xl border border-border-primary bg-white p-6 text-center shadow-sm">
            <p class="text-base font-medium text-charcoal">{{ __('opes.dashboard.empty_title') }}</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-text-secondary">{{ __('opes.dashboard.empty_body') }}</p>
        </section>
    @endif

    {{-- ── Quick actions ────────────────────────────────────────────────── --}}
    @if ($quickActions !== [])
        <section aria-labelledby="opes-dashboard-actions">
            <h2 id="opes-dashboard-actions" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.dashboard.quick_actions') }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($quickActions as $action)
                    <a href="{{ $action['url'] }}" wire:key="action-{{ $action['key'] }}"
                       class="group flex min-h-[88px] items-start gap-3 rounded-xl border border-border-primary bg-white p-4 shadow-sm transition hover:border-primary hover:shadow-md">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-kpi-green text-kpi-green-solid">
                            <x-opes-nav-icon :nav-key="$action['icon']" class="h-5 w-5"/>
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold text-charcoal group-hover:text-primary">{{ $action['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-text-secondary">{{ $action['description'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── Alerts ───────────────────────────────────────────────────────── --}}
    <section aria-labelledby="opes-alerts">
        <h2 id="opes-alerts" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.dashboard.alerts') }}
        </h2>

        @if ($alerts === [])
            <x-empty-state :message="__('opes.dashboard.no_alerts')"/>
        @else
            <ul class="space-y-2">
                @foreach ($alerts as $alert)
                    <li class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-bg px-4 py-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-charcoal">
                                {{ __('opes.health.'.$alert->key) }}
                            </p>
                            <p class="text-sm text-charcoal/70">{{ $alert->message }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
```

**Check `x-kpi-card`'s icon API before relying on the `<x-slot:icon>` above** — the component takes `$icon` as a prop holding raw markup (`{!! $icon !!}`). If it has no `icon` slot, pass the rendered glyph instead: `:icon="view('components.opes-nav-icon', ['navKey' => $panel['icon']])->render()"`. Read the component and use whichever it actually supports.

Also confirm `$alert->key` / `$alert->message` match `HealthCheckResult`'s real properties, and that `opes.health.<key>` strings exist — the previous blade rendered alerts differently, and `tests/Architecture/HealthCheckLocalisationTest.php` asserts every check key has a label.

- [ ] **Step 2: Add the lang keys**

`lang/en/opes.php` under `'dashboard'`, add `'overview' => 'Overview'`, `'greeting' => 'Good day, :name'`, `'greeting_role' => 'Signed in as :role'`, `'empty_title' => 'Nothing to show here yet'`, `'empty_body' => 'Your role does not have summary figures on this screen. Use the navigation to reach the areas you work in.'`, and a `panel_<key>` **and** `panel_<key>_sub` pair for every one of the ~35 panel keys in `RoleDashboard::PANEL_PERMISSIONS`, plus an `action_<key>` and `action_<key>_description` pair for every one of the ~23 quick-action keys. For example:

```php
        'panel_cash_position' => 'Cash position',
        'panel_cash_position_sub' => 'Across all cash and bank accounts',
        'panel_unposted_entries' => 'Unposted entries',
        'panel_unposted_entries_sub' => 'Draft journal entries awaiting posting',
        'panel_open_periods' => 'Open periods',
        'panel_open_periods_sub' => 'Fiscal years still accepting entries',
        'panel_my_classes' => 'My classes',
        'panel_my_classes_sub' => 'Class groups on your timetable',
        'panel_registers_not_taken' => 'Registers outstanding',
        'panel_registers_not_taken_sub' => 'Your lessons today with no register',
        'panel_marks_due' => 'Marks due',
        'panel_marks_due_sub' => 'Assessment periods still open',
        'panel_todays_consultations' => "Today's consultations",
        'panel_todays_consultations_sub' => 'Visits logged at the sick bay today',
        'panel_books_on_loan' => 'Books on loan',
        'panel_books_on_loan_sub' => 'Copies currently out',
        'action_take_register' => 'Take register',
        'action_take_register_description' => 'Mark today’s attendance for a class.',
        'action_enter_marks' => 'Enter marks',
        'action_enter_marks_description' => 'Record marks for an open assessment period.',
```

Add the French equivalent for **every** key. `tests/Feature/LocalisationTest.php` will name any that is missing; run it after each batch rather than at the end.

- [ ] **Step 3: Run the tests**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature/Operations tests/Architecture/HealthCheckLocalisationTest.php tests/Feature/LocalisationTest.php`
Expected: PASS.

- [ ] **Step 4: Build and commit**

```bash
npm run build
git add resources/views/livewire/dashboard.blade.php lang/en/opes.php lang/fr/opes.php
git commit -m "feat(dashboard): rebuild the landing screen around per-role panels"
```

---

### Task 44: Screenshot every role, at both breakpoints

**Files:** none — this is verification, and it is the only thing that catches a dashboard that passes its tests and looks wrong.

**The screenshot gotcha, which has already caused one false bug report in this project: always `resize_window` to 1440×900 IMMEDIATELY before the screenshot, never straight after a navigate or reload.** The pane loses its viewport after navigation and renders a tiny page; a measurement taken then is meaningless.

- [ ] **Step 1: Start the preview**

```
preview_start {name: "opes"}
```

Expected: a server on port 8931. (The demo behind the Cloudflare tunnel is 8940; use 8931 for local verification.)

- [ ] **Step 2: Seed a user per role**

Run:

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" artisan tinker --execute="
foreach (['administrator','accountant','teacher','nurse','librarian'] as \$r) {
    \$u = App\Modules\Identity\Models\User::firstOrCreate(
        ['email' => \$r.'@shot.local'],
        ['name' => ucfirst(\$r), 'password' => bcrypt('password123')]
    );
    \$u->syncRoles([\$r]);
    echo \$r, ' ok', PHP_EOL;
}"
```

Expected: five `ok` lines. If `syncRoles` fails, the role has not been seeded — run `$PHP artisan db:seed --class=RolePermissionSeeder` first.

- [ ] **Step 3: Capture desktop, one role at a time**

For **each** of `administrator`, `accountant`, `teacher`, `nurse`, `librarian`:

```
navigate  http://localhost:8931/login
resize_window 1440x900
(sign in as <role>@shot.local / password123)
navigate  http://localhost:8931/dashboard
resize_window 1440x900        ← immediately before the screenshot
computer screenshot
```

Check against the spec at the top of this phase, **by looking, not by measuring**:
- Cards present and role-appropriate — an Accountant sees finance figures, a Teacher sees classes and registers, a Nurse sees consultations, a Librarian sees loans.
- **No card reading "—" without a sub-line explaining why**, and **no raw exception text anywhere on the page**.
- KPI numerals sit on one shared baseline across the row (the fixed two-line label box).
- Four columns at this width; 16px gutters; cards visually distinct by tone rather than four identical greens.
- Quick-action cards all the same height, icons optically aligned.

- [ ] **Step 4: Capture mobile**

For the same five roles:

```
resize_window 375x812
(reload)
resize_window 375x812        ← immediately before the screenshot
computer screenshot
```

Check: one column throughout, nothing overflowing horizontally, every tappable control at least 44px on its short edge, the greeting not wrapping to three lines.

- [ ] **Step 5: Fix what you see, then re-shoot**

Any defect found here is fixed in the blade or the component and re-verified with a fresh screenshot at the same size. **A measurement is not a substitute for looking**: this project has a standing note that computed styles pass while the page looks wrong.

- [ ] **Step 6: Commit any fixes**

```bash
npm run build
git add resources/views/livewire/dashboard.blade.php app/Modules/Operations/Livewire/Dashboard.php
git commit -m "fix(dashboard): visual corrections from the role screenshot pass"
```

---

# Final verification

### Task 45: Full verification pass

**Files:** none — nothing new is written here. Anything this task finds is fixed in the task that owns it.

- [ ] **Step 1: Architecture and unit suites**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Architecture tests/Unit`
Expected: PASS. `ModuleBoundaryTest` is the one most likely to fail here — every dashboard and student-tab read added by this plan must be `DB::table`, never another module's Model.

- [ ] **Step 2: The full feature suite**

Run: `DB_DATABASE=opeschool_test_verify $PHP vendor/bin/pest tests/Feature`
Expected: PASS. **Never run two suites concurrently** — they share one MySQL database.

- [ ] **Step 3: Scoped PHPStan**

Run:

```bash
"C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" vendor/bin/phpstan analyse \
  app/Modules/SchoolProfile app/Modules/Operations/Domain app/Modules/Operations/Actions \
  app/Modules/Operations/Livewire app/Modules/Reporting/Domain app/Modules/Reporting/Actions \
  app/Modules/Reporting/Http app/Modules/Assets/Actions app/Modules/Assets/Livewire \
  app/Modules/Students/Livewire app/Support/Branding app/Support/Storage \
  --level=8 --no-progress
```

Expected: **0 errors in the files this plan created or modified.** The repo carries ~268 pre-existing errors and **zero `ignoreErrors`** — new code adds none. If an error is genuinely in pre-existing untouched code inside a scanned directory, note it and move on; if it is in a file this plan touched, fix it. **Never add an `ignoreErrors` entry or a baseline.**

- [ ] **Step 4: Front-end build**

Run: `npm run build`
Expected: build succeeds with no CSS or Blade errors.

- [ ] **Step 5: Migrate the dev database**

Run: `$PHP artisan migrate --force`
Expected: the five migrations this plan adds run clean — `440001` (branding settings), `440002` (school watermark), `440003` (asset label templates), `440010` (broadsheet). If a fresh test database is needed, **run it in the background**: a first migrate takes 10–20 minutes.

- [ ] **Step 6: Confirm the storage symlink**

Run: `$PHP artisan storage:link`
Expected: `The [public/storage] link already exists.`

- [ ] **Step 7: Live browser verification, phase by phase**

`preview_start {name: "opes"}`, then walk each phase. **`resize_window 1440x900` immediately before every screenshot, never straight after a navigate or reload.**

| Phase | Route | What must be true |
|---|---|---|
| 0 | `/settings` | Eight categorised cards with state summaries; a Bursar sees fewer than a Principal |
| 0 | `/settings/school-identity` | Grouped fieldsets, helper text under each field, sticky save bar, unsaved-changes marker, success toast |
| 0c | `/settings/branding` | Six pickers with synced hex boxes, seven presets, contrast warning on a bad pick, live preview repainting |
| 0c | `/students` after saving Burgundy | Sidebar and buttons are burgundy, not green — **this is the Tailwind-layering check** |
| 1 | `/settings/school-identity` | Upload a crest; the thumbnail appears; save; re-open and it is still there |
| 1 | a certificate PDF | Crest, logo, signature images and stamp all visible in the rendered PDF |
| 2 | `/settings/school-identity` | Enable the school watermark; print a document; **both** the school mark and SPECIMEN/DUPLICATA appear |
| 3 | `/assets/{id}` | "Print label" streams a CR80 PDF with a scannable barcode |
| 3 | `/assets` | Tick three assets; "Print label sheet" streams an A4 sheet |
| 4 | `/students/{id}?tab=documents` | Preview links open a SPECIMEN PDF inline; no serial appears; `issued_documents` count is unchanged |
| 5 | `/students/{id}` | All eleven tabs are live; none is greyed; each empty one shows a designed empty state |
| 6 | — | `BROADSHEET` renders at A3 landscape |
| 7 | `/dashboard` as each of five roles | Role-appropriate cards, no "—" without explanation, no exception text |

- [ ] **Step 8: Scan the whole diff**

Run: `git log --oneline main..HEAD` and `git diff main --stat`
Expected: one commit per task, and no file changed that no task named. A file in the diff that no task mentions is either scope creep or a mistake.

---

# Risks and what could go wrong

**1. Frozen document reproducibility (Phases 1 and 2) — the highest-consequence risk in this plan.**
`RenderDocument` freezes the school chrome, including image *paths*, onto every issued document, and reprints re-render and hash-compare. If replacing an image reused its path, **every certificate issued before the replacement would fail its reproducibility check, permanently, with no way back** — a silent, total, unrecoverable loss of reprintability across a school's whole document history. Content-hashed filenames (Task 14) make it structurally impossible: different bytes, different path. Tasks 19 and 23 pin all four cases (replace, delete, watermark-on-after-issue, image-added-after-issue). **The residual risk is deletion**: `StoredImage::forget()` deletes the old file, so a document frozen against that path now re-renders *without* the image and throws `DocumentReproducibilityViolation` — an honest 422 rather than a forged certificate, which is the right side of the trade, but it *will* surprise a school that replaces a signature and then reprints an old certificate. That behaviour is documented in the class and asserted by test 3 of Task 19. **If it proves unacceptable in the field, the fix is to stop deleting — not to reuse paths.**

**2. Preview/issue divergence (Phase 4).** A preview that shows something different from what gets issued is worse than no preview: the operator stops checking, and the first document they do not check is the wrong one. `preview()` deliberately calls the same template resolution, language resolution, snapshot load, chrome capture and `renderHtml()` as `issueOriginal()`, and Task 31 asserts byte-equality of the document body across both paths — including that previewing first does not change what the subsequent issue produces. **The ongoing risk is drift**: a future change to `issueOriginal()` that is not mirrored in `preview()`. Task 31's test is what catches it, so that test must never be weakened to accommodate a diff in the document body — only in the watermark and dates.

**3. Wrong table or column names (Phases 5 and 7).** `ReadDashboardPanels` makes ~35 cross-module reads and the student tabs make another ten, all written against table names inferred from module structure rather than verified. **A wrong name is a 500 on the two most-visited screens in the product.** Both phases open with an explicit reconciliation step against `artisan db:show` / `db:table`, and Task 41's last test walks every panel every role can be given. Follow those steps; do not skip them because a name "looks right".

**4. The Tailwind 4 layering trap (Phase 0).** Unlayered CSS outranks every layered rule regardless of specificity. A `@layer components` version of the brand override ships as a **silent no-op that measures correctly in devtools** — this has already happened once in this codebase. Task 10 Step 8 requires a screenshot proving a changed colour actually repaints `/students`. That screenshot is the only real check; the test asserting the `<style>` block's content is necessary but not sufficient.

**5. The 17px root (Phase 0b).** Every published 8pt-grid and modular-scale table assumes 16px. Spacing tokens are therefore declared in `px`, and `DesignTokenTest` fails the build if a `--space-*` token is ever expressed in rem. Tailwind's spacing names also lie at this root (`w-72` = 306px): never infer a size from a utility name.

**6. Livewire uploads are new here (Phase 1).** Nothing in the repo used `WithFileUploads` before. The failure modes are a `TemporaryUploadedFile` left on a public property (re-serialised into every subsequent request payload — the plan deletes it inside `save()`), a missing `public/storage` symlink (verified in Task 16 Step 9), and validation that admits SVG (`image` alone does; the plan adds an explicit mimes list and refuses SVG as a stored-XSS surface).

**7. Scope.** This plan is 45 tasks across eight phases and touches the settings shell, the branding system, the document platform, the asset register, the student profile and the dashboard. **Phases 0–2 are one shippable increment; 3–7 are independent of each other and of everything after Phase 2.** If the work has to stop, stop on a phase boundary — every phase ends with a full test run and a working product.

**8. An implementer is already executing Tasks 1–10 concurrently with the authoring of Tasks 11–45.** Tasks ≤10 must not be renumbered or restructured. Later tasks that modify the same files (`DocumentProfile.php`, `Branding.php`, `layouts/app.blade.php`, the lang files) build on the state Tasks 1–10 leave behind and say so explicitly; if a conflict appears, the earlier task's version is authoritative.

---

# Self-review

Run against the writing-plans skill's checklist.

**1. Spec coverage.** Every phase from the three briefs maps to tasks:

| Requirement | Tasks |
|---|---|
| Settings hub, categorised, permission-gated, state summaries | 1, 2 |
| Proper form workflow, one reusable pattern | 3, 4 |
| Multi-colour branding, presets, contrast, live preview | 5, 6, 7, 8, 9 |
| Where colours land (Tailwind 4, 17px, unlayered) | 10, 12 |
| Design system foundation, tokens, icon decision, WCAG test | 11, 12, 13 |
| Image uploads (all eight assets) | 14, 16, 17, 22 |
| Rendering logo / signatures / stamp as data URIs | 15, 18 |
| Reproducibility hazard argued and tested | 14 (design), 19, 23 |
| School watermark as a second layer, text **and** image | 20, 21, 22, 23 |
| Asset barcode + CR80 label + bulk sheet | 24, 25, 26, 27, 28, 29 |
| Preview before issue, divergence-proofed, wired into the UI | 30, 31, 32 |
| Dead buttons: audit, rule, implement-or-remove | 33, 34, 35, 36, 37, 38 |
| A3/LETTER decided with evidence | 39 |
| Role dashboards, RBAC-derived, all 20 roles, both defects fixed | 40, 41, 42, 43, 44 |
| Final verification incl. live browser + screenshot gotcha | 44, 45 |
| Risks section | above |

**2. Placeholder scan.** No "TBD", no "add validation", no "similar to Task N". Every code step carries the actual code. Four places deliberately require the implementer to *read reality before writing* — the icon-slot API of `x-kpi-card` (Task 43), `StudentDocumentReads`' real method (Task 32), `LogStudentActivity`'s signature (Task 37), and the ~45 table/column names (Tasks 33, 41). Those are verification instructions with an exact command and expected output, not placeholders: the alternative is a plan that confidently names a column that does not exist.

**3. Type consistency.** Checked across tasks: `BrandTokens::fromArray/all/get/toCssVariables` used identically in Tasks 6, 9, 10, 11, 13. `ColorContrast::ratio/passesAA/AA_NORMAL` identical in 5, 9, 11, 13. `StoredImage::putContents/forget/ALLOWED_EXTENSIONS/MAX_KILOBYTES/MAX_DIMENSION/DIRECTORY/DISK` identical in 14, 15, 16, 17. `EmbeddedImage::dataUri/resolveBranding` identical in 15, 18, 21. `AssetTagBarcode::fromCanonical/tryFromCanonical/barcodePayload` identical in 24, 28. `RoleDashboard::for/panelPermission/quickAction` identical in 40, 41, 42. `RenderDocument::preview()`'s signature matches its call sites in 31 and 32. `IMAGE_SLOTS` gains its sixth entry in Task 22 and every walker of it (validation, storage, preview, removal) is driven from that one constant.

**Two fixes applied during review:** Task 43's `'icon'` was originally read from a lang file — corrected to a `RoleDashboard::quickActionIcon()` lookup, because an icon name in a translation file is data in the wrong place and would drift between `en` and `fr`. Task 11's AA clamp was originally introduced as a conditional fix in Task 13 — moved into `ColorScale::of()` in Task 11 so the class has one behaviour rather than two depending on which task ran.
