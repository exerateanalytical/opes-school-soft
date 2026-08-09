<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\LetterEntries;
use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\SupplierFeeBearer;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Domain\SupplierPaymentClearingState;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use App\Modules\Procurement\Domain\SupplierRetentionStatus;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Modules\Procurement\Models\SupplierPaymentAllocation;
use App\Modules\Procurement\Models\SupplierRetention;
use App\Modules\Tax\Actions\IssueWithholdingAttestation;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.7 - execute an APPROVED payment: the
 * money moves, exclusively through PostFromEvent (a second posting path is
 * a defect). The §6.4 worked example is THE contract, one entry:
 *
 *   Dr 401  Fournisseur (gross, partner)          1 431 000
 *       Cr 5x   Banque (net disbursed)                    1 365 000
 *       Cr 447  État, retenues à la source (§6.3:            66 000
 *               resolved at the PAYMENT date)
 *   (+ Dr 6317 / Cr 5x when the school bears an operator fee)
 *
 * Netting the withholding into a single treasury credit with no 447 leg is
 * the §6.4 error this Action exists to make impossible.
 *
 * Retention (§3.3): the FIRST settlement of an invoice carrying
 * `retention_amount` also posts the reclass `Dr payable / Cr 4817` (event
 * `supplier.paid`, rule-discriminated by a negative `document.total`
 * sentinel) and records the `SupplierRetention` row `ReleaseRetention`
 * later closes. Retention never touches expense.
 *
 * Everything re-verifies under `FOR UPDATE` on the invoices - approval
 * happened outside the lock window. Full settlement letters the payable
 * (C10) via the real LetterEntries Action; attestations are issued in the
 * SAME transaction (§6.6, `on_payment` source = the payment). A closed
 * payment-date period forward-posts to the first open one (02-accounting
 * C4).
 *
 * SoD (§11.14): the approver cannot pay - identity check, not permission.
 */
