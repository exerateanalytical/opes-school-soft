<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierRetentionStatus;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierRetention;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §3.3 - the works are accepted: the
 * retenue de garantie reclassifies `Dr 4817 / Cr 401` and becomes payable
 * again. Retention is not a discount and never touches expense; the only
 * ledger movement here is liability-to-liability, through PostFromEvent
 * (event `supplier.invoice.received`, negative-total sentinel selecting
 * the school's release rule - the shape is the invoice rule's mirror: one
 * Dr leg, balancing Cr on the payable with the supplier partner).
 *
 * The invoice re-opens (`partially_paid`) for the released amount, which
 * a subsequent ordinary payment settles.
 */
final class ReleaseRetention
{
    public function __construct(
        private readonly ComputeInvoiceSettlement $settlement,
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $supplierInvoiceId, Actor $actor, ?string $releaseDate = null): SupplierRetention
    {
        Gate::authorize(SupplierPaymentPermission::APPROVE);

        $releaseDate ??= BusinessDate::today();

        return DB::transaction(function () use ($supplierInvoiceId, $actor, $releaseDate): SupplierRetention {
            /** @var SupplierRetention|null $retention */
            $retention = SupplierRetention::query()
                ->where('supplier_invoice_id', $supplierInvoiceId)
                ->lockForUpdate()
                ->first();

            if ($retention === null) {
                throw new DomainException(
                    'No retention has been withheld against this invoice; there is nothing to release (03-tax-procurement 3.3).'
                );
            }

            if ($retention->status !== SupplierRetentionStatus::Withheld) {
                throw new DomainException(sprintf(
                    'The retention on this invoice is %s; only a withheld retention can be released.',
                    $retention->status->value,
                ));
            }

            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($supplierInvoiceId)->lockForUpdate()->firstOrFail();

            $postingDate = $this->postingDateFor($releaseDate);

            $entry = $this->post->handle(
                PostingEvent::SupplierInvoiceReceived->value,
                [
                    'document' => [
                        // Negative sentinel: selects the release rule.
                        'total' => -$retention->amount,
                        'reference' => $invoice->internal_no,
                        'partner' => ['type' => 'supplier', 'id' => $invoice->supplier_id],
                        'payable_account_id' => $invoice->payable_account_id,
                        'lines' => [[
                            'amount' => $retention->amount,
                            'expense_account_id' => $retention->retention_account_id,
                            'label' => 'Libération retenue de garantie '.$invoice->internal_no,
                        ]],
                    ],
                ],
                $postingDate,
                $actor,
                $invoice->internal_no,
            );

            $retention->forceFill([
                'status' => SupplierRetentionStatus::Released,
                'released_at' => now(),
                'released_by' => $actor->id,
                'release_journal_entry_id' => (int) $entry->getKey(),
            ])->save();

            // The released amount is payable again - re-open the invoice.
            $recognition = $this->settlement->recognitionBasis();
            $settleable = $this->settlement->settleableOf($invoice, $recognition);
            $allocated = $this->settlement->allocatedOf((int) $invoice->getKey());

            $invoice->forceFill([
                'status' => $allocated >= $settleable ? SupplierInvoiceStatus::Paid : SupplierInvoiceStatus::PartiallyPaid,
                'version' => $invoice->version + 1,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierRetention::class,
                auditableId: (int) $retention->getKey(),
                after: [
                    'status' => 'released',
                    'amount' => $retention->amount,
                    'release_journal_entry_id' => (int) $entry->getKey(),
                ],
                actor: $actor,
            );

            return $retention->refresh();
        });
    }

    /**
     * 02-accounting C4 forward-posting, same discipline as payments.
     */
    private function postingDateFor(string $date): string
    {
        $own = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first(['status']);

        if ($own !== null && (string) $own->status === 'open') {
            return $date;
        }

        $open = DB::table('accounting_periods')
            ->where('status', 'open')
            ->whereDate('ends_on', '>=', $date)
            ->orderBy('starts_on')
            ->first(['starts_on']);

        if ($open === null) {
            throw new DomainException(
                "The period of {$date} is closed and no later open period exists to forward-post into (02-accounting C4)."
            );
        }

        $start = Carbon::parse((string) $open->starts_on)->toDateString();

        return max($date, $start);
    }
}
