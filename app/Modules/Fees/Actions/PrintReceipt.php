<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Actions\RevokeIssuedDocument;
use App\Modules\Reporting\Domain\AmountInWords;
use App\Modules\Reporting\Domain\DocumentLanguage;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Support\Fiscal\FiscalIdentityGate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §10.1 - prints the Fee Receipt (A5 or the
 * 80 mm thermal variant) for an already-recorded Payment.
 *
 * Snapshot pattern: `Payment` is append-only from the instant RecordPayment
 * returns (04-fees §11.1), so the payment ROW ITSELF is the immutable
 * snapshot - there is no separate JSON snapshot table for receipts, exactly
 * the "receipt pattern" SnapshotSourceMap's docblock names. `snapshotId` is
 * the payment's own id: every reprint of the SAME payment hits the SAME
 * IssuedDocument row and is automatically a DUPLICATA (10-documents §4.5),
 * with no extra bookkeeping in this Action.
 *
 * `receipt_no` printed on the document is `Payment::receipt_no` - the number
 * Fees already allocated at collection time (04-fees §14) - NOT a fresh
 * series allocation; the money-document templates carry series_code = NULL
 * for exactly this reason (see the 310010 seed migration's comment).
 */
final class PrintReceipt
{
    public const VARIANT_A5 = 'a5';

    public const VARIANT_POS = 'pos';

    public function __construct(
        private readonly RenderDocument $render,
        private readonly RevokeIssuedDocument $revoke,
    ) {}

    public function handle(int $paymentId, string $variant = self::VARIANT_A5, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::FeeView->value);

        FiscalIdentityGate::assertCompleteForMoneyDocuments();

        $templateCode = $variant === self::VARIANT_POS ? 'FEE-RECEIPT-POS' : 'FEE-RECEIPT';

        /** @var object{id: int, receipt_no: string, student_id: int, value_date: string, payment_method: string, amount: int, reference: string|null, payer_name: string, clearing_state: string}|null $payment */
        $payment = DB::table('payments')->where('id', $paymentId)->first([
            'id', 'receipt_no', 'student_id', 'value_date', 'payment_method',
            'amount', 'reference', 'payer_name', 'clearing_state',
        ]);

        if ($payment === null) {
            throw new DomainException("Payment {$paymentId} does not exist.");
        }

        $isVoided = DB::table('payment_voids')
            ->where('payment_id', $paymentId)
            ->where('status', 'confirmed')
            ->exists();

        if ($isVoided) {
            // Sets the IssuedDocument row to `revoked`, NOT a flag baked into
            // this Action's own payload: RenderDocument's reprint path
            // re-renders the CLEAN artefact from this SAME payload and
            // hash-compares it against the one recorded at issue (10-
            // documents §4.5) BEFORE it decides which watermark to apply -
            // a payload that silently starts including "voided" text the
            // moment the payment is voided would make that clean re-render
            // diverge from the original and trip a false
            // DocumentReproducibilityViolation. `$issued->isRevoked()`
            // downstream is what selects the ANNULÉ/VOID watermark, exactly
            // like a DUPLICATA overlay - never this payload.
            $this->revokeIfIssued($templateCode, $paymentId, $payment->receipt_no);
        }

        /** @var object{first_name: string, last_name: string, matricule: string}|null $student */
        $student = DB::table('students')->where('id', $payment->student_id)
            ->first(['first_name', 'last_name', 'matricule']);

        if ($student === null) {
            throw new DomainException("Student {$payment->student_id} does not exist.");
        }

        $classGroup = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', $payment->student_id)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        $lines = DB::table('payment_allocations as pa')
            ->join('invoice_lines as l', 'l.id', '=', 'pa.invoice_line_id')
            ->leftJoin('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('pa.payment_id', $paymentId)
            ->whereNull('pa.reversed_at')
            ->orderBy('pa.id')
            ->get(['i.invoice_no', 'l.description', 'pa.amount']);

        $allocatedTotal = 0;

        $lineItems = [];

        foreach ($lines as $line) {
            /** @var object{invoice_no: string|null, description: string, amount: int|string} $line */
            $allocatedTotal += (int) $line->amount;

            $lineItems[] = [
                'invoice_no' => (string) ($line->invoice_no ?? ''),
                'description' => (string) $line->description,
                'amount' => (int) $line->amount,
            ];
        }

        $unallocated = (int) $payment->amount - $allocatedTotal;
        $lang = DocumentLanguage::tryFrom($language ?? '') ?? DocumentLanguage::En;

        if ($unallocated > 0) {
            $lineItems[] = [
                'invoice_no' => '',
                // A document string, in the DOCUMENT language (10-documents
                // §4.6) - lives in lang/documents.php, never opes.php (the
                // operator's UI locale file), even though this is assembled
                // in an Action rather than looked up inside the Blade.
                'description' => __('documents.receipt.credit_balance', [], $lang->value),
                'amount' => $unallocated,
            ];
        }

        // A simple, always-correct footer figure - total received from this
        // student to date, in (date, id) order up to and including this
        // payment. Deliberately NOT the fee-account running balance (that
        // needs the full §5 invoice/adjustment/credit-note formula the
        // Statement renders); the receipt is not the place to approximate it.
        $paidToDate = (int) DB::table('payments as p')
            ->where('p.student_id', $payment->student_id)
            ->where('p.value_date', '<=', $payment->value_date)
            ->where('p.id', '<=', $paymentId)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->sum('p.amount');

        $chrome = $this->render->captureSchoolChrome(includeStateHeader: false);

        $payload = [
            'school' => $chrome,
            'receipt' => [
                'receipt_no' => $payment->receipt_no,
                'date' => $payment->value_date,
                'received_from' => $payment->payer_name,
                'student_name' => $student->first_name.' '.$student->last_name,
                'student_matricule' => $student->matricule,
                'class_group' => is_string($classGroup) ? $classGroup : '',
                'method' => $payment->payment_method,
                'reference' => $payment->reference,
                'amount' => (int) $payment->amount,
                'amount_words' => AmountInWords::render((int) $payment->amount, $lang),
                'lines' => $lineItems,
                'paid_to_date' => $paidToDate,
            ],
        ];

        return $this->render->handle(
            templateCode: $templateCode,
            subjectType: 'Payment',
            subjectId: $paymentId,
            subjectLabel: 'Receipt '.$payment->receipt_no.' for '.$student->first_name.' '.$student->last_name,
            snapshotId: $paymentId,
            language: $language,
            data: $payload,
        );
    }

    /**
     * A voided payment never gets a fresh, unmarked "original" (10-documents
     * §10.1's invariant): if it was already printed, the existing
     * IssuedDocument is revoked here so RenderDocument's own reprint path
     * (which reads `$issued->isRevoked()` under the row lock) applies the
     * ANNULE/VOID overlay on every render from now on. If it was never
     * printed, handle() below refuses outright - see the caller.
     */
    private function revokeIfIssued(string $templateCode, int $paymentId, string $receiptNo): void
    {
        $templateId = DB::table('document_templates')->where('code', $templateCode)->value('id');

        if ($templateId === null) {
            return;
        }

        $issuedId = DB::table('issued_documents')
            ->where('document_template_id', $templateId)
            ->where('subject_type', 'Payment')
            ->where('subject_id', $paymentId)
            ->where('status', 'valid')
            ->value('id');

        if ($issuedId !== null) {
            $this->revoke->handle((int) $issuedId, "Payment {$receiptNo} was voided (04-fees §11.5).");

            return;
        }

        // Never printed while valid, and now voided: refuse a brand-new
        // "original" receipt for money that no longer stands.
        throw new DomainException(
            "Payment {$receiptNo} has been voided; a receipt cannot be issued for a voided payment."
        );
    }
}