final class PaySupplierPayment
{
    public function __construct(
        private readonly ComputeInvoiceSettlement $settlement,
        private readonly PostFromEvent $post,
        private readonly LetterEntries $letter,
        private readonly IssueWithholdingAttestation $issueAttestation,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $paymentId, Actor $actor): SupplierPayment
    {
        Gate::authorize(SupplierPaymentPermission::RECORD);

        $recognition = $this->settlement->recognitionBasis();

        return DB::transaction(function () use ($paymentId, $actor, $recognition): SupplierPayment {
            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SupplierPaymentStatus::Approved) {
                throw new DomainException(sprintf(
                    'Payment %s is %s; only an approved payment can be paid.',
                    $payment->payment_no,
                    $payment->status->value,
                ));
            }

            if ($payment->approved_by === $actor->id) {
                throw new DomainException(
                    'The approver of a payment cannot execute it (03-tax-procurement 11.14 segregation of duties).'
                );
            }

            /** @var list<SupplierPaymentAllocation> $allocations */
            $allocations = $payment->allocations()->whereNull('reversed_at')->orderBy('supplier_invoice_id')->get()->all();

            if ($allocations === []) {
                throw new DomainException("Payment {$payment->payment_no} has no live allocations; nothing to pay.");
            }

            $paymentDate = $payment->payment_date;
            $postingDate = $this->postingDateFor($paymentDate);

            $gross = 0;
            $withholdingTotal = 0;
            /** @var array<int, int> $withholdingByLiability liability account => amount */
            $withholdingByLiability = [];
            /** @var list<array{invoice: SupplierInvoice, allocation: SupplierPaymentAllocation, by_rule: array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}>}> $settled */
            $settled = [];

            foreach ($allocations as $allocation) {
                /** @var SupplierInvoice $invoice */
                $invoice = SupplierInvoice::query()
                    ->whereKey($allocation->supplier_invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // §9: recompute outstanding INSIDE the lock, excluding this
                // payment's own reservation.
                $settleable = $this->settlement->settleableOf($invoice, $recognition);
                $outstanding = $settleable - $this->settlement->allocatedOf((int) $invoice->getKey(), (int) $payment->getKey());

                if ($allocation->amount > $outstanding) {
                    throw new DomainException(sprintf(
                        'Invoice %s now has only %d outstanding; the approved allocation of %d is stale - re-record the payment (03-tax-procurement 4.7).',
                        $invoice->internal_no,
                        $outstanding,
                        $allocation->amount,
                    ));
                }

                $byRule = [];
                $allocationWithholding = 0;

                if ($recognition === WithholdingRecognition::OnPayment) {
                    // §6.3: selection driven by the PAYMENT date.
                    $resolved = $this->settlement->withholdingAt($invoice, $paymentDate);
                    $allocationWithholding = $this->settlement->withholdingShare(
                        allocation: $allocation->amount,
                        outstandingBefore: $outstanding,
                        settleable: $settleable,
                        fullWithholding: $resolved['total'],
                        alreadyWithheld: $this->settlement->withheldOf((int) $invoice->getKey(), (int) $payment->getKey()),
                    );

                    $byRule = $this->shareByRule($resolved['by_rule'], $resolved['total'], $allocationWithholding);

                    foreach ($byRule as $share) {
                        $withholdingByLiability[$share['liability_account_id']] =
                            ($withholdingByLiability[$share['liability_account_id']] ?? 0) + $share['amount'];
                    }
                }

                if ($allocation->withholding_amount !== $allocationWithholding) {
                    $allocation->withholding_amount = $allocationWithholding;
                    $allocation->save();
                }

                $gross += $allocation->amount;
                $withholdingTotal += $allocationWithholding;

                $settled[] = ['invoice' => $invoice, 'allocation' => $allocation, 'by_rule' => $byRule];
            }

            $net = $gross - $withholdingTotal;

            $entry = $this->post->handle(
                PostingEvent::SupplierPaid->value,
                [
                    'document' => [
                        'total' => $gross,
                        'reference' => $payment->payment_no,
                        'partner' => ['type' => 'supplier', 'id' => $payment->supplier_id],
                        'payable_account_id' => $settled[0]['invoice']->payable_account_id,
                        'lines' => $this->settlementLegs($payment, $net, $withholdingByLiability),
                    ],
                ],
                $postingDate,
                $actor,
                $payment->payment_no,
            );

            // §3.3: first settlement withholds the retenue de garantie.
            foreach ($settled as $row) {
                $this->withholdRetention($row['invoice'], $payment, $postingDate, $actor);
            }

            // Advance invoice statuses, then letter full settlements (C10).
            foreach ($settled as $row) {
                $this->advanceInvoice($row['invoice'], $recognition);
            }

            $this->letterFullSettlements($payment, $settled, (int) $entry->getKey(), $recognition, $actor);

            $postedPeriod = $this->periodContaining($postingDate);

            $payment->forceFill([
                'withholding_amount' => $withholdingTotal,
                'net_amount' => $net,
                'status' => SupplierPaymentStatus::Paid,
                'clearing_state' => SupplierPaymentClearingState::Cleared,
                'paid_by' => $actor->id,
                'paid_at' => now(),
                'journal_entry_id' => (int) $entry->getKey(),
                'accounting_period_id' => $postedPeriod['id'],
                'fiscal_year_id' => $postedPeriod['fiscal_year_id'],
                'version' => $payment->version + 1,
            ])->save();

            // §6.6: attestations in the SAME transaction, source = payment.
            if ($recognition === WithholdingRecognition::OnPayment) {
                $this->issueAttestations($payment, $settled, $postingDate, $actor);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: SupplierPayment::class,
                auditableId: (int) $payment->getKey(),
                after: [
                    'status' => 'paid',
                    'journal_entry_id' => (int) $entry->getKey(),
                    'gross_amount' => $gross,
                    'withholding_amount' => $withholdingTotal,
                    'net_amount' => $net,
                    'posting_date' => $postingDate,
                ],
                actor: $actor,
            );

            return $payment->refresh();
        });
    }

