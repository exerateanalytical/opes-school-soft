<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\MatchStatus;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.5 - approve a matched invoice.
 *
 * Segregation of duties: THE USER WHO CREATED THE INVOICE CANNOT APPROVE
 * IT - an identity check, not a permission check, so holding both
 * permissions does not bypass it (§11 test obligation 14).
 *
 * Gates, in order:
 *  - a live match exception blocks outright (override it first, §4.4);
 *  - a mode-none direct invoice (match not required, no PO) needs
 *    `procurement.invoice_approve_unmatched` AND a stored reason;
 *  - `withholding_unresolved` blocks without
 *    `procurement.invoice_waive_withholding` AND a stored reason (§6.4.7 -
 *    NOT withholding must be the deliberate, recorded act).
 */
final class ApproveSupplierInvoice
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $invoiceId,
        Actor $actor,
        ?string $unmatchedReason = null,
        ?string $waiveWithholdingReason = null,
    ): SupplierInvoice {
        Gate::authorize(SupplierInvoicePermission::APPROVE);

        return DB::transaction(function () use ($invoiceId, $actor, $unmatchedReason, $waiveWithholdingReason): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== SupplierInvoiceStatus::PendingApproval) {
                throw new DomainException(sprintf(
                    'Invoice %s is %s; only a pending_approval invoice can be approved%s.',
                    $invoice->internal_no,
                    $invoice->status->value,
                    $invoice->status === SupplierInvoiceStatus::MatchException
                        ? ' - resolve or override the match exception first (03-tax-procurement 4.4)'
                        : '',
                ));
            }

            // §4.5 SoD - identity, not permission.
            if ($invoice->created_by === $actor->id) {
                throw new DomainException(
                    'The user who captured an invoice cannot approve it (03-tax-procurement 4.5, segregation of duties).'
                );
            }

            if ($invoice->match_status === MatchStatus::Exception) {
                throw new DomainException(
                    "Invoice {$invoice->internal_no} carries a match exception; approval is blocked (03-tax-procurement 4.4)."
                );
            }

            // Mode NONE (§4.4): unmatched approval is a distinct, recorded
            // privilege.
            if ($invoice->match_status === MatchStatus::NotRequired) {
                if (! Gate::allows(SupplierInvoicePermission::APPROVE_UNMATCHED)) {
                    throw new DomainException(
                        'Approving an unmatched direct invoice needs procurement.invoice_approve_unmatched (03-tax-procurement 4.4).'
                    );
                }

                if ($unmatchedReason === null || mb_strlen(trim($unmatchedReason)) < 5) {
                    throw ValidationException::withMessages([
                        'unmatched_reason' => 'Approving without a match requires a stored reason (03-tax-procurement 4.4).',
                    ]);
                }

                $invoice->unmatched_reason = $unmatchedReason;
            }

            // §6.4.7 - silence is not an answer.
            if ($invoice->withholding_unresolved) {
                if (! Gate::allows(SupplierInvoicePermission::WAIVE_WITHHOLDING)) {
                    throw new DomainException(
                        'No withholding rule resolved for this invoice; approval needs procurement.invoice_waive_withholding '
                        .'and a stored reason (03-tax-procurement 6.4 step 7).'
                    );
                }

                if ($waiveWithholdingReason === null || mb_strlen(trim($waiveWithholdingReason)) < 5) {
                    throw ValidationException::withMessages([
                        'waive_withholding_reason' => 'Waiving withholding requires a stored reason (03-tax-procurement 6.4 step 7).',
                    ]);
                }

                $invoice->withholding_waived_reason = $waiveWithholdingReason;
                $invoice->withholding_waived_by = $actor->id;
                $invoice->withholding_waived_at = now();
            }

            $invoice->status = SupplierInvoiceStatus::Approved;
            $invoice->approved_by = $actor->id;
            $invoice->approved_at = now();
            $invoice->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierInvoice::class,
                auditableId: (int) $invoice->getKey(),
                after: [
                    'status' => 'approved',
                    'unmatched_reason' => $invoice->unmatched_reason,
                    'withholding_waived' => $invoice->withholding_waived_reason !== null,
                ],
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }
}
