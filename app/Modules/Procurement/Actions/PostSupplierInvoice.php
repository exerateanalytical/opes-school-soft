<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierInvoiceLine;
use App\Modules\Tax\Actions\IssueWithholdingAttestation;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.6 - land an APPROVED invoice in the
 * ledger, exclusively through PostFromEvent (02-accounting §11.1; a second
 * posting path is a defect).
 *
 * Event `supplier.invoice.received`, one call PER PAYABLE FAMILY (§3.3):
 * a pure-opex or pure-capex invoice posts once; a MIXED invoice posts one
 * balanced entry per family, each payable leg carrying the supplier
 * partner - the Action refuses a single payable line spanning both
 * families by construction, since each entry's balancing credit is one
 * family account. Payload lines carry the §5.4 split to the franc:
 *
 *   Dr expense (HT)                 - per line; a CAPITALISED line lands
 *                                     HT + non-deductible TVA on its
 *                                     class-2 account (§5.5)
 *   Dr TVA déductible               - per tax code's deductible account
 *   Dr TVA non déductible           - per tax code's expense account
 *       Cr 401 / 481x               - balancing, family total TTC
 *
 * Under `on_invoice` recognition a SECOND PostFromEvent call (event
 * `withholding.retained`) moves the withholding from the payable to the
 * 447-family liability (§4.6's "recognised at invoice" scheme) and the
 * attestations are issued in the same transaction (§6.6).
 *
 * Closed period (§4.5 invariant 5): the entry is FORWARD-POSTED to the
 * first open period; the invoice's `value_date` keeps the economic date
 * (02-accounting C4).
 *
 * `qty_invoiced` advances on every referenced PO line under FOR UPDATE
 * (§9), and the PO rolls to partially_invoiced / invoiced.
 */
final class PostSupplierInvoice
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly IssueWithholdingAttestation $issueAttestation,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $invoiceId, Actor $actor): SupplierInvoice
    {
        Gate::authorize(SupplierInvoicePermission::APPROVE);

        return DB::transaction(function () use ($invoiceId, $actor): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== SupplierInvoiceStatus::Approved) {
                throw new DomainException(sprintf(
                    'Invoice %s is %s; only an approved invoice posts.',
                    $invoice->internal_no,
                    $invoice->status->value,
                ));
            }

            if ($invoice->is_migration) {
                throw new DomainException(
                    "Invoice {$invoice->internal_no} is a migrated document; its ledger entry already exists and must not re-trigger posting rules (02-accounting H)."
                );
            }

            /** @var list<SupplierInvoiceLine> $lines */
            $lines = $invoice->lines()->get()->all();

            $postingDate = $this->postingDateFor($invoice);

            [$families, $withholdingByFamily] = $this->familiesOf($invoice, $lines);

            $entryIds = [];

            foreach ($families as $family) {
                $entry = $this->post->handle(
                    PostingEvent::SupplierInvoiceReceived->value,
                    [
                        'document' => [
                            'total' => $family['total_ttc'],
                            'reference' => $invoice->internal_no,
                            'partner' => ['type' => 'supplier', 'id' => $invoice->supplier_id],
                            'payable_account_id' => $family['payable_account_id'],
                            'lines' => $family['legs'],
                        ],
                    ],
                    $postingDate,
                    $actor,
                    $invoice->internal_no,
                );

                $entryIds[] = (int) $entry->getKey();
            }

            $withholdingEntryId = $this->recogniseWithholding($invoice, $withholdingByFamily, $postingDate, $actor);

            $this->advancePurchaseOrder($lines);

            $postedPeriod = $this->periodContaining($postingDate);

            $invoice->forceFill([
                'status' => SupplierInvoiceStatus::Posted,
                'posted_at' => now(),
                'journal_entry_id' => $entryIds[0],
                'secondary_journal_entry_id' => $entryIds[1] ?? null,
                'withholding_journal_entry_id' => $withholdingEntryId,
                'accounting_period_id' => $postedPeriod['id'],
                'fiscal_year_id' => $postedPeriod['fiscal_year_id'],
            ])->save();

            if ($this->recognitionBasis() === WithholdingRecognition::OnInvoice) {
                $this->issueAttestations($invoice, $lines, $actor);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierInvoice::class,
                auditableId: (int) $invoice->getKey(),
                after: [
                    'status' => 'posted',
                    'journal_entry_id' => $entryIds[0],
                    'secondary_journal_entry_id' => $entryIds[1] ?? null,
                    'withholding_journal_entry_id' => $withholdingEntryId,
                    'posting_date' => $postingDate,
                ],
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }

    /**
     * §3.3: one payload per payable family. The capex family uses the
     * invoice's (481-family) payable; the opex share of a mixed invoice
     * falls back to the supplier's operating payable, which must be
     * 40-family.
     *
     * @param  list<SupplierInvoiceLine>  $lines
     * @return array{0: list<array{payable_account_id: int, total_ttc: int, legs: list<array{amount: int, expense_account_id: int, label: string}>}>, 1: array<int, int>} families and payable_account_id => withholding total
     */
    private function familiesOf(SupplierInvoice $invoice, array $lines): array
    {
        $capex = array_values(array_filter($lines, static fn (SupplierInvoiceLine $l): bool => $l->is_capitalised));
        $opex = array_values(array_filter($lines, static fn (SupplierInvoiceLine $l): bool => ! $l->is_capitalised));

        $families = [];
        $withholding = [];

        if ($opex !== []) {
            $payableId = $capex === []
                ? $invoice->payable_account_id
                : $this->operatingPayableFor($invoice);

            $families[] = $this->familyPayload($opex, $payableId);
            $wh = $this->withholdingSum($opex);

            if ($wh > 0) {
                $withholding[$payableId] = $wh;
            }
        }

        if ($capex !== []) {
            $families[] = $this->familyPayload($capex, $invoice->payable_account_id);
            $wh = $this->withholdingSum($capex);

            if ($wh > 0) {
                // The capex family's payable (481) is never the opex key
                // (40-family), so a plain assignment cannot clobber.
                $withholding[$invoice->payable_account_id] = $wh;
            }
        }

        // Keep the header-family entry FIRST so journal_entry_id points at
        // the entry whose payable matches payable_account_id.
        if (count($families) === 2 && $families[1]['payable_account_id'] === $invoice->payable_account_id) {
            $families = [$families[1], $families[0]];
        }

        return [$families, $withholding];
    }

    /**
     * @param  list<SupplierInvoiceLine>  $lines
     * @return array{payable_account_id: int, total_ttc: int, legs: list<array{amount: int, expense_account_id: int, label: string}>}
     */
    private function familyPayload(array $lines, int $payableAccountId): array
    {
        $legs = [];
        $deductibleByAccount = [];
        $nonDeductibleByAccount = [];
        $total = Money::zero();

        foreach ($lines as $line) {
            // §5.5: a capitalised line's non-recoverable TVA enters the
            // ASSET COST, never expense.
            $drAmount = $line->is_capitalised
                ? $line->amount_ht + $line->non_deductible_tax_amount
                : $line->amount_ht;

            $legs[] = [
                'amount' => $drAmount,
                'expense_account_id' => $line->expense_account_id,
                'label' => $line->description,
            ];

            $taxAccounts = $this->taxAccountsFor($line);

            if ($line->deductible_tax_amount > 0) {
                $deductibleByAccount[$taxAccounts['deductible']] =
                    ($deductibleByAccount[$taxAccounts['deductible']] ?? 0) + $line->deductible_tax_amount;
            }

            if (! $line->is_capitalised && $line->non_deductible_tax_amount > 0) {
                $nonDeductibleByAccount[$taxAccounts['non_deductible']] =
                    ($nonDeductibleByAccount[$taxAccounts['non_deductible']] ?? 0) + $line->non_deductible_tax_amount;
            }

            $total = $total->plus(Money::of($line->amount_ht + $line->tax_amount));
        }

        foreach ($deductibleByAccount as $accountId => $amount) {
            $legs[] = ['amount' => $amount, 'expense_account_id' => $accountId, 'label' => 'TVA déductible'];
        }

        foreach ($nonDeductibleByAccount as $accountId => $amount) {
            $legs[] = ['amount' => $amount, 'expense_account_id' => $accountId, 'label' => 'TVA non déductible'];
        }

        return [
            'payable_account_id' => $payableAccountId,
            'total_ttc' => $total->amount(),
            'legs' => $legs,
        ];
    }

    /**
     * The TVA accounts of the line's snapshotted tax code - refusing to
     * post while the accountant has not wired them (00-core §16: empty and
     * blocking, never a silent guess).
     *
     * @return array{deductible: int, non_deductible: int}
     */
    private function taxAccountsFor(SupplierInvoiceLine $line): array
    {
        $row = DB::table('tax_codes')
            ->where('id', $line->tax_code_id)
            ->first(['code', 'deductible_account_id', 'non_deductible_expense_account_id']);

        if ($row === null) {
            throw new DomainException("Tax code {$line->tax_code_id} vanished; the snapshot is broken.");
        }

        if ($line->deductible_tax_amount > 0 && $row->deductible_account_id === null) {
            throw new DomainException(
                "Tax code {$row->code} has no TVA déductible account configured; wire it before posting (03-tax-procurement 5.3)."
            );
        }

        if (! $line->is_capitalised && $line->non_deductible_tax_amount > 0 && $row->non_deductible_expense_account_id === null) {
            throw new DomainException(
                "Tax code {$row->code} has no non-deductible TVA expense account configured; wire it before posting (03-tax-procurement 5.3)."
            );
        }

        return [
            'deductible' => (int) $row->deductible_account_id,
            'non_deductible' => (int) $row->non_deductible_expense_account_id,
        ];
    }

    private function operatingPayableFor(SupplierInvoice $invoice): int
    {
        $row = DB::table('suppliers')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'suppliers.payable_account_id')
            ->where('suppliers.id', $invoice->supplier_id)
            ->first(['chart_of_accounts.id as account_id', 'chart_of_accounts.code']);

        if ($row === null || ! str_starts_with((string) $row->code, '40')) {
            throw new DomainException(
                'This mixed invoice needs an operating (40-family) payable for its opex lines; the supplier\'s '
                .'default payable is not one - posting refuses a single payable spanning both families (03-tax-procurement 3.3).'
            );
        }

        return (int) $row->account_id;
    }

    /**
     * @param  list<SupplierInvoiceLine>  $lines
     */
    private function withholdingSum(array $lines): int
    {
        return array_sum(array_map(
            static fn (SupplierInvoiceLine $l): int => $l->withholding_amount,
            $lines,
        ));
    }

    /**
     * §4.6 `on_invoice` recognition: Dr payable / Cr 447-family, its own
     * entry per family, via the `withholding.retained` event.
     *
     * @param  array<int, int>  $withholdingByFamily
     */
    private function recogniseWithholding(
        SupplierInvoice $invoice,
        array $withholdingByFamily,
        string $postingDate,
        Actor $actor,
    ): ?int {
        if ($invoice->withholding_total === 0 || $withholdingByFamily === []) {
            return null;
        }

        $liabilityLegs = [];

        foreach ($invoice->lines()->get() as $line) {
            if ($line->withholding_amount <= 0 || $line->withholding_rule_id === null) {
                continue;
            }

            $rule = DB::table('withholding_rules')
                ->where('id', $line->withholding_rule_id)
                ->first(['name', 'liability_account_id']);

            if ($rule === null || $rule->liability_account_id === null) {
                throw new DomainException(
                    'A withholding rule lost its liability account; the 447 credit cannot be posted (03-tax-procurement 6.2).'
                );
            }

            $key = (int) $rule->liability_account_id;
            $liabilityLegs[$key] = ($liabilityLegs[$key] ?? 0) + $line->withholding_amount;
        }

        $firstEntryId = null;

        foreach ($withholdingByFamily as $payableAccountId => $amount) {
            // The liability legs are shared across families
            // proportionally-by-order; in practice withholding falls on
            // service (opex) lines, so a single family carries it all.
            $legs = [];
            $remaining = $amount;

            foreach ($liabilityLegs as $accountId => $legAmount) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($legAmount, $remaining);
                $legs[] = ['amount' => $take, 'expense_account_id' => $accountId, 'label' => 'Retenue à la source'];
                $liabilityLegs[$accountId] -= $take;
                $remaining -= $take;
            }

            $entry = $this->post->handle(
                PostingEvent::WithholdingRetained->value,
                [
                    'document' => [
                        'total' => $amount,
                        'reference' => $invoice->internal_no,
                        'partner' => ['type' => 'supplier', 'id' => $invoice->supplier_id],
                        'payable_account_id' => $payableAccountId,
                        'lines' => $legs,
                    ],
                ],
                $postingDate,
                $actor,
                $invoice->internal_no,
            );

            $firstEntryId ??= (int) $entry->getKey();
        }

        return $firstEntryId;
    }

    /**
     * §6.6: one attestation per rule, issued in the SAME transaction as
     * the recognition, snapshotting base / rate / amount.
     *
     * @param  list<SupplierInvoiceLine>  $lines
     */
    private function issueAttestations(SupplierInvoice $invoice, array $lines, Actor $actor): void
    {
        $byRule = [];

        foreach ($lines as $line) {
            if ($line->withholding_rule_id === null || $line->withholding_amount <= 0) {
                continue;
            }

            $ruleId = $line->withholding_rule_id;
            $byRule[$ruleId] ??= ['base' => 0, 'amount' => 0, 'rate_bp' => $line->withholding_rate_bp_applied];
            $byRule[$ruleId]['base'] += $line->withholding_base;
            $byRule[$ruleId]['amount'] += $line->withholding_amount;
        }

        $invoiceDate = Carbon::parse($invoice->invoice_date);

        foreach ($byRule as $ruleId => $sums) {
            $this->issueAttestation->handle([
                'supplier_id' => $invoice->supplier_id,
                'supplier_invoice_id' => (int) $invoice->getKey(),
                'withholding_rule_id' => $ruleId,
                'period_month' => (int) $invoiceDate->format('n'),
                'period_year' => (int) $invoiceDate->format('Y'),
                'base_amount' => $sums['base'],
                'rate_bp_applied' => $sums['rate_bp'],
                'withheld_amount' => $sums['amount'],
            ], $actor);
        }
    }

    /**
     * §9: qty_invoiced advances under FOR UPDATE; the PO rolls forward.
     *
     * @param  list<SupplierInvoiceLine>  $lines
     */
    private function advancePurchaseOrder(array $lines): void
    {
        $poIds = [];

        foreach ($lines as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            /** @var PurchaseOrderLine $poLine */
            $poLine = PurchaseOrderLine::query()
                ->whereKey($line->purchase_order_line_id)
                ->lockForUpdate()
                ->firstOrFail();

            $newMillis = LineAmount::toMillis($poLine->qty_invoiced) + LineAmount::toMillis($line->quantity);
            $poLine->qty_invoiced = sprintf('%d.%03d', intdiv($newMillis, 1000), $newMillis % 1000);
            $poLine->save();

            $poIds[$poLine->purchase_order_id] = true;
        }

        foreach (array_keys($poIds) as $poId) {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->whereKey($poId)->lockForUpdate()->firstOrFail();

            $outstanding = $po->lines()->whereColumn('qty_invoiced', '<', 'quantity')->exists();
            $po->status = $outstanding ? PurchaseOrderStatus::PartiallyInvoiced : PurchaseOrderStatus::Invoiced;
            $po->save();
        }
    }

    private function recognitionBasis(): WithholdingRecognition
    {
        // Cross-module READ via DB::table - TaxSettings is Tax's model
        // (00-core §6.2).
        $recognition = DB::table('tax_settings')->where('id', 1)->value('withholding_recognition');

        if ($recognition === null) {
            throw new DomainException(
                'TaxSettings.withholding_recognition is not configured (03-tax-procurement 4.6).'
            );
        }

        return WithholdingRecognition::from((string) $recognition);
    }

    /**
     * 02-accounting C4: post into the invoice's own period while it is
     * open; forward-post to the first open period otherwise, the economic
     * date surviving on `value_date`.
     */
    private function postingDateFor(SupplierInvoice $invoice): string
    {
        $own = DB::table('accounting_periods')
            ->where('id', $invoice->accounting_period_id)
            ->first(['status', 'starts_on', 'ends_on']);

        if ($own !== null && (string) $own->status === 'open') {
            return $invoice->invoice_date;
        }

        $open = DB::table('accounting_periods')
            ->where('status', 'open')
            ->whereDate('ends_on', '>=', $invoice->invoice_date)
            ->orderBy('starts_on')
            ->first(['starts_on']);

        if ($open === null) {
            throw new DomainException(
                "The period of {$invoice->invoice_date} is closed and no later open period exists to forward-post into (02-accounting C4)."
            );
        }

        $start = Carbon::parse((string) $open->starts_on)->toDateString();

        return max($invoice->invoice_date, $start);
    }

    /**
     * @return array{id: int, fiscal_year_id: int}
     */
    private function periodContaining(string $date): array
    {
        $row = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first(['id', 'fiscal_year_id']);

        if ($row === null) {
            throw new DomainException("No accounting period covers {$date}.");
        }

        return ['id' => (int) $row->id, 'fiscal_year_id' => (int) $row->fiscal_year_id];
    }
}