    /**
     * The Signed payload legs of the settlement entry: treasury net (Cr),
     * one 447-family credit per liability account, and the school-borne
     * operator fee (Dr 6317 / Cr treasury). The balancing Dr lands on the
     * payable with the supplier partner - configured in the posting rule,
     * never here.
     *
     * @param  array<int, int>  $withholdingByLiability
     * @return list<array{amount: int, expense_account_id: int, label: string}>
     */
    private function settlementLegs(SupplierPayment $payment, int $net, array $withholdingByLiability): array
    {
        $legs = [];

        if ($net > 0) {
            $legs[] = [
                'amount' => -$net,
                'expense_account_id' => $payment->treasury_account_id,
                'label' => 'Règlement fournisseur '.$payment->payment_no,
            ];
        }

        foreach ($withholdingByLiability as $accountId => $amount) {
            if ($amount > 0) {
                $legs[] = [
                    'amount' => -$amount,
                    'expense_account_id' => $accountId,
                    'label' => 'Retenue à la source',
                ];
            }
        }

        if ($payment->fee_amount > 0 && $payment->fee_bearer === SupplierFeeBearer::School) {
            /** @var int $feeAccount */
            $feeAccount = $payment->fee_expense_account_id;

            $legs[] = [
                'amount' => $payment->fee_amount,
                'expense_account_id' => $feeAccount,
                'label' => 'Frais opérateur',
            ];
            $legs[] = [
                'amount' => -$payment->fee_amount,
                'expense_account_id' => $payment->treasury_account_id,
                'label' => 'Frais opérateur',
            ];
        }

        if ($legs === []) {
            throw new DomainException('A payment that moves no money cannot post.');
        }

        return $legs;
    }

    /**
     * Largest-remainder split of this allocation's withholding across the
     * resolved rules, so multi-rule invoices conserve to the franc.
     *
     * @param  array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}>  $byRule
     * @return array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}>
     */
    private function shareByRule(array $byRule, int $fullTotal, int $allocationWithholding): array
    {
        if ($allocationWithholding === 0 || $byRule === []) {
            return [];
        }

        if ($allocationWithholding >= $fullTotal) {
            return $byRule;
        }

        /** @var array<int, int> $amounts */
        $amounts = [];
        $assigned = 0;
        $remainders = [];

        foreach ($byRule as $ruleId => $rule) {
            $exactNumerator = $rule['amount'] * $allocationWithholding;
            $amounts[$ruleId] = intdiv($exactNumerator, $fullTotal);
            $remainders[$ruleId] = $exactNumerator % $fullTotal;
            $assigned += $amounts[$ruleId];
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $ruleId) {
            if ($assigned >= $allocationWithholding) {
                break;
            }

            $amounts[$ruleId] += 1;
            $assigned += 1;
        }

        $shares = [];

        foreach ($byRule as $ruleId => $rule) {
            $amount = $amounts[$ruleId];

            if ($amount <= 0) {
                continue;
            }

            $shares[$ruleId] = [
                'rule_id' => $rule['rule_id'],
                'liability_account_id' => $rule['liability_account_id'],
                'rate_bp' => $rule['rate_bp'],
                'base' => $rule['amount'] > 0 && $amount < $rule['amount']
                    ? intdiv(2 * $rule['base'] * $amount + $rule['amount'], 2 * $rule['amount'])
                    : $rule['base'],
                'amount' => $amount,
            ];
        }

        return $shares;
    }

