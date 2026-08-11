<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Http\JsonResponse;

/**
 * Slice D - money (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4, rows
 * 12-15 of the endpoint table; 07-students.md 7.5 rows 13-18).
 *
 * Two grants of different width live here and must not be collapsed:
 *
 *   the WIDE grant  - `receives_invoices OR is_fee_payer` (rows 13, 14, 15,
 *                     17) - the statement, the invoices and every receipt
 *                     against the child's enrollment, including money another
 *                     guardian paid;
 *   the row-16 FLOOR - every valid link, however bare - only the payments
 *                     THIS guardian made.
 *
 * The floor is the reason `GET /v1/me/payments` exists at all: a parent who
 * receives no invoices is still entitled to a record of their own money. It is
 * also the reason that endpoint is not scoped to one child - row 16 is granted
 * on "any valid link" without naming one.
 *
 * KNOWN GAP, inherited and deliberately not papered over: `payments` carries
 * `payer_name`/`payer_phone` but no `payer_guardian_id`, though the matrix's
 * own row-16 comment assumes one. ChildFeeStatement::receipts() documents this
 * at length. The consequence here is that "mine" is a best-effort phone match
 * used for DISPLAY, while AUTHORIZATION takes the safe side of the
 * approximation: hold the wide grant and you see the child's receipts; hold
 * only the floor and you see just the rows that match your own number, never
 * the unfiltered list.
 */
