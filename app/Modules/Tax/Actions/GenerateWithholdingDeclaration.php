<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Domain\DueRule;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Modules\Tax\Models\TaxDeclarationEntry;
use App\Modules\Tax\Models\TaxDeclarationLine;
use App\Modules\Tax\Models\TaxDeclarationType;
use App\Modules\Tax\Models\TaxObligation;
use App\Modules\Tax\Models\WithholdingAttestation;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §7.3 - the monthly withholding
 * declaration: same mechanics as the TVA one, sourced from the ISSUED
 * WithholdingAttestations of the period and reconciled against the 447
 * liability accounts of the confirmed rules (§6.6 invariant 3):
 *
 *     Σ withheld (issued, non-cancelled attestations)
 *   = the withholding line of the declaration
 *   = the period's movement on the rules' liability accounts,
 *
 * and a mismatch BLOCKS generation - a manually inserted 447 movement
 * without an attestation is exactly the §11.8 case. The declaration
 * carries the per-supplier annex (name, NIU, base, rate, withheld) that
 * the form requires and that cannot be reconstructed later.
 */
final class GenerateWithholdingDeclaration
{
    public const PERMISSION = Permission::TaxDeclare->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $periodYear, int $periodMonth, Actor $actor, ?TaxDeclaration $amends = null): TaxDeclaration
    {
        Gate::authorize(self::PERMISSION);

        if ($periodMonth < 1 || $periodMonth > 12) {
            throw new DomainException('The withholding declaration period month must be between 1 and 12.');
        }

        $type = TaxDeclarationType::query()
            ->where('code', DeclarationTypeCode::WithholdingMonthly->value)
            ->where('is_archived', false)
            ->first();

        if ($type === null) {
            throw new DomainException(
                'The withholding_monthly declaration type is not configured (the reference list ships empty - 03-tax-procurement §7.1). Create it with your accountant before generating.'
            );
        }

        /** @var object{id: int|string, fiscal_year_id: int|string, status: string}|null $period */
        $period = DB::table('accounting_periods')
            ->whereDate('period_month', sprintf('%04d-%02d-01', $periodYear, $periodMonth))
            ->first(['id', 'fiscal_year_id', 'status']);

        if ($period === null) {
            throw new DomainException(sprintf('No accounting period exists for %04d-%02d; open the fiscal year first.', $periodYear, $periodMonth));
        }

        if ((string) $period->status === 'open') {
            throw new DomainException(sprintf(
                'Accounting period %04d-%02d is still OPEN; soft-lock it before declaring (03-tax-procurement §7.2 step 2).',
                $periodYear,
                $periodMonth,
            ));
        }

        $lockName = sprintf('opes.tax_declaration.%s.%04d-%02d', DeclarationTypeCode::WithholdingMonthly->value, $periodYear, $periodMonth);
        $this->acquireAdvisoryLock($lockName);

        try {
            return DB::transaction(function () use ($periodYear, $periodMonth, $actor, $amends, $period): TaxDeclaration {
                /** @var TaxDeclaration|null $existing */
                $existing = TaxDeclaration::query()
                    ->where('declaration_type', DeclarationTypeCode::WithholdingMonthly->value)
                    ->where('period_year', $periodYear)
                    ->where('period_month', $periodMonth)
                    ->where('period_slot', $amends === null ? 0 : $amends->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && $existing->status->occupiesPeriod()) {
                    throw new DomainException(sprintf(
                        'A withholding declaration for %04d-%02d already exists (status %s); cancel it to regenerate (03-tax-procurement §7.2 step 1).',
                        $periodYear,
                        $periodMonth,
                        $existing->status->value,
                    ));
                }

                $collection = $this->collectFor($periodYear, $periodMonth);

                $declaration = $existing ?? TaxDeclaration::query()->create([
                    'declaration_type' => DeclarationTypeCode::WithholdingMonthly->value,
                    'period_type' => 'month',
                    'period_year' => $periodYear,
                    'period_month' => $periodMonth,
                    'fiscal_year_id' => (int) $period->fiscal_year_id,
                    'status' => DeclarationStatus::Draft->value,
                    'amends_declaration_id' => $amends?->id,
                ]);

                $declaration->forceFill([
                    'status' => DeclarationStatus::Generated->value,
                    'generated_at' => now(),
                    'generated_by' => $actor->id,
                    'amount_declared' => $collection['total_withheld'],
                    'due_date' => $this->dueDateFor($periodYear, $periodMonth),
                    'generated_from_entry_ids' => array_values(array_unique(array_column($collection['ledger_lines'], 'journal_entry_id'))),
                    'inputs_hash' => $collection['hash'],
                    'notes' => null,
                    'filed_at' => null,
                    'filed_by' => null,
                    'filing_channel' => null,
                    'external_reference' => null,
                ])->save();

                $this->writeLines($declaration, $collection);
                $this->writePivot($declaration, $collection['ledger_lines']);

                // Stamp the attestations into the declaration (§6.6 - a
                // lifecycle move, permitted after issue).
                WithholdingAttestation::query()
                    ->whereIn('id', array_column($collection['attestations'], 'id'))
                    ->get()
                    ->each(function (WithholdingAttestation $attestation) use ($declaration): void {
                        $attestation->forceFill(['tax_declaration_id' => $declaration->id])->save();
                    });

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Tax',
                    auditableType: TaxDeclaration::class,
                    auditableId: (int) $declaration->getKey(),
                    after: [
                        'declaration_type' => DeclarationTypeCode::WithholdingMonthly->value,
                        'period' => sprintf('%04d-%02d', $periodYear, $periodMonth),
                        'total_withheld' => $collection['total_withheld'],
                        'attestations' => count($collection['attestations']),
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

    /** Re-verified at filing - see GenerateTvaDeclaration::currentInputsHash. */
    public function currentInputsHash(TaxDeclaration $declaration): string
    {
        return $this->collectFor($declaration->period_year, $declaration->period_month)['hash'];
    }

    /**
     * @return array{
     *     total_withheld: int,
     *     attestations: list<array{id: int, supplier_id: int, base_amount: int, rate_bp_applied: int, withheld_amount: int}>,
     *     annex: list<array{supplier_id: int, supplier_name: string, supplier_niu: string|null, base: int, rate_bp: int, withheld: int}>,
     *     ledger_lines: list<array{id: int, journal_entry_id: int, account_id: int, debit: int, credit: int}>,
     *     hash: string,
     * }
     */
    private function collectFor(int $periodYear, int $periodMonth): array
    {
        /** @var list<WithholdingAttestation> $attestations */
        $attestations = WithholdingAttestation::query()
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->where('status', AttestationStatus::Issued->value)
            ->orderBy('id')
            ->get()
            ->all();

        $totalWithheld = 0;
        $attestationTuples = [];
        $bySupplierRate = [];

        foreach ($attestations as $attestation) {
            $totalWithheld += $attestation->withheld_amount;
            $attestationTuples[] = [
                'id' => (int) $attestation->id,
                'supplier_id' => (int) $attestation->supplier_id,
                'base_amount' => (int) $attestation->base_amount,
                'rate_bp_applied' => (int) $attestation->rate_bp_applied,
                'withheld_amount' => (int) $attestation->withheld_amount,
            ];

            $key = $attestation->supplier_id.':'.$attestation->rate_bp_applied;
            $bySupplierRate[$key] ??= [
                'supplier_id' => (int) $attestation->supplier_id,
                'rate_bp' => (int) $attestation->rate_bp_applied,
                'base' => 0,
                'withheld' => 0,
            ];
            $bySupplierRate[$key]['base'] += $attestation->base_amount;
            $bySupplierRate[$key]['withheld'] += $attestation->withheld_amount;
        }

        // The 447 side: every liability account of a confirmed rule.
        /** @var list<int> $liabilityAccounts */
        $liabilityAccounts = WithholdingRule::query()
            ->whereNotNull('confirmed_at')
            ->whereNotNull('liability_account_id')
            ->pluck('liability_account_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();

        $start = sprintf('%04d-%02d-01', $periodYear, $periodMonth);
        $end = \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString();

        $ledgerLines = [];
        $ledgerMovement = 0;

        if ($liabilityAccounts !== []) {
            /** @var list<object{id: int, journal_entry_id: int, account_id: int, debit: int, credit: int}> $rows */
            $rows = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->whereIn('journal_entry_lines.account_id', $liabilityAccounts)
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

            foreach ($rows as $row) {
                $line = [
                    'id' => (int) $row->id,
                    'journal_entry_id' => (int) $row->journal_entry_id,
                    'account_id' => (int) $row->account_id,
                    'debit' => (int) $row->debit,
                    'credit' => (int) $row->credit,
                ];
                $ledgerLines[] = $line;
                $ledgerMovement += $line['credit'] - $line['debit'];
            }
        }

        // §6.6 invariant 3 / §11.8: Σ attestations must equal the 447
        // movement, and a mismatch BLOCKS - in either direction.
        if ($ledgerMovement !== $totalWithheld) {
            throw new DomainException(sprintf(
                'Withholding does not reconcile for %04d-%02d: attestations total %d but the liability (447) accounts moved %d. '
                .'A 447 movement without an attestation - or an attestation never posted - must be resolved before declaring (03-tax-procurement §6.6 invariant 3, §11.8).',
                $periodYear,
                $periodMonth,
                $totalWithheld,
                $ledgerMovement,
            ));
        }

        // §7.3 per-supplier annex: snapshot name and NIU now.
        $supplierIds = array_values(array_unique(array_column($attestationTuples, 'supplier_id')));
        /** @var array<int, object{id: int, name: string, niu: string|null}> $suppliers */
        $suppliers = DB::table('suppliers')
            ->whereIn('id', $supplierIds)
            ->get(['id', 'name', 'niu'])
            ->keyBy('id')
            ->all();

        $annex = [];

        foreach ($bySupplierRate as $row) {
            $supplier = $suppliers[$row['supplier_id']] ?? null;

            $annex[] = [
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $supplier->name ?? ('Supplier #'.$row['supplier_id']),
                'supplier_niu' => $supplier?->niu,
                'base' => $row['base'],
                'rate_bp' => $row['rate_bp'],
                'withheld' => $row['withheld'],
            ];
        }

        usort($annex, static fn (array $a, array $b): int => [$a['supplier_name'], $a['rate_bp']] <=> [$b['supplier_name'], $b['rate_bp']]);

        return [
            'total_withheld' => $totalWithheld,
            'attestations' => $attestationTuples,
            'annex' => $annex,
            'ledger_lines' => $ledgerLines,
            'hash' => hash('sha256', (string) json_encode(['attestations' => $attestationTuples, 'ledger' => $ledgerLines])),
        ];
    }

    /**
     * @param  array{total_withheld: int, annex: list<array{supplier_id: int, supplier_name: string, supplier_niu: string|null, base: int, rate_bp: int, withheld: int}>}  $collection
     */
    private function writeLines(TaxDeclaration $declaration, array $collection): void
    {
        TaxDeclarationLine::query()->where('tax_declaration_id', $declaration->id)->delete();

        TaxDeclarationLine::query()->create([
            'tax_declaration_id' => $declaration->id,
            'line_no' => 1,
            'line_code' => 'WH_TOTAL',
            'label' => 'Retenues à la source du mois',
            'base_amount' => array_sum(array_column($collection['annex'], 'base')),
            'rate_bp' => null,
            'tax_amount' => $collection['total_withheld'],
            'source' => 'computed',
        ]);

        foreach ($collection['annex'] as $index => $row) {
            TaxDeclarationLine::query()->create([
                'tax_declaration_id' => $declaration->id,
                'line_no' => $index + 2,
                'line_code' => 'WH_ANNEX',
                'label' => $row['supplier_name'],
                'base_amount' => $row['base'],
                'rate_bp' => $row['rate_bp'],
                'tax_amount' => $row['withheld'],
                'source' => 'computed',
                'supplier_id' => $row['supplier_id'],
                'supplier_name' => $row['supplier_name'],
                'supplier_niu' => $row['supplier_niu'],
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

    private function dueDateFor(int $periodYear, int $periodMonth): ?string
    {
        /** @var TaxObligation|null $obligation */
        $obligation = TaxObligation::query()
            ->where('is_archived', false)
            ->whereHas('declarationType', function ($query): void {
                $query->where('code', DeclarationTypeCode::WithholdingMonthly->value);
            })
            ->first();

        if ($obligation === null) {
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