    /**
     * §3.3: `Dr payable / Cr 4817` at first settlement, through the SAME
     * PostFromEvent door (event `supplier.paid`, negative-total sentinel
     * selecting the school's retention rule).
     */
    private function withholdRetention(SupplierInvoice $invoice, SupplierPayment $payment, string $postingDate, Actor $actor): void
    {
        if ($invoice->retention_amount === 0) {
            return;
        }

        /** @var SupplierRetention|null $existing */
        $existing = SupplierRetention::query()
            ->where('supplier_invoice_id', $invoice->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing !== null && $existing->status !== SupplierRetentionStatus::Cancelled) {
            return; // Already withheld (or released) - nothing to reclass.
        }

        $retentionAccountId = $this->retentionAccountId();

        $entry = $this->post->handle(
            PostingEvent::SupplierPaid->value,
            [
                'document' => [
                    // Negative sentinel: selects the retention-withhold rule
                    // (the amount posted is carried by the leg + balancing).
                    'total' => -$invoice->retention_amount,
                    'reference' => $invoice->internal_no,
                    'partner' => ['type' => 'supplier', 'id' => $invoice->supplier_id],
                    'payable_account_id' => $invoice->payable_account_id,
                    'lines' => [[
                        'amount' => $invoice->retention_amount,
                        'expense_account_id' => $retentionAccountId,
                        'label' => 'Retenue de garantie '.$invoice->internal_no,
                    ]],
                ],
            ],
            $postingDate,
            $actor,
            $invoice->internal_no,
        );

        $releaseDueOn = $invoice->purchase_order_id !== null
            ? DB::table('purchase_orders')->where('id', $invoice->purchase_order_id)->value('retention_release_due_on')
            : null;

        $attributes = [
            'supplier_id' => $invoice->supplier_id,
            'supplier_payment_id' => $payment->getKey(),
            'retention_account_id' => $retentionAccountId,
            'amount' => $invoice->retention_amount,
            'status' => SupplierRetentionStatus::Withheld,
            'release_due_on' => is_string($releaseDueOn) ? $releaseDueOn : null,
            'withheld_journal_entry_id' => (int) $entry->getKey(),
            'released_at' => null,
            'released_by' => null,
            'release_journal_entry_id' => null,
            'created_by' => $actor->id,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return;
        }

        SupplierRetention::query()->create(
            ['supplier_invoice_id' => $invoice->getKey()] + $attributes,
        );
    }

    /**
     * The 4817 retenues de garantie account (seeded SYSCOHADA chart) -
     * refusing loudly when the chart lost it, never guessing another.
     */
    private function retentionAccountId(): int
    {
        $id = DB::table('chart_of_accounts')
            ->where('code', '4817')
            ->where('is_postable', true)
            ->value('id');

        if ($id === null) {
            throw new DomainException(
                'No postable 4817 (retenues de garantie) account exists in the chart; retention cannot be withheld (03-tax-procurement 3.3).'
            );
        }

        return (int) $id;
    }

    private function advanceInvoice(SupplierInvoice $invoice, WithholdingRecognition $recognition): void
    {
        $settleable = $this->settlement->settleableOf($invoice, $recognition);
        $allocated = $this->settlement->allocatedOf((int) $invoice->getKey());

        $invoice->forceFill([
            'status' => $allocated >= $settleable ? SupplierInvoiceStatus::Paid : SupplierInvoiceStatus::PartiallyPaid,
            'version' => $invoice->version + 1,
        ])->save();
    }

