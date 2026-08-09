<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\ProrataBasis;
use App\Modules\Tax\Models\VatProrata;
use App\Modules\Tax\Models\VatProrataRegularisation;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §5.4.3 - the provisional→definitive
 * annual regularisation. During the year, input VAT was split with the
 * PROVISIONAL prorata; once the DEFINITIVE one is confirmed, the
 * difference on the year's deductions is posted as ONE adjusting entry
 * and recorded in the working-paper table - deductions are never
 * recomputed in place.
 *
 * The working paper (§5.4.3): for every posted supplier-invoice line of
 * the year, `entitled = definitive_rate(tax_amount)` per line (the same
 * Money-rounded application ComputeLineTax used), summed; the delta is
 * `entitled − deducted`. Positive delta = extra deductible VAT to claim;
 * negative = deduction to give back.
 *
 * Posting goes through PostFromEvent (`tax.vat.declared` - the single
 * posting path). The Action passes |delta| and picks the debit/credit
 * accounts by the delta's sign, so ONE configured rule
 * (Dr counterpart / Cr liability) covers both directions:
 *   delta > 0 → Dr deductible-VAT account, Cr adjustment account
 *   delta < 0 → Dr adjustment account,     Cr deductible-VAT account
 *
 * Locks (§9): FOR UPDATE on both VatProrata rows and the fiscal year.
 */
final class RegulariseVatProrata
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly PostFromEvent $postFromEvent,
    ) {}

    public function handle(
        int $fiscalYearId,
        int $deductibleVatAccountId,
        int $adjustmentAccountId,
        Actor $actor,
    ): VatProrataRegularisation {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($fiscalYearId, $deductibleVatAccountId, $adjustmentAccountId, $actor): VatProrataRegularisation {
            // §9: FOR UPDATE on the fiscal year row (cross-module read via
            // DB::table - FiscalYear is Accounting's model).
            /** @var object{id: int|string, code: string, starts_on: string, ends_on: string}|null $fiscalYear */
            $fiscalYear = DB::table('fiscal_years')
                ->where('id', $fiscalYearId)
                ->lockForUpdate()
                ->first(['id', 'code', 'starts_on', 'ends_on']);

            if ($fiscalYear === null) {
                throw new DomainException('Unknown fiscal year.');
            }

            /** @var VatProrata|null $provisional */
            $provisional = VatProrata::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('basis', ProrataBasis::Provisional->value)
                ->lockForUpdate()
                ->first();

            /** @var VatProrata|null $definitive */
            $definitive = VatProrata::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('basis', ProrataBasis::Definitive->value)
                ->lockForUpdate()
                ->first();

            if ($provisional === null || ! $provisional->isConfirmed()) {
                throw new DomainException('No confirmed PROVISIONAL prorata exists for this year; nothing was deducted against one, so there is nothing to regularise (03-tax-procurement §5.4.3).');
            }

            if ($definitive === null || ! $definitive->isConfirmed()) {
                throw new DomainException('The DEFINITIVE prorata is not confirmed yet; compute and confirm it before regularising (03-tax-procurement §5.4.3).');
            }

            if ($provisional->regularisation_entry_id !== null) {
                throw new DomainException(sprintf('Fiscal year %s is already regularised; a second run would double the adjustment.', $fiscalYear->code));
            }

            $workingPaper = $this->workingPaper($fiscalYear, $definitive);
            $delta = $workingPaper['entitled'] - $workingPaper['deducted'];

            $entryId = null;

            if ($delta !== 0) {
                $reference = sprintf('PRORATA-REG/%s', $fiscalYear->code);

                $entry = $this->postFromEvent->handle(
                    'tax.vat.declared',
                    [
                        'declaration' => [
                            'amount' => abs($delta),
                            'reference' => $reference,
                            // The rule credits the liability slot and debits
                            // the counterpart slot; the sign picks which
                            // account sits where (see class docblock).
                            'liability_account_id' => $delta > 0 ? $adjustmentAccountId : $deductibleVatAccountId,
                            'counterpart_account_id' => $delta > 0 ? $deductibleVatAccountId : $adjustmentAccountId,
                        ],
                    ],
                    BusinessDate::today(),
                    $actor,
                    $reference,
                );
                $entryId = (int) $entry->getKey();
            }

            $regularisation = VatProrataRegularisation::query()->create([
                'vat_prorata_id' => $provisional->id,
                'asset_id' => null,
                'regularisation_type' => 'annual_adjustment',
                'amount' => $delta,
                'journal_entry_id' => $entryId,
            ]);

            if ($entryId !== null) {
                $provisional->forceFill(['regularisation_entry_id' => $entryId])->save();
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Tax',
                auditableType: VatProrataRegularisation::class,
                auditableId: (int) $regularisation->getKey(),
                after: [
                    'fiscal_year' => $fiscalYear->code,
                    'deducted' => $workingPaper['deducted'],
                    'entitled' => $workingPaper['entitled'],
                    'delta' => $delta,
                    'journal_entry_id' => $entryId,
                ],
                actor: $actor,
            );

            return $regularisation->refresh();
        });
    }

    /**
     * §5.4.3's working paper: what WAS deducted during the year (the
     * per-line deductible_tax_amount snapshots) versus what the definitive
     * prorata entitles - the definitive rate applied PER LINE with the
     * same Money rounding ComputeLineTax used, so the comparison is
     * franc-exact and conserves.
     *
     * @param  object{id: int|string, code: string, starts_on: string, ends_on: string}  $fiscalYear
     * @return array{deducted: int, entitled: int}
     */
    private function workingPaper(object $fiscalYear, VatProrata $definitive): array
    {
        /** @var list<object{tax_amount: int|string, deductible_tax_amount: int|string}> $lines */
        $lines = DB::table('supplier_invoice_lines')
            ->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_lines.supplier_invoice_id')
            ->whereIn('supplier_invoices.status', ['posted', 'partially_paid', 'paid'])
            ->whereDate('supplier_invoices.invoice_date', '>=', $fiscalYear->starts_on)
            ->whereDate('supplier_invoices.invoice_date', '<=', $fiscalYear->ends_on)
            ->where('supplier_invoice_lines.tax_amount', '>', 0)
            ->orderBy('supplier_invoice_lines.id')
            ->get(['supplier_invoice_lines.tax_amount', 'supplier_invoice_lines.deductible_tax_amount'])
            ->all();

        $deducted = 0;
        $entitled = 0;
        $rate = $definitive->rate();

        foreach ($lines as $line) {
            $deducted += (int) $line->deductible_tax_amount;
            $entitled += $rate->applyTo(Money::of((int) $line->tax_amount))->amount();
        }

        return ['deducted' => $deducted, 'entitled' => $entitled];
    }
}
