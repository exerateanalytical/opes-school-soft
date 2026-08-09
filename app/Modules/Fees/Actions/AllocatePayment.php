<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\ClearingState;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\PaymentAllocation;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §11.2/§11.6/§12.4 - allocates a payment's
 * unallocated amount to invoice LINES (A2), either against explicit
 * cashier-chosen targets or automatically OLDEST-FIRST: ascending invoice
 * due date, then invoice id, then line number (§12.4's stated order, so two
 * developers produce the same allocation). Whatever cannot land on a line
 * stays as `unallocated_amount` - pre-payment credit is a first-class
 * state (C10), never an error.
 *
 * Locking is §11.2 verbatim: invoices FOR UPDATE in ascending-id order,
 * outstanding recomputed INSIDE the lock from §5's formula, the §5.3
 * line invariant asserted before any row is written. The browser's figure
 * is never trusted.
 */
final class AllocatePayment
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  list<array{invoice_line_id: int, amount: int}>|null  $targets
     *                                                                        Explicit line targets, or null for §12.4 auto-allocation.
     */
    public function handle(int $paymentId, ?array $targets, Actor $actor): Payment
    {
        Gate::authorize(Permission::FeeCollect->value);

        return DB::transaction(function () use ($paymentId, $targets, $actor): Payment {
            return $this->allocateLocked(Payment::lockForAllocation($paymentId), $targets, $actor);
        });
    }

    /**
     * The engine; the caller (handle() above, or RecordPayment inside its
     * own transaction) holds the payment row lock already.
     *
     * @param  list<array{invoice_line_id: int, amount: int}>|null  $targets
     */
    public function allocateLocked(Payment $payment, ?array $targets, Actor $actor): Payment
    {
        if ($payment->isVoided()) {
            throw new DomainException('A voided payment cannot be allocated (04-fees §11.5).');
        }

        if ($payment->clearing_state === ClearingState::Bounced) {
            throw new DomainException('A bounced payment cannot be allocated (04-fees §11.4).');
        }

        $available = Money::of($payment->unallocated_amount);

        if ($available->isZero()) {
            return $payment;
        }

        // The invoice tables are built by a parallel Phase 6 work package
        // (migrations 240005+). Until they exist there is nothing to
        // allocate against, and the whole payment legitimately stays as
        // student credit (C10).
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_lines')) {
            return $payment;
        }

        $lines = $targets === null
            ? $this->autoTargets($payment)
            : $this->explicitTargets($payment, $targets);

        if ($lines === []) {
            return $payment;
        }

        $allocatedTotal = Money::zero();

        foreach ($lines as $line) {
            if ($available->isZero()) {
                break;
            }

            $want = Money::of($line['amount']);
            $take = $want->isLessThanOrEqualTo($available) ? $want : $available;

            if (! $take->isPositive()) {
                continue;
            }

            $this->writeAllocation($payment, $line['invoice_id'], $line['invoice_line_id'], $take, $actor);

            $available = $available->minus($take);
            $allocatedTotal = $allocatedTotal->plus($take);
        }

        if ($allocatedTotal->isPositive()) {
            $payment->unallocated_amount = $available->amount();
            $payment->save();

            $this->assertConservation($payment);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Fees',
                auditableType: Payment::class,
                auditableId: (int) $payment->getKey(),
                after: [
                    'allocated' => $allocatedTotal->amount(),
                    'unallocated_amount' => $available->amount(),
                ],
                actor: $actor,
            );
        }

        return $payment;
    }

    /**
     * §12.4 order: oldest invoice due date first, then invoice id, then
     * line number. Only ISSUED invoices carry a balance (§3.1). Each entry
     * carries the line's outstanding as the maximum allocatable.
     *
     * @return list<array{invoice_id: int, invoice_line_id: int, amount: int}>
     */
    private function autoTargets(Payment $payment): array
    {
        $invoiceIds = DB::table('invoices')
            ->where('student_id', $payment->student_id)
            ->where('status', 'issued')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($invoiceIds === []) {
            return [];
        }

        /** @var list<object{id: int|string, invoice_id: int|string, amount: int|string, tax_amount: int|string}> $rows */
        $rows = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereIn('invoice_lines.invoice_id', $invoiceIds)
            ->orderBy('invoices.due_date')
            ->orderBy('invoices.id')
            ->orderBy('invoice_lines.line_no')
            ->get(['invoice_lines.id', 'invoice_lines.invoice_id', 'invoice_lines.amount', 'invoice_lines.tax_amount'])
            ->all();

        $targets = [];

        foreach ($rows as $row) {
            $outstanding = $this->outstanding((int) $row->id, (int) $row->amount + (int) $row->tax_amount);

            if ($outstanding->isPositive()) {
                $targets[] = [
                    'invoice_id' => (int) $row->invoice_id,
                    'invoice_line_id' => (int) $row->id,
                    'amount' => $outstanding->amount(),
                ];
            }
        }

        return $targets;
    }

    /**
     * Cashier-chosen targets: §11.2 lock order (ascending invoice id), then
     * the §5.3 invariant per line - a request to allocate past a line's
     * outstanding ABORTS; silent truncation is how a cashier hands out a
     * receipt for money the ledger did not take.
     *
     * @param  list<array{invoice_line_id: int, amount: int}>  $targets
     * @return list<array{invoice_id: int, invoice_line_id: int, amount: int}>
     */
    private function explicitTargets(Payment $payment, array $targets): array
    {
        $lineIds = array_map(static fn (array $t): int => $t['invoice_line_id'], $targets);

        /** @var array<int, object{id: int|string, invoice_id: int|string, student_id: int|string, status: string, amount: int|string, tax_amount: int|string}> $lines */
        $lines = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereIn('invoice_lines.id', $lineIds)
            ->orderBy('invoice_lines.invoice_id')
            ->lockForUpdate()
            ->get([
                'invoice_lines.id', 'invoice_lines.invoice_id', 'invoices.student_id',
                'invoices.status', 'invoice_lines.amount', 'invoice_lines.tax_amount',
            ])
            ->keyBy('id')
            ->all();

        $resolved = [];

        foreach ($targets as $target) {
            $line = $lines[$target['invoice_line_id']] ?? null;

            if ($line === null) {
                throw new DomainException("Invoice line {$target['invoice_line_id']} does not exist.");
            }

            if ((int) $line->student_id !== $payment->student_id) {
                throw new DomainException(
                    'An allocation may not cross students (04-fees A5 - OHADA non-compensation).'
                );
            }

            if ($line->status !== 'issued') {
                throw new DomainException('Only an issued invoice can receive an allocation (04-fees §3.1).');
            }

            $outstanding = $this->outstanding((int) $line->id, (int) $line->amount + (int) $line->tax_amount);

            if (Money::of($target['amount'])->isGreaterThan($outstanding)) {
                throw new DomainException(sprintf(
                    'Allocating %d to invoice line %d would exceed its outstanding of %d (04-fees §5.3).',
                    $target['amount'],
                    (int) $line->id,
                    $outstanding->amount(),
                ));
            }

            $resolved[] = [
                'invoice_id' => (int) $line->invoice_id,
                'invoice_line_id' => (int) $line->id,
                'amount' => $target['amount'],
            ];
        }

        return $resolved;
    }

    /**
     * §5's formula for one line, recomputed inside the lock. The
     * adjustment / credit-note / write-off terms are subtracted when their
     * tables (owned by parallel Phase 6 packages) exist, so this method is
     * already correct the day they land.
     */
    private function outstanding(int $invoiceLineId, int $invoiced): Money
    {
        $result = Money::of($invoiced)->minus($this->allocated($invoiceLineId));

        if (Schema::hasTable('fee_adjustments')) {
            $result = $result->minus(Money::of((int) DB::table('fee_adjustments')
                ->where('invoice_line_id', $invoiceLineId)
                ->where('status', 'approved')
                ->sum('amount')));
        }

        if (Schema::hasTable('credit_note_lines') && Schema::hasTable('credit_notes')) {
            $result = $result->minus(Money::of((int) DB::table('credit_note_lines')
                ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
                ->where('credit_note_lines.invoice_line_id', $invoiceLineId)
                ->where('credit_notes.status', 'issued')
                ->sum('credit_note_lines.amount')));
        }

        if (Schema::hasTable('write_off_lines') && Schema::hasTable('write_offs')) {
            $result = $result->minus(Money::of((int) DB::table('write_off_lines')
                ->join('write_offs', 'write_offs.id', '=', 'write_off_lines.write_off_id')
                ->where('write_off_lines.invoice_line_id', $invoiceLineId)
                ->where('write_offs.status', 'approved')
                ->sum('write_off_lines.amount')));
        }

        return $result;
    }

    /**
     * §5.1 allocated(ℓ) with §5.2's two exclusions - the C1 defect: a
     * VOIDED payment's allocations and a BOUNCED payment's allocations
     * contribute nothing, and reversed rows never count.
     */
    private function allocated(int $invoiceLineId): Money
    {
        $sum = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.invoice_line_id', $invoiceLineId)
            ->whereNull('payment_allocations.reversed_at')
            ->where('payments.clearing_state', '<>', ClearingState::Bounced->value)
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_voids')
                    ->whereColumn('payment_voids.payment_id', 'payments.id')
                    ->where('payment_voids.status', 'confirmed');
            })
            ->sum('payment_allocations.amount');

        return Money::of((int) $sum);
    }

    /**
     * §11.6: one live row per (payment, line); a top-up increases the
     * existing row's amount rather than inserting a second.
     */
    private function writeAllocation(Payment $payment, int $invoiceId, int $invoiceLineId, Money $amount, Actor $actor): void
    {
        /** @var PaymentAllocation|null $existing */
        $existing = PaymentAllocation::query()
            ->where('payment_id', $payment->getKey())
            ->where('invoice_line_id', $invoiceLineId)
            ->whereNull('reversed_at')
            ->first();

        if ($existing !== null) {
            $existing->amount = Money::of($existing->amount)->plus($amount)->amount();
            $existing->save();

            return;
        }

        PaymentAllocation::query()->create([
            'payment_id' => $payment->getKey(),
            'invoice_id' => $invoiceId,
            'invoice_line_id' => $invoiceLineId,
            'amount' => $amount->amount(),
            'allocated_at' => now(),
            'allocated_by' => $actor->id,
        ]);
    }

    /**
     * §11.6's in-Action invariant, asserted at the end of every transaction
     * that touches allocations: live allocations + unallocated = amount.
     */
    private function assertConservation(Payment $payment): void
    {
        $live = (int) PaymentAllocation::query()
            ->where('payment_id', $payment->getKey())
            ->whereNull('reversed_at')
            ->sum('amount');

        if ($live + $payment->unallocated_amount !== $payment->amount) {
            throw new DomainException(sprintf(
                'Allocation conservation violated for payment %s: %d allocated + %d unallocated != %d amount (04-fees §11.6).',
                $payment->receipt_no,
                $live,
                $payment->unallocated_amount,
                $payment->amount,
            ));
        }
    }
}
