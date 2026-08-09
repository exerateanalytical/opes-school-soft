<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\MatchExceptionReason;
use App\Modules\Procurement\Domain\MatchStatus;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\ProcurementSettings;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierInvoiceLine;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.4 - the three-way match, run before
 * an invoice may be approved.
 *
 * Modes per LINE: three-way (goods with receipts required: PO price x qty
 * ↔ receipt qty ↔ invoice price x qty), two-way (services and goods where
 * receipt_required_for_goods = false: PO ↔ invoice), none (direct invoices
 * below po_required_above - approval then needs the approve_unmatched
 * permission and a stored reason).
 *
 * Tolerances (per-10 000 bp, the procurement-settings scale):
 * price passes within the GREATER of price_tolerance_bp (of the PO price)
 * and price_tolerance_absolute; quantity within quantity_tolerance_bp of
 * the accepted (three-way) or ordered (two-way) quantity.
 *
 * Concurrency (§9): every referenced PO line is taken FOR UPDATE - the
 * same lock ConfirmGoodsReceipt and PostSupplierInvoice take, so a
 * receipt confirmed mid-match cannot produce a phantom pass.
 *
 * Match state is stored PER LINE (match_status, matched_qty,
 * price_variance, quantity_variance, typed reason) so the exception
 * report names the line, not the invoice.
 */
