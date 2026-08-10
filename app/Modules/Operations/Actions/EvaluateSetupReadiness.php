<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Domain\SetupCheckStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The first-run readiness console (00-core §16 blocking gates).
 *
 * The specs reference a "setup wizard" repeatedly as the place where the
 * blocking gates get resolved, and the governing rule is 00-core §16: a
 * WRONG SEEDED VALUE IS MORE DANGEROUS THAN AN EMPTY FIELD, because it looks
 * authoritative. So this deliberately does not invent statutory values and
 * does not offer to guess them.
 *
 * What it does instead is tell a school, against its own live data, exactly
 * which gates are open, what each one blocks, and who has to answer it -
 * usually the school's accountant, sometimes MINESEC documentation. Every
 * check reads real state; none is a stored flag somebody can tick to make a
 * red row go green.
 *
 * `blocked` means something concrete already refuses to run - the check
 * names it. `warning` means the product degrades quietly rather than
 * refusing.
 */
final class EvaluateSetupReadiness
{
    /**
     * @return list<array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}>
     */
    public function handle(): array
    {
        Gate::authorize(Permission::SettingView->value);

        return [
            $this->fiscalYearIsCalendar(),
            $this->fiscalYearCoversToday(),
            $this->twoAccountingUsers(),
            $this->taxSettingsConfigured(),
            $this->vatProrataConfirmed(),
            $this->dsfMappingPopulated(),
            $this->assessmentFrameworkPresent(),
            $this->chartHasPostableAccounts(),
        ];
    }

