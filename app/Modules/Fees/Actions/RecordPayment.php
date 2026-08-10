<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Domain\ClearingState;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\PaymentAllocation;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/04-fees.md §11 - records a payment against the STUDENT
 * account (C10), allocates it oldest-first (or to explicit targets) via
 * AllocatePayment inside the same transaction, and posts the ledger
 * consequence through the Phase 4 posting engine: `fee.payment.received`
 * -> PostFromEvent -> the school's configured PostingRule. The §15.6 MoMo
 * shape (552 net / 6317 commission / 4111 gross, partner = student) is
 * entirely the rule's business; this Action's job is a truthful payload -
 * gross, commission-when-school-bears-it, net - and a stored
 * `journal_entry_id` so VoidPayment later reverses THE entry instead of
 * hand-building a contra.
 *
 * Manual recording only (v1 hard decision): no gateway callback ever
 * creates a payment; a human with `fee.collect` does.
 *
 * The row is immutable from the moment this returns - the model observer
 * throws on any financial edit. Corrections are VoidPayment plus a fresh
 * RecordPayment (A3). Surplus over the student's outstanding becomes
 * `unallocated_amount` - pre-payment and overpayment are first-class
 * states (C10/C9), which is why Money is SIGNED end to end.
 */
final class RecordPayment
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly AllocatePayment $allocate,
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  list<array{invoice_line_id: int, amount: int}>|null  $targets  explicit invoice-line targets; null = §12.4 oldest-first auto-allocation
     */
    public function handle(
        int $studentId,
        int $academicYearId,
        int $fiscalYearId,
        PaymentMethod $method,
        Money $amount,
        string $payerName,
        string $valueDate,
        Actor $actor,
        ?Money $feeAmount = null,
        FeeBearer $feeBearer = FeeBearer::None,
        ?string $reference = null,
        ?string $payerPhone = null,
        ?int $enrollmentId = null,
        ?array $targets = null,
        ?string $idempotencyKey = null,
        ?string $notes = null,
        ?int $treasuryAccountId = null,
        // 04-fees §11.7: the cash-desk shift this collection belongs to.
        // OPTIONAL in the signature and NULLABLE in the row, deliberately -
        // the demo seeder, the guardian portal and the test suite have no
        // till, and §17.2's "no session, no cash collection" rule is enforced
        // at the Cashier screen where a human can be told to open one. This
        // Action must never start throwing for callers that predate sessions.
        ?int $cashDeskSessionId = null,
    ): Payment {
        Gate::authorize(Permission::FeeCollect->value);

        // 02-accounting §2 + §11.3: a payment must name the float it landed
        // in. Optional in the SIGNATURE (every pre-existing caller - demo
        // seeder, tests, guardian portal - keeps working), mandatory in the
        // stored ROW: when the caller does not name one, the method's own
        // class-5 family resolves it, and a school with no such account at
        // all is a configuration refusal, not a silent hardcoded 57.
        $treasuryAccountId = $this->resolveTreasuryAccount($treasuryAccountId, $method);

        // A named session must be OPEN and must be the session of the very
        // box the money is landing in - "collected into the MTN float, filed
        // under the cash-desk shift" is exactly the confusion §11.7 exists to
        // end. Null stays null, silently.
        $this->assertSessionAccepts($cashDeskSessionId, $treasuryAccountId);

        $feeAmount ??= Money::zero();

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages([
                'amount' => 'A payment must be a positive amount.',
            ]);
        }

        if ($feeAmount->isNegative()) {
            throw ValidationException::withMessages([
                'fee_amount' => 'A commission cannot be negative.',
            ]);
        }

        if ($method->requiresReference() && trim((string) $reference) === '') {
            throw ValidationException::withMessages([
                'reference' => sprintf(
                    'A %s payment requires the transaction reference (04-fees §2.4).',
                    $method->value,
                ),
            ]);
        }

        return DB::transaction(function () use (
            $studentId, $academicYearId, $fiscalYearId, $method, $amount, $payerName,
            $valueDate, $actor, $feeAmount, $feeBearer, $reference, $payerPhone,
            $enrollmentId, $targets, $idempotencyKey, $notes, $treasuryAccountId,
            $cashDeskSessionId,
        ): Payment {
            // The double-clicked Collect button: same key -> same payment,
            // never a second receipt (§11.1).
            if ($idempotencyKey !== null) {
                /** @var Payment|null $existing */
                $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // Cross-module read via the query builder (00-core 6.2: never
            // another module's Models) - the label is the only thing Fees
            // needs from Students here.
            /** @var object{first_name: string, middle_name: string|null, last_name: string}|null $student */
            $student = DB::table('students')
                ->where('id', $studentId)
                ->first(['first_name', 'middle_name', 'last_name']);

            if ($student === null) {
                throw ValidationException::withMessages([
                    'student_id' => 'The student does not exist.',
                ]);
            }

            $partnerLabel = trim(implode(' ', array_filter([
                $student->first_name,
                $student->middle_name,
                $student->last_name,
            ])));

            // §14: gaps-permitted, GLOBAL uniqueness scope, allocated from
            // the row-locked sequence inside this transaction - never max()+1.
            $sequence = $this->sequences->allocate('receipt_no');
            $receiptNo = sprintf('RCPT/%s/%06d', Carbon::parse($valueDate)->format('Y'), $sequence);

            /** @var Payment $payment */
            $payment = Payment::query()->create([
                'receipt_no' => $receiptNo,
                'student_id' => $studentId,
                'enrollment_id' => $enrollmentId,
                'academic_year_id' => $academicYearId,
                'fiscal_year_id' => $fiscalYearId,
                'payment_method' => $method,
                'treasury_account_id' => $treasuryAccountId,
                'cash_desk_session_id' => $cashDeskSessionId,
                'amount' => $amount->amount(),
                'fee_amount' => $feeAmount->amount(),
                'fee_bearer' => $feeBearer,
                'reference' => $reference,
                'payer_name' => $payerName,
                'payer_phone' => $payerPhone,
                'value_date' => $valueDate,
                'posting_date' => $valueDate,
                // All three v1 methods are immediate instruments (§11.4).
                'clearing_state' => ClearingState::Cleared,
                'unallocated_amount' => $amount->amount(),
                'notes' => $notes,
                'is_migration' => false,
                'idempotency_key' => $idempotencyKey,
                'received_by' => $actor->id,
            ]);

            // Allocation inside the same transaction and lock window (§11.2).
            $this->allocate->allocateLocked($payment, $targets, $actor);

            // §15.6: the commission enters the books only when the SCHOOL
            // bears it; when the payer bears it, it never entered the
            // school's money at all.
            $commission = $feeBearer === FeeBearer::School ? $feeAmount : Money::zero();

            $entry = $this->post->handle(
                PostingEvent::FeePaymentReceived->value,
                [
                    'payment' => [
                        'amount' => $amount->amount(),
                        'commission' => $commission->amount(),
                        'net_amount' => $amount->minus($commission)->amount(),
                        'reference' => $receiptNo,
                        'commission_rate_label' => $commission->isPositive() ? $commission->format() : '',
                        'partner' => ['type' => 'student', 'id' => $studentId],
                        'partner_label' => $partnerLabel,
                        'invoice_reference' => $this->firstInvoiceReference($payment),
                        // 02-accounting §7 names `payment.method.treasury_account_id`
                        // as THE account_path example for an AccountSource::PayloadPath
                        // rule line: the engine could already route the debit
                        // to the real float; the payload simply never carried
                        // one. It does now (both here and at payment root, so
                        // an existing rule can address either path).
                        'treasury_account_id' => $treasuryAccountId,
                        'method' => [
                            'fee_bearer_is_school' => $feeBearer === FeeBearer::School,
                            'treasury_account_id' => $treasuryAccountId,
                        ],
                    ],
                ],
                $valueDate,
                $actor,
                $receiptNo,
            );

            // Once-only stamp; the observer permits null -> id and nothing else.
            $payment->journal_entry_id = (int) $entry->getKey();
            $payment->save();

            // The original issuance of the printed document (§14).
            Receipt::query()->create([
                'payment_id' => $payment->getKey(),
                'receipt_no' => $receiptNo,
                'copy_no' => 1,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: Payment::class,
                auditableId: (int) $payment->getKey(),
                after: [
                    'receipt_no' => $receiptNo,
                    'student_id' => $studentId,
                    'method' => $method->value,
                    'amount' => $amount->amount(),
                    'fee_amount' => $feeAmount->amount(),
                    'unallocated_amount' => $payment->unallocated_amount,
                    'journal_entry_id' => (int) $entry->getKey(),
                ],
                actor: $actor,
            );

            return $payment->refresh();
        });
    }

    /**
     * 04-fees §11.7 - a collection may only be filed under a session that is
     * still OPEN and that runs on the same cash box the money landed in.
     *
     * Cross-module-safe plain query-builder read; no refusal at all when the
     * caller names no session, which is what keeps every pre-session caller
     * working.
     */
    private function assertSessionAccepts(?int $cashDeskSessionId, int $treasuryAccountId): void
    {
        if ($cashDeskSessionId === null) {
            return;
        }

        /** @var object{session_no: string, status: string, treasury_account_id: int|string}|null $session */
        $session = DB::table('cash_desk_sessions')
            ->where('id', $cashDeskSessionId)
            ->first(['session_no', 'status', 'treasury_account_id']);

        if ($session === null) {
            throw ValidationException::withMessages([
                'cash_desk_session_id' => 'The cash-desk session does not exist.',
            ]);
        }

        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'cash_desk_session_id' => sprintf(
                    'Cash-desk session %s is %s; a closed session cannot take another collection.',
                    $session->session_no,
                    $session->status,
                ),
            ]);
        }

        if ((int) $session->treasury_account_id !== $treasuryAccountId) {
            throw ValidationException::withMessages([
                'cash_desk_session_id' => sprintf(
                    'Cash-desk session %s runs on a different cash box than the account this payment landed in.',
                    $session->session_no,
                ),
            ]);
        }
    }

    /**
     * The class-5 treasury account this payment landed in.
     *
     * Mirrors `RecordSupplierPayment`'s treatment of its own
     * `treasury_account_id` (a plain FK into `chart_of_accounts` - in
     * SYSCOHADA the treasury account IS the class-5 ledger account), with one
     * addition Procurement does not need: fee collection has legacy callers,
     * so a null resolves to the default float of the method's own family
     * (cash -> 57…, mobile money -> 55…, bank -> 52…) rather than refusing.
     *
     * Cross-module read through the query builder, never Accounting\Models
     * (00-core §6.2).
     */
    private function resolveTreasuryAccount(?int $given, PaymentMethod $method): int
    {
        if ($given !== null) {
            /** @var object{account_class: int, is_postable: int, is_archived: int, code: string}|null $account */
            $account = DB::table('chart_of_accounts')
                ->where('id', $given)
                ->first(['account_class', 'is_postable', 'is_archived', 'code']);

            if ($account === null) {
                throw ValidationException::withMessages([
                    'treasury_account_id' => 'The selected treasury account does not exist.',
                ]);
            }

            if ((int) $account->account_class !== 5) {
                throw ValidationException::withMessages([
                    'treasury_account_id' => sprintf(
                        'Account %s is not a treasury (class 5) account (02-accounting §2).',
                        $account->code,
                    ),
                ]);
            }

            if ((bool) $account->is_archived || ! (bool) $account->is_postable) {
                throw ValidationException::withMessages([
                    'treasury_account_id' => sprintf(
                        'Account %s is archived or not postable; money cannot land in it.',
                        $account->code,
                    ),
                ]);
            }

            return $given;
        }

        $prefix = self::TREASURY_FAMILY[$method->value];

        $resolved = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->where('code', 'like', $prefix.'%')
            ->orderBy('code')
            ->value('id');

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'treasury_account_id' => sprintf(
                    'No postable treasury account exists for %s payments (expected a %s… class-5 account); '
                    .'open one on the Chart of Accounts screen first (02-accounting §2).',
                    $method->value,
                    $prefix,
                ),
            ]);
        }

        return (int) $resolved;
    }

    /**
     * The class-5 family each payment method's money lands in (02-accounting
     * §2): 57 Cash, 55 Electronic money (552 Mobile phone), 52 Banks.
     *
     * @var array<string, string>
     */
    private const TREASURY_FAMILY = [
        'cash' => '57',
        'mobile_money' => '55',
        'bank' => '52',
    ];

    /**
     * The receipt's "against invoice" label for the ledger line: the
     * invoice number of the first live allocation, or the receipt itself
     * when the whole payment went to credit.
     */
    private function firstInvoiceReference(Payment $payment): string
    {
        /** @var PaymentAllocation|null $first */
        $first = PaymentAllocation::query()
            ->where('payment_id', $payment->getKey())
            ->whereNull('reversed_at')
            ->whereNotNull('invoice_id')
            ->orderBy('id')
            ->first();

        if ($first === null || $first->invoice_id === null) {
            return '';
        }

        $invoiceNo = DB::table('invoices')->where('id', $first->invoice_id)->value('invoice_no');

        return is_string($invoiceNo) ? $invoiceNo : '';
    }
}
