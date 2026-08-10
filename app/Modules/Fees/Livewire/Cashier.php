<?php

declare(strict_types=1);

namespace App\Modules\Fees\Livewire;

use App\Modules\Fees\Actions\CloseCashDeskSession;
use App\Modules\Fees\Actions\OpenCashDeskSession;
use App\Modules\Fees\Actions\RecordPayment;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Domain\PaymentVoidReason;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fee Collection (Cashier) at /finance - mockup panel 3, gated `fee.collect`.
 *
 * Search a student (query builder over `students` - ModuleBoundaryTest bars
 * a Fees class from Students\Models), review the open-invoice breakdown,
 * record a payment through F3's RecordPayment. That Action owns the entire
 * financial consequence (receipt number, oldest-first allocation, posting
 * through the Phase 4 rule engine); this screen owns none of it.
 *
 * Domain refusals (no open accounting period, no posting rule, negative
 * amount past the browser…) surface as inline text, never a 500:
 * ValidationException lands in the error bag under the field it names,
 * DomainException in a banner, verbatim - those messages are written for
 * operators.
 */
#[Layout('layouts.app')]
final class Cashier extends Component
{
    public string $search = '';

    public ?int $studentId = null;

    public string $amount = '';

    public string $method = 'cash';

    /**
     * 02-accounting §2 + §11.3 - "Received into": the class-5 account the
     * money actually landed in (57x cash box, 552x MTN/Orange float, 52x
     * bank). Defaulted from the chosen method, overridable by the cashier -
     * a school with two MoMo floats must be able to say WHICH one took the
     * note, which is the whole reason the bursar could never reconcile the
     * MTN balance before.
     */
    public string $treasuryAccountId = '';

    public string $reference = '';

    public string $receiptNo = '';

    // Phase 13 D3 (10-documents §10.1): the payment id the just-recorded
    // receipt prints from - the Print Receipt button needs it, receiptNo
    // alone is not enough to address /finance/payments/{payment}/receipt.
    public ?int $lastPaymentId = null;

    public string $errorMessage = '';

    // Void-a-payment toggle form (04-fees §11.5). The cashier screen has no
    // payments list to attach a per-row action to (only the invoice
    // breakdown is shown here), so voiding is a standalone lookup-by-receipt
    // form, same shape as the payment-details panel it sits beside.
    public bool $showVoidForm = false;

    public string $voidReceiptNo = '';

    public string $voidReasonType = '';

    public string $voidReasonNote = '';

    public string $voidStatus = '';

    // ── Cash desk (04-fees §11.7 / §17.2) ──────────────────────────────
    // The shift. §17.2: "If the user has no open cash_desk_session for
    // business_date(), the first collection of the day prompts to open one
    // with an opening float. Collect is blocked until a session exists for
    // cash-method payments." That block lives HERE, on the screen, and
    // deliberately not inside RecordPayment - the demo seeder, the guardian
    // portal and the test suite all call that Action and none of them has a
    // till. A human at a counter can be told to open one; a seeder cannot.

    public bool $showOpenSessionForm = false;

    public string $openingFloat = '';

    /** The 57x cash box the session runs on. Defaulted to the first one. */
    public string $sessionTreasuryAccountId = '';

    public bool $showCloseSessionForm = false;

    public string $countedCash = '';

    public string $varianceReason = '';

    public string $sessionMessage = '';

    public string $sessionError = '';

    public function mount(): void
    {
        // The SCREEN is readable under fee.view (nav/route agreement - the
        // sidebar's finance item is gated fee.view). The ACT of collecting
        // requires fee.collect: checked in collect() below, re-authorized
        // inside RecordPayment, and the view disables the button without it.
        Gate::authorize(Permission::FeeView->value);

        $this->treasuryAccountId = $this->defaultTreasuryAccountId($this->method);
        $this->sessionTreasuryAccountId = $this->defaultCashBoxId();
    }

    /**
     * Changing the method re-defaults the float, because "mobile money into
     * the cash box" is never what the cashier meant; an explicit override
     * afterwards is still theirs to make.
     */
    public function updatedMethod(): void
    {
        $this->treasuryAccountId = $this->defaultTreasuryAccountId($this->method);
    }

