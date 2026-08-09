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
 * docs/specs/03-tax-procurement.md §4.4 - overriding a match exception is
 * a RECORDED act by a holder of `procurement.invoice_override_match`,
 * with the reason stored on the invoice (match_override_reason/_by/_at).
 * The per-line variances stay exactly as the match wrote them - the
 * override changes the gate, never the evidence.
 */
final class OverrideMatchException
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $invoiceId, string $reason, Actor $actor): SupplierInvoice
    {
        Gate::authorize(SupplierInvoicePermission::OVERRIDE_MATCH);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Overriding a match exception requires a substantive reason (03-tax-procurement 4.4).',
            ]);
        }

        return DB::transaction(function () use ($invoiceId, $reason, $actor): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== SupplierInvoiceStatus::MatchException
                || $invoice->match_status !== MatchStatus::Exception) {
                throw new DomainException(sprintf(
                    'Invoice %s carries no match exception to override (status %s / match %s).',
                    $invoice->internal_no,
                    $invoice->status->value,
                    $invoice->match_status->value,
                ));
            }

            $invoice->forceFill([
                'match_status' => MatchStatus::Overridden,
                'status' => SupplierInvoiceStatus::PendingApproval,
                'match_override_reason' => $reason,
                'match_override_by' => $actor->id,
                'match_override_at' => now(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierInvoice::class,
                auditableId: (int) $invoice->getKey(),
                after: ['match_status' => 'overridden', 'reason' => $reason],
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }
}
