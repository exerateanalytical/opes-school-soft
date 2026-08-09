<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountingPeriodStatus;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Assets\Actions\ApproveDepreciationRun;
use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\PostDepreciationRun;
use App\Modules\Assets\Actions\RegisterAsset;
use App\Modules\Assets\Actions\RunDepreciation;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\DepreciationRun;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AssetTestHelpers.php';

/**
 * Phase 9 F2 fixtures (depreciation / disposal / subsidies). Prefix
 * phase9Depr, every helper function_exists-guarded. Reuses the F1 helpers
 * (phase9Asset*) for users, suppliers, account lookups and line dumps.
 */
if (! function_exists('phase9DeprAccount')) {
    /**
     * Ensure a postable account exists at $code, creating every missing
     * prefix level (CoA-2: one digit per level) and flipping a parent
     * non-postable before it gains its first child (CoA-4).
     */
    function phase9DeprAccount(string $code, string $type, string $normalBalance): int
    {
        $parentId = null;
        $length = strlen($code);

        for ($depth = 1; $depth <= $length; $depth++) {
            $prefix = substr($code, 0, $depth);
            $isLeaf = $depth === $length;

            /** @var ChartOfAccount|null $existing */
            $existing = ChartOfAccount::query()->where('code', $prefix)->first();

            if ($existing !== null) {
                if (! $isLeaf && $existing->is_postable) {
                    $existing->forceFill(['is_postable' => false])->save();
                }

                $parentId = (int) $existing->getKey();

                continue;
            }

            /** @var ChartOfAccount $created */
            $created = ChartOfAccount::query()->create([
                'code' => $prefix,
                'parent_id' => $parentId,
                'name' => 'Test account '.$prefix,
                'name_fr' => 'Compte de test '.$prefix,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'is_postable' => $isLeaf,
            ]);

            $parentId = (int) $created->getKey();
        }

        assert($parentId !== null);

        return $parentId;
    }
}

if (! function_exists('phase9DeprAccounts')) {
    /**
     * The two school-configured accounts this package needs beyond the
     * seed: 6811 (dotations aux amortissements - V3, school's own
     * subdivision) and 845 (quote-part de subvention virée au résultat -
     * V5, school's own choice after verification).
     *
     * @return array{expense_681: int, release_845: int}
     */
    function phase9DeprAccounts(): array
    {
        // §6.2 / 02-accounting C2: 485 Créances sur cessions is configured
        // COLLECTIVE so disposal receivables carry the buyer partner - the
        // same school-side configuration the spec describes.
        /** @var ChartOfAccount $disposalReceivable */
        $disposalReceivable = ChartOfAccount::query()->where('code', '485')->firstOrFail();

        if (! $disposalReceivable->is_collective) {
            $disposalReceivable->forceFill([
                'is_collective' => true,
                'allowed_partner_types' => ['supplier'],
            ])->save();
        }

        // §6.4: the clawback's donor liability line is partner-stamped
        // (ClawBackSubsidy 'subsidy.partner' => supplier), so the account
        // the fixture points it at must be configured COLLECTIVE too - the
        // same school-side choice as the 485 flip above, else L8 rejects
        // the partner-carrying line outright.
        /** @var ChartOfAccount $donorLiability */
        $donorLiability = ChartOfAccount::query()->where('code', '476')->firstOrFail();

        if (! $donorLiability->is_collective) {
            $donorLiability->forceFill([
                'is_collective' => true,
                'allowed_partner_types' => ['supplier'],
            ])->save();
        }

        return [
            'expense_681' => phase9DeprAccount('6811', 'expense', 'debit'),
            'release_845' => phase9DeprAccount('845', 'revenue', 'credit'),
        ];
    }
}

if (! function_exists('phase9DeprCalendar')) {
    /**
     * A calendar-year fiscal year with ALL TWELVE periods open, plus an
     * academic year - depreciation runs and disposals land on arbitrary
     * period ends, unlike the single-period buildCalendar().
     *
     * @return array{fiscal_year_id: int, academic_year_id: int}
     */
    function phase9DeprCalendar(int $year): array
    {
        /** @var FiscalYear|null $existing */
        $existing = FiscalYear::query()
            ->whereDate('starts_on', Carbon::parse($year.'-01-01')->toDateString())
            ->first();

        if ($existing !== null) {
            /** @var object{id: int|string} $academic */
            $academic = DB::table('academic_years')
                ->whereDate('starts_on', '<=', $year.'-06-01')
                ->whereDate('ends_on', '>=', $year.'-06-01')
                ->orderByDesc('id')
                ->first(['id']);

            return [
                'fiscal_year_id' => (int) $existing->getKey(),
                'academic_year_id' => (int) $academic->id,
            ];
        }

        $fiscalYear = FiscalYear::factory()->create([
            'code' => 'FY'.$year.strtoupper(Str::random(4)),
            'starts_on' => Carbon::parse($year.'-01-01')->toDateString(),
            'ends_on' => Carbon::parse($year.'-12-31')->toDateString(),
            'status' => FiscalYearStatus::Open,
        ]);

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::parse(sprintf('%d-%02d-01', $year, $month));

            AccountingPeriod::factory()->create([
                'fiscal_year_id' => $fiscalYear->getKey(),
                'period_month' => $start->toDateString(),
                'starts_on' => $start->toDateString(),
                'ends_on' => $start->copy()->endOfMonth()->toDateString(),
                'status' => AccountingPeriodStatus::Open,
            ]);
        }

        $academicYearId = DB::table('academic_years')->insertGetId([
            'code' => 'AY-'.$year.'-'.Str::random(8),
            'name' => 'Academic year '.$year,
            'starts_on' => Carbon::parse($year.'-01-01')->toDateString(),
            'ends_on' => Carbon::parse($year.'-12-31')->toDateString(),
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'fiscal_year_id' => (int) $fiscalYear->getKey(),
            'academic_year_id' => (int) $academicYearId,
        ];
    }
}