final class MatchSupplierInvoice
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $invoiceId, Actor $actor): SupplierInvoice
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        return DB::transaction(function () use ($invoiceId, $actor): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [
                SupplierInvoiceStatus::Draft,
                SupplierInvoiceStatus::PendingMatch,
                SupplierInvoiceStatus::MatchException,
            ], true)) {
                throw new DomainException(sprintf(
                    'Invoice %s is %s; matching runs before approval, not after.',
                    $invoice->internal_no,
                    $invoice->status->value,
                ));
            }

            $settings = ProcurementSettings::current();

            /** @var list<SupplierInvoiceLine> $lines */
            $lines = $invoice->lines()->get()->all();

            $hasPoLink = $invoice->purchase_order_id !== null
                || array_filter($lines, static fn (SupplierInvoiceLine $l): bool => $l->purchase_order_line_id !== null) !== [];

            if (! $hasPoLink) {
                $this->matchDirect($invoice, $lines, $settings);
            } else {
                $this->matchAgainstPo($invoice, $lines, $settings);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierInvoice::class,
                auditableId: (int) $invoice->getKey(),
                after: [
                    'match_status' => $invoice->match_status->value,
                    'status' => $invoice->status->value,
                ],
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }

    /**
     * Mode NONE (§4.4): permitted only below po_required_above; above it
     * the missing PO is itself the exception.
     *
     * @param  list<SupplierInvoiceLine>  $lines
     */
    private function matchDirect(SupplierInvoice $invoice, array $lines, ProcurementSettings $settings): void
    {
        $threshold = $settings->po_required_above;

        if ($threshold !== null && $invoice->total_ttc > $threshold) {
            foreach ($lines as $line) {
                $line->forceFill([
                    'match_status' => MatchStatus::Exception,
                    'match_exception_reason' => MatchExceptionReason::NoPo->value,
                ])->save();
            }

            $invoice->forceFill([
                'match_status' => MatchStatus::Exception,
                'status' => SupplierInvoiceStatus::MatchException,
            ])->save();

            return;
        }

        $invoice->forceFill([
            'match_status' => MatchStatus::NotRequired,
            'status' => SupplierInvoiceStatus::PendingApproval,
        ])->save();
    }

    /**
     * Two- and three-way comparison per line, PO lines FOR UPDATE.
     *
     * @param  list<SupplierInvoiceLine>  $lines
     */
    private function matchAgainstPo(SupplierInvoice $invoice, array $lines, ProcurementSettings $settings): void
    {
        $anyException = false;

        $poSupplierId = $invoice->purchase_order_id === null
            ? null
            : (int) DB::table('purchase_orders')->where('id', $invoice->purchase_order_id)->value('supplier_id');

        if ($poSupplierId !== null && $poSupplierId !== $invoice->supplier_id) {
            foreach ($lines as $line) {
                $line->forceFill([
                    'match_status' => MatchStatus::Exception,
                    'match_exception_reason' => MatchExceptionReason::SupplierMismatch->value,
                ])->save();
            }

            $invoice->forceFill([
                'match_status' => MatchStatus::Exception,
                'status' => SupplierInvoiceStatus::MatchException,
            ])->save();

            return;
        }

        foreach ($lines as $line) {
            if ($line->purchase_order_line_id === null) {
                // A free line on a PO-backed invoice has nothing to compare
                // against; it rides the invoice-level approval.
                $line->forceFill(['match_status' => MatchStatus::NotRequired])->save();

                continue;
            }

            $anyException = ! $this->matchLine($line, $settings) || $anyException;
        }

        $invoice->forceFill($anyException
            ? ['match_status' => MatchStatus::Exception, 'status' => SupplierInvoiceStatus::MatchException]
            : ['match_status' => MatchStatus::Matched, 'status' => SupplierInvoiceStatus::PendingApproval])->save();
    }

    /**
     * @return bool true when the line matched within tolerance
     */
    private function matchLine(SupplierInvoiceLine $line, ProcurementSettings $settings): bool
    {
        // §9: FOR UPDATE on the PO line - the comparison and any later
        // qty_invoiced advance happen against a stable row.
        /** @var PurchaseOrderLine $poLine */
        $poLine = PurchaseOrderLine::query()
            ->whereKey($line->purchase_order_line_id)
            ->lockForUpdate()
            ->firstOrFail();

        // ── Price leg (both modes) ──────────────────────────────────────
        $delta = $line->unit_price_ht - $poLine->unit_price_ht;
        $allowed = max(
            intdiv($poLine->unit_price_ht * $settings->price_tolerance_bp + 5_000, 10_000),
            $settings->price_tolerance_absolute,
        );

        $priceOk = abs($delta) <= $allowed;

        // ── Quantity leg ────────────────────────────────────────────────
        $isGoods = $poLine->inventory_item_id !== null
            || $poLine->asset_category_id !== null
            || $poLine->is_capitalised;
        $threeWay = $isGoods && $settings->receipt_required_for_goods;

        $invoicedMillis = LineAmount::toMillis($line->quantity);
        $alreadyMillis = LineAmount::toMillis($poLine->qty_invoiced);
        $toleranceBp = $settings->quantity_tolerance_bp;

        $reason = null;
        $overMillis = 0;
        $matchedMillis = $invoicedMillis;

        if ($threeWay) {
            $acceptedMillis = LineAmount::toMillis($poLine->qty_received);

            if ($acceptedMillis === 0) {
                $reason = MatchExceptionReason::NoReceipt;
            } else {
                $ceiling = intdiv($acceptedMillis * (10_000 + $toleranceBp), 10_000);

                if ($alreadyMillis + $invoicedMillis > $ceiling) {
                    $reason = MatchExceptionReason::QuantityVariance;
                    $overMillis = $alreadyMillis + $invoicedMillis - $acceptedMillis;
                    $matchedMillis = max(0, $acceptedMillis - $alreadyMillis);
                }
            }
        } else {
            $orderedMillis = LineAmount::toMillis($poLine->quantity);
            $ceiling = intdiv($orderedMillis * (10_000 + $toleranceBp), 10_000);

            if ($alreadyMillis + $invoicedMillis > $ceiling) {
                $reason = MatchExceptionReason::OverInvoiced;
                $overMillis = $alreadyMillis + $invoicedMillis - $orderedMillis;
                $matchedMillis = max(0, $orderedMillis - $alreadyMillis);
            }
        }

        // Price beats quantity in the stored single reason only when the
        // quantity leg passed - both variances are stored numerically
        // either way, so the exception report loses nothing.
        if ($reason === null && ! $priceOk) {
            $reason = MatchExceptionReason::PriceVariance;
        } elseif ($reason !== null && ! $priceOk) {
            // Keep the quantity reason; the price variance column names the
            // second failure.
        }

        $line->forceFill([
            'match_status' => $reason === null ? MatchStatus::Matched : MatchStatus::Exception,
            'matched_qty' => self::millisToDecimal(min($matchedMillis, $invoicedMillis)),
            'price_variance' => $priceOk ? 0 : $delta,
            'quantity_variance' => self::millisToDecimal($overMillis),
            'match_exception_reason' => $reason?->value,
        ])->save();

        return $reason === null;
    }

    private static function millisToDecimal(int $millis): string
    {
        return sprintf('%d.%03d', intdiv($millis, 1000), $millis % 1000);
    }
}
