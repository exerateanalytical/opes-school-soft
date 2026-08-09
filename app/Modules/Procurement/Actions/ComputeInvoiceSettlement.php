<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\SupplierRetentionStatus;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierInvoiceLine;
use App\Modules\Tax\Actions\ResolveWithholding;
use App\Modules\Tax\Domain\WithholdingRecognition;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The payables settlement arithmetic shared by RecordSupplierPayment and
 * PaySupplierPayment - ONE definition of "how much of this invoice is
 * still payable in cash" and "how much withholding does a payment of X
 * against it recognise", so record-time preview and pay-time truth cannot
 * drift (docs/specs/03-tax-procurement.md §4.7, §6.3, §6.4).
 *
 * Settleable (the cash-side payable a payment may allocate against):
 *
 *   total_ttc
 *     − withholding_total       when recognition = on_invoice (the §4.6
 *                               recognition entry already moved it to 447)
 *     − open retention          (§3.3: withheld - or still to be withheld -
 *                               to 4817 pending acceptance; a RELEASED
 *                               retention is payable again)
 *
 * Under on_payment the withheld portion stays INSIDE the allocation
 * amount - the payable is debited gross and split between treasury and
 * 447 at pay time, exactly the §6.4 worked example.
 */
final class ComputeInvoiceSettlement
{
    public function __construct(private readonly ResolveWithholding $resolveWithholding) {}

    /**
     * §4.6: the recognition point is configuration, confirmed with the
     * accountant - blocking while unset (00-core §16).
     */
    public function recognitionBasis(): WithholdingRecognition
    {
        $settings = DB::table('tax_settings')->where('id', 1)->first(['withholding_recognition', 'confirmed_at']);

        if ($settings === null || $settings->withholding_recognition === null || $settings->confirmed_at === null) {
            throw new DomainException(
                'TaxSettings.withholding_recognition is not confirmed - configure the withholding '
                .'recognition basis with your accountant before paying suppliers (03-tax-procurement 4.6).'
            );
        }

        return WithholdingRecognition::from((string) $settings->withholding_recognition);
    }

    /**
     * §3.3: the retention portion NOT currently payable. Zero once
     * released; the full snapshot otherwise (whether not-yet-withheld,
     * withheld to 4817, or cancelled-by-void and awaiting re-withholding).
     */
    public function retentionOpen(SupplierInvoice $invoice): int
    {
        if ($invoice->retention_amount === 0) {
            return 0;
        }

        $status = DB::table('supplier_retentions')
            ->where('supplier_invoice_id', $invoice->getKey())
            ->value('status');

        return $status === SupplierRetentionStatus::Released->value ? 0 : $invoice->retention_amount;
    }

    public function settleableOf(SupplierInvoice $invoice, WithholdingRecognition $recognition): int
    {
        $settleable = $invoice->total_ttc - $this->retentionOpen($invoice);

        if ($recognition === WithholdingRecognition::OnInvoice) {
            $settleable -= $invoice->withholding_total;
        }

        return $settleable;
    }

    /**
     * Σ live (non-reversed) allocations of non-voided payments - a DRAFT
     * payment reserves its allocation so two clerks cannot promise the
     * same franc twice (§4.7 concurrency; the caller holds the invoice row
     * FOR UPDATE).
     */
    public function allocatedOf(int $invoiceId, ?int $excludingPaymentId = null): int
    {
        $query = DB::table('supplier_payment_allocations as a')
            ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
            ->where('a.supplier_invoice_id', $invoiceId)
            ->whereNull('a.reversed_at')
            ->where('p.status', '<>', 'voided');

        if ($excludingPaymentId !== null) {
            $query->where('p.id', '<>', $excludingPaymentId);
        }

        return (int) $query->sum('a.amount');
    }

