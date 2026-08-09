<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Domain\DueRule;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxCode;
use App\Modules\Tax\Models\TaxCredit;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Modules\Tax\Models\TaxDeclarationEntry;
use App\Modules\Tax\Models\TaxDeclarationLine;
use App\Modules\Tax\Models\TaxDeclarationType;
use App\Modules\Tax\Models\TaxObligation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §7.2 - generate the monthly TVA
 * declaration for (year, month):
 *
 * 1. Refuse if a non-cancelled declaration for the period exists (the DB
 *    unique is the backstop; this gives the readable error). A CANCELLED
 *    one is reused in place, keeping the unique key strict.
 * 2. Refuse if the period's AccountingPeriod is not at least soft-locked -
 *    declaring from a period still accepting entries produces a figure
 *    that changes after filing.
 * 3. Output TVA: posted JournalEntryLines on the collected accounts of
 *    active TaxCodes within the period (credit − debit).
 * 4. Input TVA: same, on the deductible accounts (debit − credit). The
 *    prorata was applied AT LINE LEVEL by ComputeLineTax - the deductible
 *    account already holds only the deductible share; the declaration
 *    does NOT re-apply it (§7.2 step 5).
 * 5. net = output − deductible − credit_carried_forward; a negative net
 *    becomes a TaxCredit carried forward, and the credits it absorbed are
 *    marked consumed.
 * 6. inputs_hash (SHA-256 over the contributing line set) is stored and
 *    re-verified at filing.
 *
 * Concurrency (§9): advisory lock on (declaration_type, period) plus
 * FOR UPDATE on the declaration row.
 *
 * Empty-and-blocking (00-core §16): the `tva_monthly` reference type row
 * is NOT seeded - the accountant creates it (with its form-box mapping
 * once verified) before the first declaration can generate.
 */
