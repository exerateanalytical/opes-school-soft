<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Modules\Tax\Models\WithholdingAttestation;
use App\Support\Fiscal\FiscalIdentityGate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §6.6 / 10-documents.md §15 (WHT-CERT) -
 * prints the attestation de retenue à la source for an already-issued
 * WithholdingAttestation.
 *
 * Same receipt pattern as Fees' PrintReceipt/PrintInvoice: the attestation
 * row is immutable once issued (Invariant 1, WithholdingAttestation::booted())
 * with its own `attestation_no` already allocated by IssueWithholdingAttestation
 * (its own `ATT` sequence, §6.6) - NOT a fresh document-series number.
 * `snapshotId` is the attestation's own id.
 *
 * Cross-module reads (`suppliers`, `supplier_invoices`, `supplier_payments`)
 * go through DB::table, never Procurement's Models (00-core §6.2) - this
 * Action lives in Tax because WithholdingAttestation is Tax's own model.
 */
final class PrintWithholdingAttestation
{
    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $attestationId, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::TaxView->value);

        FiscalIdentityGate::assertCompleteForMoneyDocuments();

        /** @var WithholdingAttestation|null $attestation */
        $attestation = WithholdingAttestation::query()->with('rule')->find($attestationId);

        if ($attestation === null) {
            throw new DomainException("Withholding attestation {$attestationId} does not exist.");
        }

        /** @var object{name: string, niu: string|null, address_line1: string|null, city: string|null}|null $supplier */
        $supplier = DB::table('suppliers')->where('id', $attestation->supplier_id)
            ->first(['name', 'niu', 'address_line1', 'city']);

        if ($supplier === null) {
            throw new DomainException("Supplier {$attestation->supplier_id} does not exist.");
        }

        $relatedDocument = '';

        if ($attestation->supplier_invoice_id !== null) {
            $invoiceNo = DB::table('supplier_invoices')->where('id', $attestation->supplier_invoice_id)->value('invoice_no');
            $relatedDocument = is_string($invoiceNo) ? $invoiceNo : '';
        } elseif ($attestation->supplier_payment_id !== null) {
            $paymentNo = DB::table('supplier_payments')->where('id', $attestation->supplier_payment_id)->value('payment_no');
            $relatedDocument = is_string($paymentNo) ? $paymentNo : '';
        }

        $chrome = $this->render->captureSchoolChrome(includeStateHeader: false);

        $payload = [
            'school' => $chrome,
            'attestation' => [
                'attestation_no' => $attestation->attestation_no,
                'supplier_name' => $supplier->name,
                'supplier_niu' => $supplier->niu,
                'supplier_address' => trim(implode(', ', array_filter([$supplier->address_line1, $supplier->city]))),
                'period' => sprintf('%04d-%02d', $attestation->period_year, $attestation->period_month),
                'legal_basis' => $attestation->rule?->legal_ref ?? $attestation->rule?->name ?? '',
                'base_amount' => $attestation->base_amount,
                'rate_bp' => $attestation->rate_bp_applied,
                'withheld_amount' => $attestation->withheld_amount,
                'related_document' => $relatedDocument,
                'issued_at' => $attestation->issued_at?->toDateString(),
            ],
        ];

        return $this->render->handle(
            templateCode: 'WHT-CERT',
            subjectType: 'WithholdingAttestation',
            subjectId: $attestationId,
            subjectLabel: 'Withholding attestation '.$attestation->attestation_no.' for '.$supplier->name,
            snapshotId: $attestationId,
            language: $language,
            data: $payload,
        );
    }
}