    /**
     * Σ withholding already recognised against the invoice by live
     * allocations (on_payment recognition).
     */
    public function withheldOf(int $invoiceId, ?int $excludingPaymentId = null): int
    {
        $query = DB::table('supplier_payment_allocations as a')
            ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
            ->where('a.supplier_invoice_id', $invoiceId)
            ->whereNull('a.reversed_at')
            ->where('p.status', '<>', 'voided');

        if ($excludingPaymentId !== null) {
            $query->where('p.id', '<>', $excludingPaymentId);
        }

        return (int) $query->sum('a.withholding_amount');
    }

    /**
     * §6.3/§6.4 - the invoice's FULL withholding resolved at the payment
     * date (on_payment: rule selection is driven by the PAYMENT date),
     * grouped by rule. Lines re-derive their nature exactly as capture did
     * (inventory/asset lines are goods, the rest services).
     *
     * @return array{total: int, by_rule: array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}>}
     */
    public function withholdingAt(SupplierInvoice $invoice, string $date): array
    {
        /** @var list<SupplierInvoiceLine> $lines */
        $lines = $invoice->lines()->get()->all();

        if ($lines === []) {
            return ['total' => 0, 'by_rule' => []];
        }

        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($invoice->supplier_id);

        $inputs = array_map(
            static function (SupplierInvoiceLine $line): array {
                $isGoods = $line->inventory_item_id !== null
                    || $line->asset_id !== null
                    || $line->asset_category_id !== null;

                return [
                    'amount_ht' => $line->amount_ht,
                    'amount_ttc' => $line->amount_ht + $line->tax_amount,
                    'nature' => $isGoods ? 'goods' : 'services',
                ];
            },
            $lines,
        );

        $resolutions = $this->resolveWithholding->handle([
            'is_withholding_exempt' => $supplier->is_withholding_exempt,
            'withholding_exemption_ref' => $supplier->withholding_exemption_ref,
            'withholding_exemption_expires_on' => $supplier->withholding_exemption_expires_on,
            'regime_fiscal' => $supplier->regime_fiscal?->value,
            'has_contributor_card' => $supplier->has_contributor_card,
            'niu_status' => $supplier->niu_status->value,
            'supplier_type' => $supplier->supplier_type->value,
            'country' => $supplier->country,
        ], $inputs, $date);

        /** @var array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}> $byRule */
        $byRule = [];
        $total = 0;

        foreach ($resolutions as $resolution) {
            if (! $resolution->isWithheld() || $resolution->ruleId === null) {
                continue;
            }

            $ruleId = $resolution->ruleId;

            if (! isset($byRule[$ruleId])) {
                $liability = DB::table('withholding_rules')->where('id', $ruleId)->value('liability_account_id');

                if ($liability === null) {
                    throw new DomainException(
                        'A withholding rule lost its liability account; the 447 credit cannot be posted (03-tax-procurement 6.2).'
                    );
                }

                $byRule[$ruleId] = [
                    'rule_id' => $ruleId,
                    'liability_account_id' => (int) $liability,
                    'rate_bp' => $resolution->rateBpApplied,
                    'base' => 0,
                    'amount' => 0,
                ];
            }

            $byRule[$ruleId]['base'] += $resolution->baseAmount;
            $byRule[$ruleId]['amount'] += $resolution->withheldAmount;
            $total += $resolution->withheldAmount;
        }

        return ['total' => $total, 'by_rule' => $byRule];
    }

    /**
     * The slice of the invoice's remaining withholding this allocation
     * recognises: proportional to the allocated amount, the FINAL
     * settlement taking the exact remainder so conservation holds to the
     * franc across partial payments.
     */
    public function withholdingShare(
        int $allocation,
        int $outstandingBefore,
        int $settleable,
        int $fullWithholding,
        int $alreadyWithheld,
    ): int {
        $remaining = $fullWithholding - $alreadyWithheld;

        if ($remaining <= 0 || $settleable <= 0) {
            return 0;
        }

        if ($allocation >= $outstandingBefore) {
            return $remaining;
        }

        // round_half_up(full × allocation / settleable), integer arithmetic.
        $proportional = intdiv(2 * $fullWithholding * $allocation + $settleable, 2 * $settleable);

        return min($remaining, $proportional);
    }
}
