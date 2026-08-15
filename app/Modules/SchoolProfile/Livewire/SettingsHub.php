<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Actions\EvaluateSetupReadiness;
use App\Modules\Operations\Domain\SetupCheckStatus;
use App\Modules\SchoolProfile\Domain\SettingsCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

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
        $cards = [];

        foreach (SettingsCatalogue::cards() as $card) {
            if (! Gate::allows($card['permission'])) {
                continue;
            }

            // Summaries are computed only for the cards actually rendered:
            // several of them are cross-module reads, and a card the holder
            // cannot open is a query nobody needs.
            $summary = $this->summaryFor($card['key']);

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
     * The per-card "current state" line. Query-builder reads only (plus the
     * Operations readiness ACTION for go-live, which is the module's only
     * legitimate door): these cross five modules, and ModuleBoundaryTest
     * allows exactly these two shapes.
     *
     * @return array{text: string, tone: string}
     */
    private function summaryFor(string $key): array
    {
        return match ($key) {
            'school_identity' => $this->schoolIdentitySummary(),
            'branding' => ['text' => (string) __('opes.settings_hub.branding_summary'), 'tone' => 'neutral'],
            'fiscal' => $this->fiscalSummary(),
            'tax' => $this->taxSummary(),
            'licence' => $this->licenceSummary(),
            'academic' => $this->academicSummary(),
            'go_live' => $this->goLiveSummary(),
            'advanced' => [
                'text' => (string) __('opes.settings_hub.advanced_summary', [
                    'count' => DB::table('settings')->count(),
                ]),
                'tone' => 'neutral',
            ],
            default => ['text' => '', 'tone' => 'neutral'],
        };
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function schoolIdentitySummary(): array
    {
        $profile = DB::table('school_document_profiles')->where('id', 1)->first();

        $imageColumns = [
            'crest_path', 'logo_path', 'principal_signature_path',
            'registrar_signature_path', 'school_stamp_path',
        ];

        $imagesSet = 0;
        $row = $profile === null ? [] : (array) $profile;

        foreach ($imageColumns as $column) {
            /** @var mixed $value */
            $value = $row[$column] ?? null;

            if (is_string($value) && $value !== '') {
                $imagesSet++;
            }
        }

        return [
            'text' => (string) __('opes.settings_hub.images_set', [
                'set' => $imagesSet,
                'total' => count($imageColumns),
            ]),
            'tone' => $imagesSet === count($imageColumns) ? 'good' : 'warn',
        ];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function fiscalSummary(): array
    {
        $confirmed = DB::table('fiscal_identities')->where('id', 1)
            ->whereNotNull('fiscal_identity_confirmed_at')->exists();

        return [
            'text' => $confirmed
                ? (string) __('opes.settings_hub.fiscal_confirmed')
                : (string) __('opes.settings_hub.fiscal_specimen'),
            'tone' => $confirmed ? 'good' : 'warn',
        ];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function taxSummary(): array
    {
        /** @var mixed $regime */
        $regime = DB::table('fiscal_identities')->where('id', 1)->value('tax_regime');

        $set = is_string($regime) && $regime !== '';

        return [
            'text' => $set
                ? (string) __('opes.settings_hub.tax_regime', ['regime' => strtoupper($regime)])
                : (string) __('opes.settings_hub.tax_unset'),
            'tone' => $set ? 'good' : 'warn',
        ];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function licenceSummary(): array
    {
        // `expires_at`, not `expires_on` - the column the licences table
        // actually carries (2026_08_09_255004).
        /** @var mixed $expiry */
        $expiry = DB::table('licences')->orderByDesc('id')->value('expires_at');

        $days = null;

        if (is_string($expiry)) {
            $expiresAt = strtotime($expiry);

            if ($expiresAt !== false) {
                $days = (int) round(($expiresAt - strtotime('today')) / 86400);
            }
        }

        return [
            'text' => $days === null
                ? (string) __('opes.settings_hub.licence_none')
                : (string) __('opes.settings_hub.licence_expires', ['days' => $days]),
            'tone' => $days !== null && $days > 30 ? 'good' : 'warn',
        ];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function academicSummary(): array
    {
        /** @var mixed $year */
        $year = DB::table('academic_years')->where('is_current', true)->value('name');

        return [
            'text' => is_string($year)
                ? (string) __('opes.settings_hub.academic_current', ['year' => $year])
                : (string) __('opes.settings_hub.academic_none'),
            'tone' => is_string($year) ? 'good' : 'warn',
        ];
    }

    /**
     * There is no `setup_checklist_items` table: go-live readiness is
     * EVALUATED against live data on every render (that is the whole point of
     * 00-core §16 - nothing can be ticked). So the blocker count comes from
     * the Operations module's own Action, the only cross-module door
     * ModuleBoundaryTest permits for anything but a plain DB::table read.
     *
     * @return array{text: string, tone: string}
     */
    private function goLiveSummary(): array
    {
        try {
            $checks = app(EvaluateSetupReadiness::class)->handle();
        } catch (Throwable) {
            return ['text' => '', 'tone' => 'neutral'];
        }

        $blockers = count(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === SetupCheckStatus::Blocked,
        ));

        return [
            'text' => $blockers === 0
                ? (string) __('opes.settings_hub.go_live_clear')
                : (string) __('opes.settings_hub.go_live_blockers', ['count' => $blockers]),
            'tone' => $blockers === 0 ? 'good' : 'warn',
        ];
    }

    public function render(): mixed
    {
        return view('livewire.schoolprofile.settings-hub', [
            'cards' => $this->visibleCards(),
        ]);
    }
}