    /**
     * 02-accounting §6: a Cameroonian school's fiscal year MUST be the
     * calendar year. A Sept-Aug "fiscal year" produces accounts that are
     * legally void and a DSF that cannot be filed. The spec says the wizard
     * warns hard, in red, and does not permit override.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function fiscalYearIsCalendar(): array
    {
        $offenders = [];

        foreach (DB::table('fiscal_years')->get() as $year) {
            $starts = (string) $year->starts_on;
            $ends = (string) $year->ends_on;

            $startsJan = str_contains($starts, '-01-01');
            $endsDec = str_contains($ends, '-12-31');

            if (! $endsDec || (! $startsJan && ! (bool) ($year->is_first_exercice ?? false))) {
                $offenders[] = (string) $year->code;
            }
        }

        return [
            'key' => 'fiscal_year_calendar',
            'title' => 'Fiscal years follow the calendar year',
            'status' => $offenders === [] ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => $offenders === []
                ? sprintf('%d fiscal year(s), all 1 January to 31 December.', DB::table('fiscal_years')->count())
                : 'Non-calendar fiscal year(s): '.implode(', ', $offenders),
            'remedy' => 'A school whose fiscal year runs September to August produces accounts that cannot '
                .'legally be filed and a DSF the DGI will not accept. The academic year runs Sept-July; the '
                .'LEDGER keeps its own calendar year alongside it. This cannot be overridden.',
            'owner' => 'Accountant',
        ];
    }

    /**
     * 00-core §8 makes the academic calendar gapless precisely so that every
     * date has a year. A ledger with no fiscal year covering today cannot
     * post, and reversals in particular fail in a way that reads as a bug.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function fiscalYearCoversToday(): array
    {
        $today = now()->toDateString();

        $covering = DB::table('fiscal_years')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->count();

        return [
            'key' => 'fiscal_year_today',
            'title' => 'A fiscal year covers today',
            'status' => $covering > 0 ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => $covering > 0
                ? "Today ({$today}) falls inside an open fiscal year."
                : "No fiscal year covers {$today}.",
            'remedy' => 'Create the fiscal year for the current calendar year. Posting, and reversals in '
                .'particular, refuse without one.',
            'owner' => 'Accountant',
        ];
    }

    /**
     * 02-accounting §20.2: maker-checker needs two people. A single-user
     * school hits this immediately, and "solo mode" is deliberately not
     * offered - it would become the default within a week at every site.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function twoAccountingUsers(): array
    {
        $count = DB::table('users as u')
            ->join('model_has_roles as mhr', function ($join): void {
                $join->on('mhr.model_id', '=', 'u.id')
                    ->where('mhr.model_type', '=', 'App\\Modules\\Identity\\Models\\User');
            })
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereIn('r.name', ['accountant', 'bursar', 'super_admin'])
            ->distinct()
            ->count('u.id');

        return [
            'key' => 'two_accounting_users',
            'title' => 'At least two people hold accounting rights',
            'status' => $count >= 2 ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => "{$count} user(s) hold an accounting role.",
            'remedy' => 'Separation of duties needs two people: the one who records is not the one who '
                .'approves. A solo mode that disables maker-checker is not offered, because it would become '
                .'the default everywhere.',
            'owner' => 'Proprietor',
        ];
    }

    /**
     * 03-tax-procurement §5.4 / §11.16 - withholding recognition and prorata
     * rounding ship unset, and the dependent Actions refuse until they are
     * decided.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function taxSettingsConfigured(): array
    {
        $row = DB::table('tax_settings')->first();

        $missing = [];

        if ($row === null || $row->withholding_recognition === null) {
            $missing[] = 'withholding recognition (on invoice vs on payment)';
        }

        if ($row === null || $row->prorata_rounding === null) {
            $missing[] = 'prorata rounding rule';
        }

        return [
            'key' => 'tax_settings',
            'title' => 'Tax treatment decided',
            'status' => $missing === [] ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => $missing === [] ? 'Configured.' : 'Unset: '.implode('; ', $missing),
            'remedy' => 'These ship blank on purpose. Whether the CGI rounds the prorata up to the whole '
                .'percent is NEEDS VERIFICATION, and a guessed value would look authoritative while being '
                .'wrong. Decide both with the accountant.',
            'owner' => 'Accountant',
        ];
    }

    /**
     * §5.4: input VAT cannot be split without a CONFIRMED prorata, and
     * ComputeLineTax refuses - which reads as a broken procurement module if
     * nobody has been told.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function vatProrataConfirmed(): array
    {
        $years = DB::table('fiscal_years')->pluck('id');

        $missing = [];

        foreach ($years as $yearId) {
            $has = DB::table('vat_proratas')
                ->where('fiscal_year_id', $yearId)
                ->whereNotNull('confirmed_at')
                ->exists();

            if (! $has) {
                $missing[] = (string) DB::table('fiscal_years')->where('id', $yearId)->value('code');
            }
        }

        return [
            'key' => 'vat_prorata',
            'title' => 'A confirmed prorata de déduction exists per fiscal year',
            'status' => $missing === [] ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => $missing === [] ? 'Every fiscal year has one.' : 'Missing for: '.implode(', ', $missing),
            'remedy' => 'Without it, every supplier invoice carrying deductible VAT refuses to post. A '
                .'school\'s activity is mixed - exempt tuition alongside taxable ancillary sales - so the '
                .'provisional prorata is computed with the accountant and confirmed.',
            'owner' => 'Accountant',
        ];
    }

    /**
     * §14 / DSF: the mapping is done once with the accountant, against the
     * DSF form itself. Unmapped, the annual filing cannot be produced.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function dsfMappingPopulated(): array
    {
        $total = DB::table('chart_of_accounts')->count();
        $mapped = DB::table('chart_of_accounts')->whereNotNull('dsf_line_code')->count();

        return [
            'key' => 'dsf_mapping',
            'title' => 'Chart accounts map to DSF lines',
            'status' => $mapped > 0 ? SetupCheckStatus::Pass : SetupCheckStatus::Warning,
            'detail' => "{$mapped} of {$total} accounts carry a DSF line code.",
            'remedy' => 'The DSF form is the authority for these codes and they are NEEDS VERIFICATION here, '
                .'so nothing is seeded. Until they are mapped the statements and books work, but the annual '
                .'DSF cannot be produced.',
            'owner' => 'Accountant',
        ];
    }

    /**
     * 00-core §16 gates 1-5: the MINESEC specimens. Without a real framework
     * the product can compute marks but cannot print the bulletin a school
     * actually recognises.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function assessmentFrameworkPresent(): array
    {
        $frameworks = DB::table('assessment_frameworks')->pluck('code')->all();
        $real = array_values(array_filter(
            $frameworks,
            static fn ($code): bool => ! str_starts_with((string) $code, 'DEMO')
        ));

        return [
            'key' => 'assessment_framework',
            'title' => 'A real assessment framework is configured',
            'status' => $real !== [] ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => $frameworks === []
                ? 'No framework configured.'
                : 'Configured: '.implode(', ', array_map('strval', $frameworks)),
            'remedy' => 'Report cards need the MINESEC framework the school actually uses - Francophone '
                .'bulletin or Anglophone report card - including its mark scale and coefficients. Whether '
                .'Anglophone secondary marks out of 20 or by percentage is NEEDS VERIFICATION and needs a '
                .'specimen from the school.',
            'owner' => 'Head of studies',
        ];
    }

    /**
     * A chart with no postable account means nothing can be recorded at all.
     *
     * @return array{key: string, title: string, status: SetupCheckStatus, detail: string, remedy: string, owner: string}
     */
    private function chartHasPostableAccounts(): array
    {
        $postable = DB::table('chart_of_accounts')->where('is_postable', true)->count();

        return [
            'key' => 'chart_postable',
            'title' => 'The chart of accounts has postable accounts',
            'status' => $postable > 0 ? SetupCheckStatus::Pass : SetupCheckStatus::Blocked,
            'detail' => "{$postable} postable account(s).",
            'remedy' => 'Seed or import the SYSCOHADA chart before opening the ledger.',
            'owner' => 'Accountant',
        ];
    }
}