    /**
     * C10: when this payment alone fully settles every invoice it touches,
     * letter the payable - the invoice credit(s), the recognition and
     * retention debits, and the payment debit net to zero. Anything short
     * of that stays unlettered and ages on the §4.9 report.
     *
     * @param  list<array{invoice: SupplierInvoice, allocation: SupplierPaymentAllocation, by_rule: array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}>}>  $settled
     */
    private function letterFullSettlements(
        SupplierPayment $payment,
        array $settled,
        int $paymentEntryId,
        WithholdingRecognition $recognition,
        Actor $actor,
    ): void {
        $payableAccountId = $settled[0]['invoice']->payable_account_id;
        $entryIds = [$paymentEntryId];

        foreach ($settled as $row) {
            $invoice = $row['invoice']->refresh();

            if ($invoice->status !== SupplierInvoiceStatus::Paid) {
                return;
            }

            $others = DB::table('supplier_payment_allocations as a')
                ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
                ->where('a.supplier_invoice_id', $invoice->getKey())
                ->whereNull('a.reversed_at')
                ->where('p.status', '<>', 'voided')
                ->where('p.id', '<>', $payment->getKey())
                ->exists();

            if ($others) {
                return; // A prior partial payment shares the position.
            }

            foreach ([$invoice->journal_entry_id, $invoice->secondary_journal_entry_id, $invoice->withholding_journal_entry_id] as $entryId) {
                if ($entryId !== null) {
                    $entryIds[] = $entryId;
                }
            }

            $retentionEntryId = DB::table('supplier_retentions')
                ->where('supplier_invoice_id', $invoice->getKey())
                ->where('status', SupplierRetentionStatus::Withheld->value)
                ->value('withheld_journal_entry_id');

            if ($retentionEntryId !== null) {
                $entryIds[] = (int) $retentionEntryId;
            }
        }

        $lines = DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', array_values(array_unique($entryIds)))
            ->where('account_id', $payableAccountId)
            ->where('partner_type', 'supplier')
            ->where('partner_id', $payment->supplier_id)
            ->whereNull('lettering_id')
            ->get(['id', 'debit', 'credit']);

        $debit = (int) $lines->sum('debit');
        $credit = (int) $lines->sum('credit');

        if ($lines->count() < 2 || $debit !== $credit) {
            return; // Not a clean full group - leave open rather than letter partially.
        }

        /** @var list<int> $lineIds */
        $lineIds = $lines->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $lettering = $this->letter->handle($lineIds, $actor);

        foreach ($settled as $row) {
            $row['allocation']->forceFill([
                'lettering_id' => $lettering->getKey(),
                'letter_code' => $lettering->code,
            ])->save();
        }
    }

    /**
     * §6.6: one attestation per (invoice, rule), snapshotting the share
     * recognised by THIS payment.
     *
     * @param  list<array{invoice: SupplierInvoice, allocation: SupplierPaymentAllocation, by_rule: array<int, array{rule_id: int, liability_account_id: int, rate_bp: int, base: int, amount: int}>}>  $settled
     */
    private function issueAttestations(SupplierPayment $payment, array $settled, string $postingDate, Actor $actor): void
    {
        $period = Carbon::parse($postingDate);

        foreach ($settled as $row) {
            foreach ($row['by_rule'] as $share) {
                if ($share['amount'] <= 0) {
                    continue;
                }

                $this->issueAttestation->handle([
                    'supplier_id' => $payment->supplier_id,
                    'supplier_payment_id' => (int) $payment->getKey(),
                    'withholding_rule_id' => $share['rule_id'],
                    'period_month' => (int) $period->format('n'),
                    'period_year' => (int) $period->format('Y'),
                    'base_amount' => $share['base'],
                    'rate_bp_applied' => $share['rate_bp'],
                    'withheld_amount' => $share['amount'],
                ], $actor);
            }
        }
    }

    /**
     * 02-accounting C4: pay into the payment date's own period while open;
     * forward-post to the first open period otherwise.
     */
    private function postingDateFor(string $paymentDate): string
    {
        $own = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $paymentDate)
            ->whereDate('ends_on', '>=', $paymentDate)
            ->first(['status']);

        if ($own !== null && (string) $own->status === 'open') {
            return $paymentDate;
        }

        $open = DB::table('accounting_periods')
            ->where('status', 'open')
            ->whereDate('ends_on', '>=', $paymentDate)
            ->orderBy('starts_on')
            ->first(['starts_on']);

        if ($open === null) {
            throw new DomainException(
                "The period of {$paymentDate} is closed and no later open period exists to forward-post into (02-accounting C4)."
            );
        }

        $start = Carbon::parse((string) $open->starts_on)->toDateString();

        return max($paymentDate, $start);
    }

    /**
     * @return array{id: int, fiscal_year_id: int}
     */
    private function periodContaining(string $date): array
    {
        $row = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first(['id', 'fiscal_year_id']);

        if ($row === null) {
            throw new DomainException("No accounting period covers {$date}.");
        }

        return ['id' => (int) $row->id, 'fiscal_year_id' => (int) $row->fiscal_year_id];
    }
}