    /**
     * The first postable, non-archived class-5 account of the method's own
     * family - cash -> 57…, mobile money -> 55… (552x), bank -> 52….
     * Cross-module read via the query builder (00-core §6.2).
     */
    private function defaultTreasuryAccountId(string $method): string
    {
        $prefix = match ($method) {
            'mobile_money' => '55',
            'bank' => '52',
            default => '57',
        };

        $id = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->where('code', 'like', $prefix.'%')
            ->orderBy('code')
            ->value('id');

        return $id === null ? '' : (string) $id;
    }

    /**
     * Every place money can land: class-5, postable, not archived.
     *
     * @return list<array{id: int, label: string}>
     */
    private function treasuryOptions(): array
    {
        $rows = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => $row->code.' · '.$row->name,
            ];
        }

        return $options;
    }

    /**
     * The first postable, non-archived 57x *Caisse* account - what a cash
     * desk actually is (02-accounting §2). Cross-module read via the query
     * builder (00-core §6.2).
     */
    private function defaultCashBoxId(): string
    {
        $id = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->where('code', 'like', '57%')
            ->orderBy('code')
            ->value('id');

        return $id === null ? '' : (string) $id;
    }

    /**
     * Every cash box a session may run on.
     *
     * @return list<array{id: int, label: string}>
     */
    private function cashBoxOptions(): array
    {
        $rows = DB::table('chart_of_accounts')
            ->where('account_class', 5)
            ->where('is_postable', true)
            ->where('is_archived', false)
            ->where('code', 'like', '57%')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => $row->code.' · '.$row->name,
            ];
        }

        return $options;
    }

    /**
     * THIS cashier's open session, with its live running total - the two
     * numbers the counter needs all day. Expected is recomputed here exactly
     * as CloseCashDeskSession recomputes it, so the panel never disagrees
     * with the close-out sheet.
     *
     * @return array{id: int, session_no: string, business_date: string, opened_at: string, opening_float: int, treasury_account_id: int, treasury_label: string, collected: int, expected: int, collections: int}|null
     */
    private function openSession(): ?array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        /** @var object{id: int|string, session_no: string, business_date: string, opened_at: string, opening_float: int|string, treasury_account_id: int|string, code: string, name: string}|null $row */
        $row = DB::table('cash_desk_sessions as s')
            ->join('chart_of_accounts as a', 'a.id', '=', 's.treasury_account_id')
            ->where('s.opened_by', $userId)
            ->where('s.status', 'open')
            ->first([
                's.id', 's.session_no', 's.business_date', 's.opened_at',
                's.opening_float', 's.treasury_account_id', 'a.code', 'a.name',
            ]);

        if ($row === null) {
            return null;
        }

        $live = DB::table('payments as p')
            ->where('p.cash_desk_session_id', $row->id)
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            });

        $collected = (int) (clone $live)->sum('p.amount');
        $count = (int) (clone $live)->count();

        return [
            'id' => (int) $row->id,
            'session_no' => (string) $row->session_no,
            'business_date' => (string) $row->business_date,
            'opened_at' => (string) $row->opened_at,
            'opening_float' => (int) $row->opening_float,
            'treasury_account_id' => (int) $row->treasury_account_id,
            'treasury_label' => $row->code.' · '.$row->name,
            'collected' => $collected,
            'collections' => $count,
            'expected' => (int) $row->opening_float + $collected,
        ];
    }

    public function toggleOpenSessionForm(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->showOpenSessionForm = ! $this->showOpenSessionForm;
        $this->sessionError = '';
        $this->resetErrorBag();

        if (! $this->showOpenSessionForm) {
            $this->reset(['openingFloat']);
        }
    }

    public function toggleCloseSessionForm(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->showCloseSessionForm = ! $this->showCloseSessionForm;
        $this->sessionError = '';
        $this->resetErrorBag();

        if (! $this->showCloseSessionForm) {
            $this->reset(['countedCash', 'varianceReason']);
        }
    }

    public function openSessionAction(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->sessionError = '';
        $this->sessionMessage = '';
        $this->resetErrorBag();

        $this->validate([
            'openingFloat' => ['required', 'integer', 'min:0'],
            'sessionTreasuryAccountId' => ['required', 'integer'],
        ], [
            'openingFloat.required' => 'Declare the opening float, even if it is zero.',
            'openingFloat.integer' => 'The opening float must be a whole number of FCFA.',
            'openingFloat.min' => 'An opening float cannot be negative.',
            'sessionTreasuryAccountId.required' => 'Choose the cash box this session runs on.',
        ]);

        $user = auth()->user();
        $actor = new Actor($user?->id, $user->name ?? 'system');

        try {
            $session = app(OpenCashDeskSession::class)->handle(
                treasuryAccountId: (int) $this->sessionTreasuryAccountId,
                openingFloat: Money::of((int) $this->openingFloat),
                actor: $actor,
            );

            $this->sessionMessage = 'Cash-desk session '.$session->session_no.' is open.';
            $this->reset(['showOpenSessionForm', 'openingFloat']);
        } catch (ValidationException $exception) {
            $this->sessionError = $this->firstMessage($exception);
        } catch (DomainException $exception) {
            $this->sessionError = $exception->getMessage();
        }
    }

    public function closeSessionAction(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->sessionError = '';
        $this->sessionMessage = '';
        $this->resetErrorBag();

        $session = $this->openSession();

        if ($session === null) {
            $this->sessionError = 'You have no open cash-desk session to close.';

            return;
        }

        $this->validate([
            'countedCash' => ['required', 'integer', 'min:0'],
            'varianceReason' => ['nullable', 'string', 'max:400'],
        ], [
            'countedCash.required' => 'Count the till and declare the amount.',
            'countedCash.integer' => 'The counted cash must be a whole number of FCFA.',
            'countedCash.min' => 'A counted till cannot be negative.',
        ]);

        $user = auth()->user();
        $actor = new Actor($user?->id, $user->name ?? 'system');

        try {
            $closed = app(CloseCashDeskSession::class)->handle(
                sessionId: $session['id'],
                countedCash: Money::of((int) $this->countedCash),
                actor: $actor,
                varianceReason: $this->varianceReason !== '' ? $this->varianceReason : null,
            );

            $variance = (int) ($closed->variance ?? 0);

            $this->sessionMessage = $variance === 0
                ? 'Session '.$closed->session_no.' closed; the till balanced.'
                : sprintf(
                    'Session %s closed with a %s of %s (journal entry #%s).',
                    $closed->session_no,
                    $variance < 0 ? 'shortage' : 'overage',
                    Money::of(abs($variance))->format(),
                    $closed->journal_entry_id ?? '—',
                );

            $this->reset(['showCloseSessionForm', 'countedCash', 'varianceReason']);
        } catch (ValidationException $exception) {
            // A missing reason belongs on its own field; everything else is a
            // banner.
            foreach ($exception->errors() as $field => $messages) {
                $message = (string) ($messages[0] ?? $exception->getMessage());

                if ($field === 'variance_reason') {
                    $this->addError('varianceReason', $message);

                    continue;
                }

                $this->sessionError = $message;
            }
        } catch (DomainException $exception) {
            // The 02-accounting §11.5 blocking gate: no configured
            // shortage/overage posting rule means NOTHING was written and the
            // session is still open. The operator reads why.
            $this->sessionError = $exception->getMessage();
        }
    }

    private function firstMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            return (string) ($messages[0] ?? $exception->getMessage());
        }

        return $exception->getMessage();
    }

    public function canCollect(): bool
    {
        return Gate::allows(Permission::FeeCollect->value);
    }

    public function canVoid(): bool
    {
        return Gate::allows(Permission::FeeVoid->value);
    }

    public function toggleVoidForm(): void
    {
        Gate::authorize(Permission::FeeVoid->value);

        $this->showVoidForm = ! $this->showVoidForm;

        if (! $this->showVoidForm) {
            $this->reset(['voidReceiptNo', 'voidReasonType', 'voidReasonNote']);
            $this->resetErrorBag();
        }
    }

    public function voidPayment(): void
    {
        Gate::authorize(Permission::FeeVoid->value);

        $this->voidStatus = '';
        $this->resetErrorBag();

        $this->validate([
            'voidReceiptNo' => ['required', 'string', 'max:120'],
            'voidReasonType' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (PaymentVoidReason $r): string => $r->value,
                PaymentVoidReason::cases(),
            ))],
            'voidReasonNote' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'voidReceiptNo.required' => 'Enter the receipt number of the payment to void.',
            'voidReasonType.required' => 'Select a void reason.',
            'voidReasonType.in' => 'Select a void reason.',
            'voidReasonNote.required' => 'A void reason note is required.',
            'voidReasonNote.min' => 'The void reason note must be at least 10 characters.',
        ]);

        $paymentId = Payment::query()->where('receipt_no', trim($this->voidReceiptNo))->value('id');

        if ($paymentId === null) {
            $this->addError('voidReceiptNo', 'No payment carries this receipt number.');

            return;
        }

        $user = auth()->user();
        $actor = new Actor($user?->id, $user->name ?? 'system');

        try {
            $void = app(VoidPayment::class)->handle(
                paymentId: (int) $paymentId,
                reason: PaymentVoidReason::from($this->voidReasonType),
                reasonNote: $this->voidReasonNote,
                actor: $actor,
            );

            $this->voidStatus = 'Payment '.$this->voidReceiptNo.' voided.';
            session()->flash('status', $this->voidStatus);
            $this->reset(['showVoidForm', 'voidReceiptNo', 'voidReasonType', 'voidReasonNote']);
            unset($void);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('void'.str($field)->studly()->toString(), (string) ($messages[0] ?? $exception->getMessage()));
            }
        } catch (DomainException $exception) {
            $this->addError('voidReceiptNo', $exception->getMessage());
        }
    }

    public function updatedSearch(): void
    {
        // A new search implicitly abandons the previous selection.
        $this->studentId = null;
        $this->receiptNo = '';
        $this->errorMessage = '';
    }

    public function selectStudent(int $studentId): void
    {
        $exists = DB::table('students')->where('id', $studentId)->exists();

        if ($exists) {
            $this->studentId = $studentId;
            $this->receiptNo = '';
            $this->errorMessage = '';
        }
    }

    public function clearSelection(): void
    {
        $this->reset(['studentId', 'search', 'amount', 'reference', 'receiptNo', 'lastPaymentId', 'errorMessage']);
    }

    public function collect(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->receiptNo = '';
        $this->errorMessage = '';

        $studentId = $this->studentId;

        if ($studentId === null) {
            return;
        }

        $this->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,mobile_money,bank'],
            // Required for all three v1 methods (02-accounting §2): every one
            // of them lands somewhere real. Enforced here AND in the Action -
            // never as a DB CHECK, which cannot dereference the FK to see
            // that the account is class 5 and postable.
            'treasuryAccountId' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [
            'amount.required' => __('opes.fees_screen.amount_invalid'),
            'amount.integer' => __('opes.fees_screen.amount_invalid'),
            'amount.min' => __('opes.fees_screen.amount_invalid'),
            'treasuryAccountId.required' => __('opes.fees_screen.treasury_account_required'),
            'treasuryAccountId.integer' => __('opes.fees_screen.treasury_account_required'),
        ]);

        $method = PaymentMethod::from($this->method);

        // 04-fees §17.2 - "Collect is blocked until a session exists for
        // cash-method payments." Only CASH: a MoMo or bank receipt does not
        // pass through the till and blocking it would be theatre.
        $session = $this->openSession();
        $cashDeskSessionId = null;

        if ($method === PaymentMethod::Cash) {
            if ($session === null) {
                $this->errorMessage = 'No cash-desk session is open. Open one with its opening float before collecting cash (04-fees §17.2).';
                $this->showOpenSessionForm = true;

                return;
            }

            if ($session['treasury_account_id'] !== (int) $this->treasuryAccountId) {
                $this->errorMessage = sprintf(
                    'Your open session %s runs on %s. Collect cash into that box, or close the session first.',
                    $session['session_no'],
                    $session['treasury_label'],
                );

                return;
            }

            $cashDeskSessionId = $session['id'];
        }

        $academicYearId = DB::table('academic_years')->where('is_current', true)->value('id');
        $fiscalYearId = DB::table('fiscal_years')->where('status', 'open')->value('id');

        if ($academicYearId === null || $fiscalYearId === null) {
            // No current academic year / open fiscal year configured: a
            // configuration gap the operator can name to an administrator.
            $this->errorMessage = __('opes.fees_screen.no_open_year');

            return;
        }

        /** @var object{first_name: string, last_name: string}|null $student */
        $student = DB::table('students')->where('id', $studentId)->first(['first_name', 'last_name']);

        if ($student === null) {
            return;
        }

        $user = auth()->user();
        $actor = new Actor($user?->id, $user->name ?? 'system');

        try {
            $payment = app(RecordPayment::class)->handle(
                studentId: $studentId,
                academicYearId: (int) $academicYearId,
                fiscalYearId: (int) $fiscalYearId,
                method: $method,
                amount: Money::of((int) $this->amount),
                // The walk-up payer at a cashier desk is recorded as the
                // student's own name unless a dedicated payer field exists;
                // the mockup's form carries no payer input.
                payerName: $student->first_name.' '.$student->last_name,
                valueDate: BusinessDate::today(),
                actor: $actor,
                reference: $this->reference !== '' ? $this->reference : null,
                enrollmentId: $this->latestEnrollmentId($studentId),
                treasuryAccountId: (int) $this->treasuryAccountId,
                // §11.7: file the collection under the shift that took it -
                // set above for cash only, and only once a session was found
                // to be open on the very box the money is landing in.
                cashDeskSessionId: $cashDeskSessionId,
            );

            $this->receiptNo = $payment->receipt_no;
            $this->lastPaymentId = (int) $payment->getKey();
            $this->reset(['amount', 'reference']);
        } catch (ValidationException $exception) {
            // The Action's own refusals (an archived or non-class-5 float,
            // a school with no treasury account at all) land on the field
            // that names them, verbatim - they are written for operators.
            foreach ($exception->errors() as $field => $messages) {
                $message = (string) ($messages[0] ?? $exception->getMessage());

                if ($field === 'treasury_account_id') {
                    $this->addError('treasuryAccountId', $message);

                    continue;
                }

                $this->errorMessage = $message;
            }
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    private function latestEnrollmentId(int $studentId): ?int
    {
        $id = DB::table('enrollments')
            ->where('student_id', $studentId)
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<array{id: int, name: string, matricule: string}>
     */
    private function results(): array
    {
        if ($this->search === '' || $this->studentId !== null) {
            return [];
        }

        $term = '%'.$this->search.'%';

        $rows = DB::table('students')
            ->where('is_archived', false)
            ->where(function ($query) use ($term): void {
                $query->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('matricule', 'like', $term)
                    ->orWhere('admission_no', 'like', $term);
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'matricule']);

        $results = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, first_name: string, last_name: string, matricule: string} $row */
            $results[] = [
                'id' => (int) $row->id,
                'name' => $row->first_name.' '.$row->last_name,
                'matricule' => (string) $row->matricule,
            ];
        }

        return $results;
    }

    /**
     * @return array{id: int, name: string, matricule: string, class: string, initials: string}|null
     */
    private function selected(): ?array
    {
        if ($this->studentId === null) {
            return null;
        }

        /** @var object{id: int|string, first_name: string, last_name: string, matricule: string}|null $row */
        $row = DB::table('students')
            ->where('id', $this->studentId)
            ->first(['id', 'first_name', 'last_name', 'matricule']);

        if ($row === null) {
            return null;
        }

        $className = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', $this->studentId)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        return [
            'id' => (int) $row->id,
            'name' => $row->first_name.' '.$row->last_name,
            'matricule' => (string) $row->matricule,
            'class' => is_string($className) ? $className : '',
            'initials' => mb_strtoupper(mb_substr($row->first_name, 0, 1).mb_substr($row->last_name, 0, 1)),
        ];
    }

    /**
     * Per-line outstanding over the student's ISSUED invoices - the §5
     * formula at line grain: (amount + tax) − valid allocations − approved
     * adjustments − issued credit notes. Balance is computed, never stored.
     *
     * @return list<array{invoice_no: string, description: string, amount: int, outstanding: int}>
     */
    private function breakdown(): array
    {
        if ($this->studentId === null) {
            return [];
        }

        $allocated = "(SELECT COALESCE(SUM(pa.amount), 0)
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            WHERE pa.invoice_line_id = l.id
              AND pa.reversed_at IS NULL
              AND p.clearing_state <> 'bounced'
              AND NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id AND v.status = 'confirmed'))";

        $adjusted = "(SELECT COALESCE(SUM(fa.amount), 0) FROM fee_adjustments fa
            WHERE fa.invoice_line_id = l.id AND fa.status = 'approved')";

        $credited = "(SELECT COALESCE(SUM(cnl.amount + cnl.tax_amount), 0)
            FROM credit_note_lines cnl
            JOIN credit_notes cn ON cn.id = cnl.credit_note_id
            WHERE cnl.invoice_line_id = l.id AND cn.status = 'issued')";

        $rows = DB::table('invoice_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.student_id', $this->studentId)
            ->where('i.status', 'issued')
            ->orderBy('i.issue_date')
            ->orderBy('i.id')
            ->orderBy('l.line_no')
            ->select([
                'i.invoice_no',
                'l.description',
                DB::raw('(l.amount + l.tax_amount) as gross'),
                DB::raw("((l.amount + l.tax_amount) - {$allocated} - {$adjusted} - {$credited}) as outstanding"),
            ])
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            /** @var object{invoice_no: string|null, description: string, gross: int|string, outstanding: int|string} $row */
            $breakdown[] = [
                'invoice_no' => (string) ($row->invoice_no ?? ''),
                'description' => (string) $row->description,
                'amount' => (int) $row->gross,
                'outstanding' => (int) $row->outstanding,
            ];
        }

        return $breakdown;
    }

    /**
     * @param  list<array{invoice_no: string, description: string, amount: int, outstanding: int}>  $breakdown
     * @return array{invoiced: int, paid: int, balance: int}
     */
    private function totals(array $breakdown): array
    {
        $invoiced = 0;
        $balance = 0;

        foreach ($breakdown as $line) {
            $invoiced += $line['amount'];
            $balance += $line['outstanding'];
        }

        $paid = 0;

        if ($this->studentId !== null) {
            $paid = (int) DB::table('payments as p')
                ->where('p.student_id', $this->studentId)
                ->where('p.clearing_state', '<>', 'bounced')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('payment_voids as v')
                        ->whereColumn('v.payment_id', 'p.id')
                        ->where('v.status', 'confirmed');
                })
                ->sum('p.amount');
        }

        return ['invoiced' => $invoiced, 'paid' => $paid, 'balance' => $balance];
    }

    public function render(): mixed
    {
        $breakdown = $this->breakdown();

        return view('livewire.fees.cashier', [
            'results' => $this->results(),
            'selected' => $this->selected(),
            'breakdown' => $breakdown,
            'totals' => $this->totals($breakdown),
            'treasuryOptions' => $this->treasuryOptions(),
            'cashBoxOptions' => $this->cashBoxOptions(),
            'session' => $this->openSession(),
            'methodOptions' => array_map(static fn (PaymentMethod $m): string => $m->value, PaymentMethod::cases()),
            'canCollect' => $this->canCollect(),
            'canVoid' => $this->canVoid(),
            'voidReasonOptions' => array_map(static fn (PaymentVoidReason $r): string => $r->value, PaymentVoidReason::cases()),
        ]);
    }
}