final class GenerateTvaDeclaration
{
    public const PERMISSION = Permission::TaxDeclare->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $periodYear, int $periodMonth, Actor $actor, ?TaxDeclaration $amends = null): TaxDeclaration
    {
        Gate::authorize(self::PERMISSION);

        if ($periodMonth < 1 || $periodMonth > 12) {
            throw new DomainException('The TVA declaration period month must be between 1 and 12.');
        }

        $type = TaxDeclarationType::query()
            ->where('code', DeclarationTypeCode::TvaMonthly->value)
            ->where('is_archived', false)
            ->first();

        if ($type === null) {
            throw new DomainException(
                'The tva_monthly declaration type is not configured (the reference list ships empty - 03-tax-procurement §7.1). Create it with your accountant before generating.'
            );
        }

        $identity = FiscalIdentity::current();

        if ($identity === null || ! $identity->isConfirmed()) {
            throw new DomainException(
                'The fiscal identity is not confirmed; a TVA declaration cannot name its declarant (03-tax-procurement §2.2).'
            );
        }

        $period = $this->lockedPeriodFor($periodYear, $periodMonth);

        $lockName = sprintf('opes.tax_declaration.%s.%04d-%02d', DeclarationTypeCode::TvaMonthly->value, $periodYear, $periodMonth);
        $this->acquireAdvisoryLock($lockName);

        try {
            return DB::transaction(function () use ($periodYear, $periodMonth, $actor, $amends, $period): TaxDeclaration {
                $declaration = $this->claimPeriodRow(
                    DeclarationTypeCode::TvaMonthly->value,
                    $periodYear,
                    $periodMonth,
                    (int) $period->fiscal_year_id,
                    $amends,
                );

                $collection = $this->collectFor($periodYear, $periodMonth);

                // §7.4: an unfiled prior-period declaration is a WARNING on
                // the next generation run, never a silent pass.
                $warning = $this->unfiledPriorPeriodWarning($periodYear, $periodMonth);

                // §7.2 step 6: absorb open credits from earlier periods.
                /** @var list<TaxCredit> $openCredits */
                $openCredits = TaxCredit::query()
                    ->open()
                    ->where(function ($query) use ($periodYear, $periodMonth): void {
                        $query->where('period_year', '<', $periodYear)
                            ->orWhere(function ($query) use ($periodYear, $periodMonth): void {
                                $query->where('period_year', $periodYear)
                                    ->where('period_month', '<', $periodMonth);
                            });
                    })
                    ->lockForUpdate()
                    ->get()
                    ->all();

                $creditCarried = 0;

                foreach ($openCredits as $credit) {
                    $creditCarried += $credit->amount;
                }

                $net = $collection['output'] - $collection['deductible'] - $creditCarried;

                $declaration->forceFill([
                    'status' => DeclarationStatus::Generated->value,
                    'generated_at' => now(),
                    'generated_by' => $actor->id,
                    'amount_declared' => max($net, 0),
                    'due_date' => $this->dueDateFor($periodYear, $periodMonth),
                    'generated_from_entry_ids' => array_values(array_unique(array_column($collection['lines'], 'journal_entry_id'))),
                    'inputs_hash' => $collection['hash'],
                    'notes' => $warning,
                    'filed_at' => null,
                    'filed_by' => null,
                    'filing_channel' => null,
                    'external_reference' => null,
                ])->save();

                $this->writeLines($declaration, $collection['output'], $collection['deductible'], $creditCarried, $net);
                $this->writePivot($declaration, $collection['lines']);

                foreach ($openCredits as $credit) {
                    $credit->forceFill(['consumed_in_declaration_id' => $declaration->id])->save();
                }

                if ($net < 0) {
                    TaxCredit::query()->create([
                        'fiscal_year_id' => $declaration->fiscal_year_id,
                        'period_year' => $periodYear,
                        'period_month' => $periodMonth,
                        'amount' => -$net,
                        'source_declaration_id' => $declaration->id,
                    ]);
                }

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Tax',
                    auditableType: TaxDeclaration::class,
                    auditableId: (int) $declaration->getKey(),
                    after: [
                        'declaration_type' => DeclarationTypeCode::TvaMonthly->value,
                        'period' => sprintf('%04d-%02d', $periodYear, $periodMonth),
                        'output' => $collection['output'],
                        'deductible' => $collection['deductible'],
                        'credit_carried' => $creditCarried,
                        'net' => $net,
                        'inputs_hash' => $collection['hash'],
                    ],
                    actor: $actor,
                );

                return $declaration->refresh();
            });
        } finally {
            $this->releaseAdvisoryLock($lockName);
        }
    }

    /**
     * Recompute the hash over TODAY'S ledger - FileTaxDeclaration compares
     * it to the stored one so filing fails if the ledger changed
     * underneath (§7.1 inputs_hash discipline).
     */
    public function currentInputsHash(TaxDeclaration $declaration): string
    {
        return $this->collectFor($declaration->period_year, $declaration->period_month)['hash'];
    }

    /**
     * The posted ledger lines feeding the period's declaration.
     *
     * @return array{output: int, deductible: int, lines: list<array{id: int, journal_entry_id: int, account_id: int, debit: int, credit: int}>, hash: string}
     */
    private function collectFor(int $periodYear, int $periodMonth): array
    {
        $start = sprintf('%04d-%02d-01', $periodYear, $periodMonth);
        $end = \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString();

        $collectedAccounts = TaxCode::query()
            ->where('is_active', true)
            ->whereNotNull('collected_account_id')
            ->pluck('collected_account_id')->unique()->values()->all();

        $deductibleAccounts = TaxCode::query()
            ->where('is_active', true)
            ->whereNotNull('deductible_account_id')
            ->pluck('deductible_account_id')->unique()->values()->all();

        if ($collectedAccounts === [] && $deductibleAccounts === []) {
            throw new DomainException(
                'No active tax code carries a collected or deductible account; there is nothing to declare from. Configure the tax codes with your accountant first (03-tax-procurement §5.3, empty-seed refusal §11.16).'
            );
        }

        /** @var list<int> $accountIds */
        $accountIds = array_values(array_unique(array_map(
            'intval',
            array_merge($collectedAccounts, $deductibleAccounts),
        )));

        // Cross-module read of Accounting's tables via DB::table (00-core
        // §6.2) - never its models.
        /** @var list<object{id: int, journal_entry_id: int, account_id: int, debit: int, credit: int}> $rows */
        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereDate('journal_entries.date', '>=', $start)
            ->whereDate('journal_entries.date', '<=', $end)
            ->orderBy('journal_entry_lines.id')
            ->get([
                'journal_entry_lines.id',
                'journal_entry_lines.journal_entry_id',
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            ])
            ->all();

        $collectedSet = array_map('intval', $collectedAccounts);
        $deductibleSet = array_map('intval', $deductibleAccounts);

        $output = 0;
        $deductible = 0;
        $lines = [];

        foreach ($rows as $row) {
            $line = [
                'id' => (int) $row->id,
                'journal_entry_id' => (int) $row->journal_entry_id,
                'account_id' => (int) $row->account_id,
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
            ];
            $lines[] = $line;

            if (in_array($line['account_id'], $collectedSet, true)) {
                $output += $line['credit'] - $line['debit'];
            }

            if (in_array($line['account_id'], $deductibleSet, true)) {
                $deductible += $line['debit'] - $line['credit'];
            }
        }

        return [
            'output' => $output,
            'deductible' => $deductible,
            'lines' => $lines,
            'hash' => hash('sha256', (string) json_encode($lines)),
        ];
    }

    private function writeLines(TaxDeclaration $declaration, int $output, int $deductible, int $creditCarried, int $net): void
    {
        TaxDeclarationLine::query()->where('tax_declaration_id', $declaration->id)->delete();

        // INTERNAL codes - the official DGI box mapping is NEEDS
        // VERIFICATION and lives in tax_declaration_types.form_boxes; until
        // configured, the declaration shows the banner and cannot be filed.
        $rows = [
            ['line_code' => 'TVA_OUTPUT', 'label' => 'TVA collectée (output)', 'tax_amount' => $output],
            ['line_code' => 'TVA_INPUT_DEDUCTIBLE', 'label' => 'TVA déductible (input, prorata déjà appliqué)', 'tax_amount' => $deductible],
            ['line_code' => 'TVA_CREDIT_CARRIED', 'label' => 'Crédit de TVA reporté', 'tax_amount' => $creditCarried],
            ['line_code' => 'TVA_NET', 'label' => $net >= 0 ? 'TVA nette à payer' : 'Crédit de TVA à reporter', 'tax_amount' => $net],
        ];

        foreach ($rows as $index => $row) {
            TaxDeclarationLine::query()->create([
                'tax_declaration_id' => $declaration->id,
                'line_no' => $index + 1,
                'line_code' => $row['line_code'],
                'label' => $row['label'],
                'base_amount' => 0,
                'rate_bp' => null,
                'tax_amount' => $row['tax_amount'],
                'source' => 'computed',
            ]);
        }
    }

    /**
     * @param  list<array{id: int, journal_entry_id: int, account_id: int, debit: int, credit: int}>  $lines
     */
    private function writePivot(TaxDeclaration $declaration, array $lines): void
    {
        TaxDeclarationEntry::query()->where('tax_declaration_id', $declaration->id)->delete();

        foreach ($lines as $line) {
            TaxDeclarationEntry::query()->create([
                'tax_declaration_id' => $declaration->id,
                'journal_entry_id' => $line['journal_entry_id'],
                'journal_entry_line_id' => $line['id'],
            ]);
        }
    }

    /**
     * §7.2 steps 1-2 plus the amendment slot: locks (FOR UPDATE) and
     * returns the declaration row for the period - a fresh one, a reused
     * cancelled one, or the amendment row.
     */
    private function claimPeriodRow(string $typeCode, int $periodYear, int $periodMonth, int $fiscalYearId, ?TaxDeclaration $amends): TaxDeclaration
    {
        /** @var TaxDeclaration|null $existing */
        $existing = TaxDeclaration::query()
            ->where('declaration_type', $typeCode)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->where('period_slot', $amends?->id ?? 0)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && $existing->status->occupiesPeriod()) {
            throw new DomainException(sprintf(
                'A %s declaration for %04d-%02d already exists (status %s). Cancel it to regenerate, or amend it once filed (03-tax-procurement §7.2 step 1).',
                $typeCode,
                $periodYear,
                $periodMonth,
                $existing->status->value,
            ));
        }

        if ($existing !== null) {
            return $existing;
        }

        return TaxDeclaration::query()->create([
            'declaration_type' => $typeCode,
            'period_type' => 'month',
            'period_year' => $periodYear,
            'period_month' => $periodMonth,
            'fiscal_year_id' => $fiscalYearId,
            'status' => DeclarationStatus::Draft->value,
            'amends_declaration_id' => $amends?->id,
        ]);
    }

    /**
     * §7.2 step 2: the AccountingPeriod must be at least soft-locked.
     * Cross-module read via DB::table.
     *
     * @return object{id: int|string, fiscal_year_id: int|string, status: string}
     */
    private function lockedPeriodFor(int $periodYear, int $periodMonth): object
    {
        /** @var object{id: int|string, fiscal_year_id: int|string, status: string}|null $period */
        $period = DB::table('accounting_periods')
            ->whereDate('period_month', sprintf('%04d-%02d-01', $periodYear, $periodMonth))
            ->first(['id', 'fiscal_year_id', 'status']);

        if ($period === null) {
            throw new DomainException(sprintf(
                'No accounting period exists for %04d-%02d; open the fiscal year first.',
                $periodYear,
                $periodMonth,
            ));
        }

        if ($period->status === 'open') {
            throw new DomainException(sprintf(
                'Accounting period %04d-%02d is still OPEN. Declaring from a period still accepting entries produces a figure that changes after filing - soft-lock it first (03-tax-procurement §7.2 step 2, 02-accounting C8).',
                $periodYear,
                $periodMonth,
            ));
        }

        return $period;
    }

    private function unfiledPriorPeriodWarning(int $periodYear, int $periodMonth): ?string
    {
        /** @var TaxDeclaration|null $unfiled */
        $unfiled = TaxDeclaration::query()
            ->where('declaration_type', DeclarationTypeCode::TvaMonthly->value)
            ->whereNotIn('status', [
                DeclarationStatus::Filed->value,
                DeclarationStatus::Paid->value,
                DeclarationStatus::Amended->value,
                DeclarationStatus::Cancelled->value,
            ])
            ->where(function ($query) use ($periodYear, $periodMonth): void {
                $query->where('period_year', '<', $periodYear)
                    ->orWhere(function ($query) use ($periodYear, $periodMonth): void {
                        $query->where('period_year', $periodYear)
                            ->where('period_month', '<', $periodMonth);
                    });
            })
            ->orderBy('period_year')->orderBy('period_month')
            ->first();

        if ($unfiled === null) {
            return null;
        }

        return sprintf(
            'WARNING: the %04d-%02d TVA declaration is generated but NOT FILED (03-tax-procurement §7.4).',
            $unfiled->period_year,
            $unfiled->period_month,
        );
    }

    private function dueDateFor(int $periodYear, int $periodMonth): ?string
    {
        /** @var TaxObligation|null $obligation */
        $obligation = TaxObligation::query()
            ->where('is_archived', false)
            ->whereHas('declarationType', function ($query): void {
                $query->where('code', DeclarationTypeCode::TvaMonthly->value);
            })
            ->first();

        if ($obligation === null) {
            // The TVA deadline is NEEDS VERIFICATION and never assumed
            // (§7.4); without a configured obligation there is no due date.
            return null;
        }

        return DueRule::parse($obligation->due_rule)
            ->dueDateFor($periodYear, $periodMonth, FiscalIdentity::current()?->tax_centre_type)
            ->toDateString();
    }

    private function acquireAdvisoryLock(string $name): void
    {
        /** @var object{acquired: int|null}|null $row */
        $row = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);

        if ($row === null || (int) $row->acquired !== 1) {
            throw new DomainException('Another declaration generation for this period is in progress; retry shortly (03-tax-procurement §9).');
        }
    }

    private function releaseAdvisoryLock(string $name): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }
}