if (! function_exists('phase9DeprPostingRules')) {
    /**
     * The F2 posting rules, through the real SavePostingRule gate:
     *
     *  - asset.depreciated: iterating SIGNED pair - Dr the payload's 681x
     *    per line / Cr its 28x mirror (a §5.5 negative charge flips both);
     *  - asset.disposed: the §6.2 GROSS shape - Dr 28x accumulated, Dr 812
     *    NBV, Cr class-2 cost, Dr 485/treasury proceeds (partner-stamped),
     *    Cr 822 proceeds; zero legs drop via skip_if_zero;
     *  - asset.subsidy.released: Dr 14x / Cr 845 (signed pair);
     *  - asset.subsidy.clawed_back: Dr 14x / Cr donor liability, partner.
     */
    function phase9DeprPostingRules(): void
    {
        $accountant = phase9AssetUser(Permission::LedgerConfigure->value);
        $journal = Journal::factory()->create();
        $save = app(SavePostingRule::class);
        $actor = phase9AssetActor($accountant);

        $save->handle([
            'code' => 'p9f2_asset_depreciated',
            'event' => PostingEvent::AssetDepreciated->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Dotations aux amortissements {run.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.expense_account_id',
                'sign' => LineSign::Signed,
                'amount_expression' => 'item.charge',
                'iterates_over' => 'run.lines',
                'label_expression' => 'Dotation {item.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.accumulated_account_id',
                'sign' => LineSign::Signed,
                'amount_expression' => '0 - item.charge',
                'iterates_over' => 'run.lines',
                'label_expression' => 'Amortissement {item.reference}',
            ],
        ], $actor);

        $save->handle([
            'code' => 'p9f2_asset_disposed',
            'event' => PostingEvent::AssetDisposed->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Cession immobilisation {asset.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.depreciation_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'asset.accumulated_depreciation',
                'label_expression' => 'Sortie amortissements {asset.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.disposal_value_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'asset.net_book_value',
                'label_expression' => 'Valeur comptable cédée {asset.reference}',
            ],
            [
                'sequence' => 3,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.asset_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'asset.cost',
                'label_expression' => 'Sortie immobilisation {asset.reference}',
            ],
            [
                'sequence' => 4,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.settlement_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'asset.proceeds',
                'partner_source' => 'asset.partner',
                'label_expression' => 'Créance sur cession {asset.reference}',
            ],
            [
                'sequence' => 5,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'asset.disposal_proceeds_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'asset.proceeds',
                'label_expression' => 'Produit de cession {asset.reference}',
            ],
        ], $actor);

        $save->handle([
            'code' => 'p9f2_subsidy_released',
            'event' => PostingEvent::AssetSubsidyReleased->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Quote-part subvention {subsidy.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'subsidy.subsidy_account_id',
                'sign' => LineSign::Signed,
                'amount_expression' => 'subsidy.amount',
                'label_expression' => 'Reprise subvention {subsidy.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'subsidy.counterpart_account_id',
                'sign' => LineSign::Signed,
                'amount_expression' => '0 - subsidy.amount',
                'label_expression' => 'Quote-part virée au résultat {subsidy.reference}',
            ],
        ], $actor);

        $save->handle([
            'code' => 'p9f2_subsidy_clawed_back',
            'event' => PostingEvent::AssetSubsidyClawedBack->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Reversement subvention {subsidy.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'subsidy.subsidy_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'subsidy.amount',
                'label_expression' => 'Annulation subvention {subsidy.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'subsidy.counterpart_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'subsidy.amount',
                'partner_source' => 'subsidy.partner',
                'label_expression' => 'Dette envers le donateur {subsidy.reference}',
            ],
        ], $actor);
    }
}

