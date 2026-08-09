<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierInvoiceLine;
use App\Modules\Tax\Actions\CancelWithholdingAttestation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.5/§9 - cancel a supplier invoice.
 *
 * Before posting: a status flip with a mandatory reason - nothing reached
 * the ledger, nothing to reverse. After posting: every entry the invoice
 * produced (main, secondary family, withholding recognition) is reversed
 * through Accounting's ReverseJournalEntry - the ledger's own single
 * reversal door, dated in the earliest open period, never by deletion -
 * PO `qty_invoiced` is handed back under lock, and any issued attestation
 * is CANCELLED in the same transaction (§6.6 invariant 2: an attestation
 * for a cancelled invoice is a false tax document).
 *
 * A partially or fully PAID invoice cannot be cancelled - void the
 * payments first (§4.7, F4's VoidSupplierPayment).
 */
final class CancelSupplierInvoice
{
    public function __construct(
        private readonly ReverseJournalEntry $reverse,
        private readonly CancelWithholdingAttestation $cancelAttestation,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $invoiceId, string $reason, Actor $actor): SupplierInvoice
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Cancelling an invoice requires a substantive reason.',
            ]);
        }

        return DB::transaction(function () use ($invoiceId, $reason, $actor): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if (in_array($invoice->status, [
                SupplierInvoiceStatus::PartiallyPaid,
                SupplierInvoiceStatus::Paid,
            ], true)) {
                throw new DomainException(
                    "Invoice {$invoice->internal_no} has payments allocated; void the payments before cancelling (03-tax-procurement 4.7)."
                );
            }

            if ($invoice->status === SupplierInvoiceStatus::Cancelled) {
                throw new DomainException("Invoice {$invoice->internal_no} is already cancelled.");
            }

            if ($invoice->status === SupplierInvoiceStatus::Posted) {
                foreach ([
                    $invoice->journal_entry_id,
                    $invoice->secondary_journal_entry_id,
                    $invoice->withholding_journal_entry_id,
                ] as $entryId) {
                    if ($entryId !== null) {
                        $this->reverse->handle($entryId, "Cancellation of {$invoice->internal_no}: {$reason}", $actor);
                    }
                }

                $this->releasePurchaseOrderQuantities($invoice);
                $this->cancelAttestations($invoice, $reason, $actor);
            }

            $invoice->forceFill([
                'status' => SupplierInvoiceStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierInvoice::class,
                auditableId: (int) $invoice->getKey(),
                after: ['status' => 'cancelled', 'reason' => $reason],
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }

    private function releasePurchaseOrderQuantities(SupplierInvoice $invoice): void
    {
        /** @var list<SupplierInvoiceLine> $lines */
        $lines = $invoice->lines()->get()->all();

        foreach ($lines as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            /** @var PurchaseOrderLine $poLine */
            $poLine = PurchaseOrderLine::query()
                ->whereKey($line->purchase_order_line_id)
                ->lockForUpdate()
                ->firstOrFail();

            $newMillis = max(0, LineAmount::toMillis($poLine->qty_invoiced) - LineAmount::toMillis($line->quantity));
            $poLine->qty_invoiced = sprintf('%d.%03d', intdiv($newMillis, 1000), $newMillis % 1000);
            $poLine->save();
        }
    }

    private function cancelAttestations(SupplierInvoice $invoice, string $reason, Actor $actor): void
    {
        $attestationIds = DB::table('withholding_attestations')
            ->where('supplier_invoice_id', $invoice->getKey())
            ->where('status', 'issued')
            ->pluck('id');

        foreach ($attestationIds as $attestationId) {
            $this->cancelAttestation->handle(
                (int) $attestationId,
                "Invoice {$invoice->internal_no} cancelled: {$reason}",
                $actor,
            );
        }
    }
}
