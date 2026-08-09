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
use App\Modules\Tax\Models\TaxDeclaration;
use App\Modules\Tax\Models\TaxDeclarationLine;
use App\Modules\Tax\Models\TaxObligation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §7.5 - the DSF generator: a MAPPER
 * over `ChartOfAccount.dsf_line_code`, never a second accounting engine.
 * Every figure is a trial-balance aggregate; the reconciliation
 * Σ mapped = Σ trial balance holds BY CONSTRUCTION because an account
 * with movement and no `dsf_line_code` BLOCKS generation, listed by name
 * (§11.11) - a silently dropped account is a wrong DSF that looks
 * complete.
 *
 * The due date comes from the seeded (verified, §7.5) DSF obligation's
 * due_rule and the confirmed fiscal identity's tax_centre_type -
 * 15 March (DGE) / 15 April (CIME) / 15 May (others) of year+1.
 *
 * Filing is NOT here: RecordDsfFiling runs the pre-filing checklist.
 */
final class GenerateDsf
{
    public const PERMISSION = Permission::TaxDeclare->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $fiscalYearId, Actor $actor): TaxDeclaration
    {
        Gate::authorize(self::PERMISSION);

        /** @var object{id: int|string, code: string, status: string, ends_on: string}|null $fiscalYear */
        $fiscalYear = DB::table('fiscal_years')
            ->where('id', $fiscalYearId)
            ->first(['id', 'code', 'status', 'ends_on']);

        if ($fiscalYear === null) {
            throw new DomainException('Unknown fiscal year.');
        }

        if (! in_array($fiscalYear->status, ['closing', 'closed'], true)) {
            throw new DomainException(sprintf(
                'Fiscal year %s is %s; the DSF is generated from a year in clôture (closing or closed) - a DSF from a year still posting changes under the filer (03-tax-procurement §7.5).',
                $fiscalYear->code,
                $fiscalYear->status,
            ));
        }

        $identity = FiscalIdentity::current();

        if ($identity === null || ! $identity->isConfirmed() || $identity->tax_centre_type === null) {
            throw new DomainException(
                'The fiscal identity (with its tax centre type) must be confirmed before generating the DSF - tax_centre_type selects the due date (03-tax-procurement §2.1/§7.6).'
            );
        }

        $periodYear = (int) \Illuminate\Support\Carbon::parse($fiscalYear->ends_on)->format('Y');

        return DB::transaction(function () use ($fiscalYearId, $periodYear, $identity, $actor, $fiscalYear): TaxDeclaration {
            /** @var TaxDeclaration|null $existing */
            $existing = TaxDeclaration::query()
                ->where('declaration_type', DeclarationTypeCode::DsfAnnual->value)
                ->where('period_year', $periodYear)
                ->where('period_month', 0)
                ->where('period_slot', 0)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status->occupiesPeriod()) {
                throw new DomainException(sprintf(
                    'A DSF for %d already exists (status %s); cancel it to regenerate, or file an amending declaration (03-tax-procurement §7.5).',
                    $periodYear,
                    $existing->status->value,
                ));
            }

            $mapping = $this->mappedBalancesFor($fiscalYearId);

            $dueDate = $this->dueDate($periodYear, $identity);

            $declaration = $existing ?? TaxDeclaration::query()->create([
                'declaration_type' => DeclarationTypeCode::DsfAnnual->value,
                'period_type' => 'year',
                'period_year' => $periodYear,
                'period_month' => 0,
                'fiscal_year_id' => $fiscalYearId,
                'status' => DeclarationStatus::Draft->value,
            ]);

            $declaration->forceFill([
                'status' => DeclarationStatus::Generated->value,
                'generated_at' => now(),
                'generated_by' => $actor->id,
                'amount_declared' => 0,
                'due_date' => $dueDate,
                'inputs_hash' => $mapping['hash'],
                'notes' => sprintf(
                    'Mapper reconciliation: Σ mapped movement (signed) = %d over %d mapped accounts of fiscal year %s = Σ trial balance by construction (03-tax-procurement §7.5). Statutory due date shown without weekend/holiday adjustment (roll-forward NEEDS VERIFICATION, §7.6).',
                    $mapping['total'],
                    count($mapping['accounts']),
                    $fiscalYear->code,
                ),
                'filed_at' => null,
                'filed_by' => null,
                'filing_channel' => null,
                'external_reference' => null,
            ])->save();

            TaxDeclarationLine::query()->where('tax_declaration_id', $declaration->id)->delete();

            $lineNo = 1;

            foreach ($mapping['lines'] as $line) {
                TaxDeclarationLine::query()->create([
                    'tax_declaration_id' => $declaration->id,
                    'line_no' => $lineNo++,
                    'line_code' => $line['dsf_line_code'],
                    'label' => $line['label'],
                    'base_amount' => $line['balance'],
                    'rate_bp' => null,
                    'tax_amount' => 0,
                    'source' => 'computed',
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Tax',
                auditableType: TaxDeclaration::class,
                auditableId: (int) $declaration->getKey(),
                after: [
                    'declaration_type' => DeclarationTypeCode::DsfAnnual->value,
                    'period_year' => $periodYear,
                    'lines' => count($mapping['lines']),
                    'inputs_hash' => $mapping['hash'],
                ],
                actor: $actor,
            );

            return $declaration->refresh();
        });
    }

    /** Re-verified by RecordDsfFiling before the filing is recorded. */
    public function currentInputsHash(TaxDeclaration $declaration): string
    {
        return $this->mappedBalancesFor($declaration->fiscal_year_id)['hash'];
    }

    /**
     * Trial-balance movement per account for the year, folded onto the
     * dsf_line_code mapping. Cross-module reads via DB::table only.
     *
     * @return array{
     *     accounts: list<array{account_id: int, code: string, dsf_line_code: string, balance: int}>,
     *     lines: list<array{dsf_line_code: string, label: string, balance: int}>,
     *     total: int,
     *     hash: string,
     * }
     */
    private function mappedBalancesFor(int $fiscalYearId): array
    {
        /** @var list<object{account_id: int, total_debit: string, total_credit: string}> $movements */
        $movements = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->groupBy('journal_entry_lines.account_id')
            ->orderBy('journal_entry_lines.account_id')
            ->get([
                'journal_entry_lines.account_id',
                DB::raw('SUM(journal_entry_lines.debit) AS total_debit'),
                DB::raw('SUM(journal_entry_lines.credit) AS total_credit'),
            ])
            ->all();

        if ($movements === []) {
            throw new DomainException('The fiscal year has no posted movement; there is nothing to map into a DSF.');
        }

        $accountIds = array_map(static fn (object $row): int => (int) $row->account_id, $movements);

        /** @var array<int, object{id: int, code: string, name: string, dsf_line_code: string|null, dsf_statement: string|null}> $accounts */
        $accounts = DB::table('chart_of_accounts')
            ->whereIn('id', $accountIds)
            ->get(['id', 'code', 'name', 'dsf_line_code', 'dsf_statement'])
            ->keyBy('id')
            ->all();

        $unmapped = [];
        $mapped = [];
        $byLineCode = [];
        $total = 0;

        foreach ($movements as $movement) {
            $account = $accounts[(int) $movement->account_id] ?? null;

            if ($account === null) {
                throw new DomainException(sprintf('Account #%d has movement but no chart row; the ledger is inconsistent.', (int) $movement->account_id));
            }

            // MySQL SUM() comes back as a string - (int)-cast per house rule.
            $balance = (int) $movement->total_debit - (int) $movement->total_credit;

            if ($account->dsf_line_code === null || trim($account->dsf_line_code) === '') {
                $unmapped[] = sprintf('%s — %s', $account->code, $account->name);

                continue;
            }

            $mapped[] = [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'dsf_line_code' => $account->dsf_line_code,
                'balance' => $balance,
            ];

            $key = $account->dsf_line_code;
            $byLineCode[$key] ??= [
                'dsf_line_code' => $key,
                'label' => 'DSF '.$key.($account->dsf_statement !== null ? ' ('.$account->dsf_statement.')' : ''),
                'balance' => 0,
            ];
            $byLineCode[$key]['balance'] += $balance;
            $total += $balance;
        }

        // §11.11: the unmapped accounts are NAMED - a silently dropped
        // account is a wrong DSF that looks complete.
        if ($unmapped !== []) {
            throw new DomainException(sprintf(
                'DSF generation is blocked: %d account(s) with movement carry no dsf_line_code mapping: %s (03-tax-procurement §7.5, §11.11). Map every account before generating.',
                count($unmapped),
                implode('; ', $unmapped),
            ));
        }

        ksort($byLineCode);

        return [
            'accounts' => $mapped,
            'lines' => array_values($byLineCode),
            'total' => $total,
            'hash' => hash('sha256', (string) json_encode($mapped)),
        ];
    }

    private function dueDate(int $periodYear, FiscalIdentity $identity): ?string
    {
        /** @var TaxObligation|null $obligation */
        $obligation = TaxObligation::query()
            ->where('is_archived', false)
            ->whereHas('declarationType', function ($query): void {
                $query->where('code', DeclarationTypeCode::DsfAnnual->value);
            })
            ->first();

        if ($obligation === null) {
            return null;
        }

        return DueRule::parse($obligation->due_rule)
            ->dueDateFor($periodYear, 0, $identity->tax_centre_type)
            ->toDateString();
    }
}