final class FeesController
{
    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly ChildFeeStatement $statement,
    ) {
    }

    /**
     * `GET /v1/me/children/{student}/fees` - rows 13 and 14.
     *
     * One round trip for the whole fees tab: totals, the billed structure, the
     * invoice list, the installment schedule and the running statement.
     */
    public function show(int $student): JsonResponse
    {
        $this->requireChild($student);

        $canInvoices = $this->policy->allows(GuardianCapability::R13ViewInvoices, $student);
        $canStatement = $this->policy->allows(GuardianCapability::R14ViewFeeStatement, $student);

        if (! $canInvoices && ! $canStatement) {
            // A valid link that simply is not the fees link. 403, not 404 -
            // the child's existence is already established by row 1.
            abort(403);
        }

        $enrollmentId = $this->statement->latestEnrollmentId($student);

        if ($enrollmentId === null) {
            return response()->json([
                'data' => [
                    'currency' => 'XAF',
                    'has_enrollment' => false,
                    'totals' => ['billed' => 0, 'paid' => 0, 'outstanding' => 0, 'next_due_on' => null],
                    'structure' => [],
                    'invoices' => [],
                    'installments' => [],
                    'statement' => [],
                ],
            ]);
        }

        $asOf = $this->asOf();

        $data = [
            'currency' => $this->statement->currency($enrollmentId),
            'has_enrollment' => true,
            'totals' => $this->statement->totals($enrollmentId, $asOf),
            'structure' => $this->statement->structure($enrollmentId)
                ->map(static fn (object $row): array => [
                    'fee_item' => (string) $row->description,
                    'fee_item_fr' => $row->description_fr === null ? null : (string) $row->description_fr,
                    'category_code' => $row->fee_category_code === null ? null : (string) $row->fee_category_code,
                    'amount' => (int) $row->amount,
                ])->values()->all(),
            'installments' => $this->statement->installments($enrollmentId, $asOf)->values()->all(),
        ];

        // Row 13 is the invoice list; row 14 is the ledger behind it. A link
        // holding one and not the other gets one and not the other.
        $data['invoices'] = $canInvoices
            ? $this->statement->invoices($enrollmentId)
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'number' => $row->invoice_no === null ? null : (string) $row->invoice_no,
                    'issued_on' => (string) $row->issue_date,
                    'due_on' => (string) $row->due_date,
                    'total' => (int) $row->total,
                ])->values()->all()
            : [];

        $data['statement'] = $canStatement
            ? $this->statement->statement($enrollmentId, $asOf)
                ->map(static fn (object $row): array => [
                    'date' => $row->date,
                    'reference' => $row->reference,
                    'description' => $row->description,
                    'debit' => $row->debit,
                    'credit' => $row->credit,
                    'balance' => $row->balance,
                ])->values()->all()
            : [];

        return response()->json(['data' => $data]);
    }

    /**
     * `GET /v1/me/children/{student}/invoices/{invoice}` - row 13.
     *
     * The invoice id is resolved THROUGH the child's enrollment, never on its
     * own: an id belonging to another family must answer 404, and it does
     * because the lookup simply does not find it.
     */
    public function invoice(int $student, int $invoice): JsonResponse
    {
        $this->requireChild($student);

        if (! $this->policy->allows(GuardianCapability::R13ViewInvoices, $student)) {
            abort(403);
        }

        $enrollmentId = $this->statement->latestEnrollmentId($student);
        $row = $enrollmentId === null ? null : $this->statement->invoice($enrollmentId, $invoice);

        if ($row === null) {
            abort(404);
        }

        return response()->json(['data' => $row]);
    }

    /**
     * `GET /v1/me/payments` - rows 16 and 17, across every child.
     *
     * Not child-scoped, because row 16 is not: it is granted on any valid
     * link. Each entry names the child it belongs to so the app can group.
     */
    public function payments(): JsonResponse
    {
        $context = $this->context();
        $phone = $context->guardian->phone;

        $rows = [];

        foreach ($context->validLinks() as $link) {
            $studentId = (int) $link->student_id;

            $canWide = $this->policy->allows(GuardianCapability::R15ViewReceipts, $studentId)
                || $this->policy->allows(GuardianCapability::R17ViewOtherGuardianPayments, $studentId);
            $canOwn = $this->policy->allows(GuardianCapability::R16ViewOwnPayments, $studentId);

            if (! $canWide && ! $canOwn) {
                continue;
            }

            $enrollmentId = $this->statement->latestEnrollmentId($studentId);

            if ($enrollmentId === null) {
                continue;
            }

            $currency = $this->statement->currency($enrollmentId);

            foreach ($this->statement->receipts($enrollmentId, $phone) as $receipt) {
                // The row-16 floor: without the wide grant, only best-effort
                // matches on this guardian's own number survive.
                if (! $canWide && ! $receipt->is_own) {
                    continue;
                }

                $rows[] = [
                    'id' => $receipt->id,
                    'student_id' => $studentId,
                    'receipt_no' => $receipt->receipt_no,
                    'paid_on' => $receipt->value_date,
                    'amount' => $receipt->amount,
                    'currency' => $currency,
                    'payment_method' => $receipt->payment_method,
                    'clearing_state' => $receipt->clearing_state,
                    'is_own' => $receipt->is_own,
                    'can_download_receipt' => $this->policy->allows(GuardianCapability::R15ViewReceipts, $studentId),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['paid_on'], $b['id']] <=> [$a['paid_on'], $a['id']]);

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => count($rows)],
        ]);
    }

    /**
     * `GET /v1/me/children/{student}/receipts/{payment}` - row 15.
     *
     * A receipt DESCRIPTOR, not bytes, and the reason is the same one
     * ChildDocuments records: 10-documents §4.8 makes RenderDocument the only
     * path to a PDF and gates it on `documents.print`, a staff permission the
     * guardian role must never hold. Forking that Action would put a second,
     * weaker path to a signed financial document in the product.
     *
     * What a parent actually needs from a receipt - the number, the amount,
     * the date, the method and a verification code that resolves at the public
     * /documents/verify page - is all here.
     */
    public function receipt(int $student, int $payment): JsonResponse
    {
        $this->requireChild($student);

        if (! $this->policy->allows(GuardianCapability::R15ViewReceipts, $student)) {
            abort(403);
        }

        $enrollmentId = $this->statement->latestEnrollmentId($student);
        $row = $enrollmentId === null ? null : $this->statement->receipt($enrollmentId, $payment);

        if ($row === null) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'id' => $row->id,
                'receipt_no' => $row->receipt_no,
                'paid_on' => $row->value_date,
                'amount' => $row->amount,
                'currency' => $this->statement->currency($enrollmentId),
                'payment_method' => $row->payment_method,
                'clearing_state' => $row->clearing_state,
                'payer_name' => $row->payer_name,
                'reference' => $row->reference,
                // The school's own verify page. The app renders this as a QR;
                // a front desk scans it and sees the same answer a parent does.
                'verify_url' => url('/documents/verify?serial='.rawurlencode($row->receipt_no)),
            ],
        ]);
    }

    /**
     * `POST /v1/me/children/{student}/payments` - row 18.
     *
     * Specced, routed, gated and audited-by-absence: it returns 501 because no
     * payment gateway exists (spec §1 non-goals, §5). The route ships now so
     * the app can build its payment flow against a stable contract and get a
     * truthful answer, rather than against a mock that would have to be torn
     * out when a real gateway lands.
     *
     * The capability check runs FIRST and for real: a guardian without row 18
     * gets 403, not 501. Otherwise this endpoint would become an oracle for
     * which links are fee payers.
     */
    public function initiatePayment(int $student): JsonResponse
    {
        $this->requireChild($student);

        if (! $this->policy->allows(GuardianCapability::R18InitiatePayment, $student)) {
            abort(403);
        }

        return response()->json([
            'error' => [
                'code' => 'not_implemented',
                'message' => 'Online payment is not available yet. Please pay at the school office.',
                'details' => [],
            ],
        ], 501);
    }

    /** Row 32: no valid link, no child - and no confirmation that one exists. */
    private function requireChild(int $student): void
    {
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $student)) {
            abort(404);
        }
    }

    private function asOf(): string
    {
        return $this->context()->asOf;
    }

    private function context(): PortalContext
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        return $context;
    }
}
