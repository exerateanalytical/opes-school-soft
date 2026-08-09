<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\AmountInWords;
use App\Modules\Reporting\Domain\DocumentLanguage;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Support\Clock\BusinessDate;
use App\Support\Fiscal\FiscalIdentityGate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §10.2 - prints the Fee Invoice for an ISSUED
 * invoice.
 *
 * Same receipt pattern as PrintReceipt: `Invoice` is the immutable
 * snapshot (its lines never change once issued - 04-fees §3), `invoice_no`
 * printed is the number IssueInvoice already allocated (04-fees §4.1), and
 * `snapshotId` is the invoice's own id so a reprint of the same invoice is
 * automatically a DUPLICATA.
 *
 * Agent-collected lines (§10.2's "Amounts collected on behalf of third
 * parties" block, 04-fees §C5) are split into their own subtotalled group -
 * the school is not the principal for those francs.
 */
final class PrintInvoice
{
    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $invoiceId, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::FeeView->value);

        FiscalIdentityGate::assertCompleteForMoneyDocuments();

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->with('lines')->find($invoiceId);

        if ($invoice === null) {
            throw new DomainException("Invoice {$invoiceId} does not exist.");
        }

        if ($invoice->invoice_no === null) {
            throw new DomainException("Invoice {$invoiceId} has not been issued yet; a draft invoice has no number to print.");
        }

        /** @var object{first_name: string, last_name: string, matricule: string}|null $student */
        $student = DB::table('students')->where('id', $invoice->student_id)
            ->first(['first_name', 'last_name', 'matricule']);

        if ($student === null) {
            throw new DomainException("Student {$invoice->student_id} does not exist.");
        }

        $classGroup = DB::table('enrollment_segments as seg')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('seg.enrollment_id', $invoice->enrollment_id)
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        $ownLines = [];
        $thirdPartyLines = [];
        $ownTotal = 0;
        $thirdPartyTotal = 0;

        foreach ($invoice->lines as $line) {
            $gross = (int) $line->amount + (int) $line->tax_amount;
            $row = [
                'description' => $line->description,
                'amount' => (int) $line->amount,
                'tax' => (int) $line->tax_amount,
                'total' => $gross,
            ];

            if ($line->collection_basis === InvoiceLine::BASIS_AGENT) {
                $thirdPartyLines[] = $row;
                $thirdPartyTotal += $gross;
            } else {
                $ownLines[] = $row;
                $ownTotal += $gross;
            }
        }

        $grandTotal = $ownTotal + $thirdPartyTotal;
        $lang = DocumentLanguage::tryFrom($language ?? '') ?? DocumentLanguage::En;
        $balanceDue = $invoice->outstandingAsOf(Carbon::parse(BusinessDate::today()));

        $chrome = $this->render->captureSchoolChrome(includeStateHeader: false);

        $payload = [
            'school' => $chrome,
            'invoice' => [
                'invoice_no' => $invoice->invoice_no,
                'date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'student_name' => $student->first_name.' '.$student->last_name,
                'student_matricule' => $student->matricule,
                'class_group' => is_string($classGroup) ? $classGroup : '',
                'own_lines' => $ownLines,
                'own_total' => $ownTotal,
                'third_party_lines' => $thirdPartyLines,
                'third_party_total' => $thirdPartyTotal,
                'grand_total' => $grandTotal,
                'amount_words' => AmountInWords::render($grandTotal, $lang),
                'balance_due' => $balanceDue,
            ],
        ];

        return $this->render->handle(
            templateCode: 'FEE-INVOICE',
            subjectType: 'Invoice',
            subjectId: $invoiceId,
            subjectLabel: 'Invoice '.$invoice->invoice_no.' for '.$student->first_name.' '.$student->last_name,
            snapshotId: $invoiceId,
            language: $language,
            data: $payload,
        );
    }
}