if (! function_exists('phase9DeprBaseline')) {
    /**
     * Rules + accounts + 12-open-period calendar + category (681x wired) +
     * supplier + a user holding the full F2 ability set, signed in last.
     *
     * @param  array<string, mixed>  $categoryOverrides
     * @return array{user: User, actor: Actor, category: AssetCategory, fiscal_year_id: int, academic_year_id: int, year: int, supplier_id: int, accounts: array{expense_681: int, release_845: int}}
     */
    function phase9DeprBaseline(int $year = 2431, array $categoryOverrides = []): array
    {
        phase9AssetPostingRules();
        phase9DeprPostingRules();

        $accounts = phase9DeprAccounts();
        $calendar = phase9DeprCalendar($year);
        $supplierId = phase9AssetSupplierId();

        $category = AssetCategory::factory()->create([
            'depreciation_expense_account_id' => $accounts['expense_681'],
            'useful_life_months' => 120,
            ...$categoryOverrides,
        ]);

        $user = phase9AssetUser(
            AssetPermission::MANAGE,
            AssetPermission::DEPRECIATE,
            AssetPermission::DISPOSE,
            Permission::LedgerPost->value,
        );

        return [
            'user' => $user,
            'actor' => phase9AssetActor($user),
            'category' => $category,
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
            'year' => $year,
            'supplier_id' => $supplierId,
            'accounts' => $accounts,
        ];
    }
}

if (! function_exists('phase9DeprAsset')) {
    /**
     * Register + capitalise an in-service asset through the REAL Actions.
     * Defaults to the §4.4 minibus: 35 775 000 over 120 months, monthly
     * convention, in service 1 September.
     *
     * @param  array{user: User, actor: Actor, category: AssetCategory, fiscal_year_id: int, academic_year_id: int, year: int, supplier_id: int, accounts: array{expense_681: int, release_845: int}}  $baseline
     * @param  array<string, mixed>  $overrides
     */
    function phase9DeprAsset(array $baseline, array $overrides = [], ?string $inServiceDate = null): Asset
    {
        $inServiceDate ??= $baseline['year'].'-09-01';

        $asset = app(RegisterAsset::class)->handle([
            'asset_category_id' => (int) $baseline['category']->getKey(),
            'name' => 'Minibus '.fake()->unique()->numberBetween(1, 999_999),
            'acquisition_date' => $inServiceDate,
            'acquisition_cost' => 35_775_000,
            'cost_basis' => 'ttc_non_recoverable_vat_capitalised',
            'non_recoverable_vat_amount' => 5_775_000,
            'acquisition_type' => 'purchase',
            'supplier_id' => $baseline['supplier_id'],
            'fiscal_year_id' => $baseline['fiscal_year_id'],
            'academic_year_id' => $baseline['academic_year_id'],
            ...$overrides,
        ], $baseline['actor']);

        return app(CapitaliseAsset::class)->handle(
            (int) $asset->getKey(),
            $baseline['actor'],
            $inServiceDate,
        );
    }
}

if (! function_exists('phase9DeprApprove')) {
    /**
     * Approve through a SECOND user (maker/checker), then sign the
     * baseline user back in.
     *
     * @param  array{user: User, actor: Actor}  $baseline
     */
    function phase9DeprApprove(array $baseline, DepreciationRun $run): DepreciationRun
    {
        $approver = phase9AssetUser(AssetPermission::DEPRECIATE);
        $approved = app(ApproveDepreciationRun::class)->handle(
            (int) $run->getKey(),
            phase9AssetActor($approver),
        );

        actingAs($baseline['user']);

        return $approved;
    }
}

if (! function_exists('phase9DeprRunPosted')) {
    /**
     * Calculate + approve + post one period, returning the posted run.
     *
     * @param  array{user: User, actor: Actor, category: AssetCategory, fiscal_year_id: int, academic_year_id: int, year: int, supplier_id: int, accounts: array{expense_681: int, release_845: int}}  $baseline
     */
    function phase9DeprRunPosted(array $baseline, int $periodMonth): DepreciationRun
    {
        $run = app(RunDepreciation::class)->handle(
            $baseline['fiscal_year_id'],
            $periodMonth,
            $baseline['actor'],
        );

        phase9DeprApprove($baseline, $run);

        return app(PostDepreciationRun::class)->handle((int) $run->getKey(), $baseline['actor']);
    }
}

if (! function_exists('phase9DeprLedgerSum')) {
    /** Net movement (debit − credit) posted to an exact account code. */
    function phase9DeprLedgerSum(string $code): int
    {
        /** @var object{d: string|int|null, c: string|int|null}|null $row */
        $row = DB::table('journal_entry_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('chart_of_accounts.code', $code)
            ->selectRaw('SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->first();

        if ($row === null) {
            return 0;
        }

        return (int) ($row->d ?? 0) - (int) ($row->c ?? 0);
    }
}

if (! function_exists('phase9DeprLineFor')) {
    /**
     * The single line matching $code, or a hard failure - mirrors F4's
     * f4PayRow guard against silently indexing a missing/duplicate entry
     * instead of leaving PHPStan (and the reader) staring at a bare
     * array-offset access on a grouped, possibly-absent key.
     *
     * @param  list<object{code: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}>  $lines
     * @return object{code: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}
     */
    function phase9DeprLineFor(array $lines, string $code): object
    {
        foreach ($lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        throw new RuntimeException("no journal line with code {$code} - the fixture did not produce it.");
    }
}
