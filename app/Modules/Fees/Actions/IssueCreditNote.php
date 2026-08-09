<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Domain\CreditNoteReasonType;
use App\Modules\Fees\Domain\CreditNoteSettlementMode;
use App\Modules\Fees\Domain\CreditNoteStatus;
use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Models\CreditNote;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/04-fees.md §9 - C7. Issues a facture d'avoir against an issued
 * invoice, line-level (A2), under the §11.2 lock discipline.
 *
 * Invariant, per line, checked under FOR UPDATE: Σ credit-note amounts
 * ≤ invoiced(ℓ) − Σ adjustments(ℓ) - you cannot credit more than remains
 * billed. (The write-off term joins the formula in its own work package.)
 *
 * Posting goes through PostFromEvent (`fee.credit_note.issued`) - the single
 * posting path. Which contra account the credit hits (4198 for own revenue,
 * the class-47 liability for agent lines) is posting-rule configuration.
 *
 * @phpstan-type CreditLineInput array{invoice_line_id: int, amount: int}
 */
final class IssueCreditNote
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @phpstan-param list<CreditLineInput> $lines
     */
    public function handle(
        int $invoiceId,
        array $lines,
        CreditNoteReasonType $reasonType,
        string $reasonNote,
        CreditNoteSettlementMode $settlementMode,
        string $issueDate,
        Actor $actor,
        ?string $idempotencyKey = null,
    ): CreditNote {
        Gate::authorize(Permission::FeeCollect->value);

        if ($lines === []) {
            throw new DomainException('A credit note needs at least one line.');
        }

        return DB::transaction(function () use (
            $invoiceId, $lines, $reasonType, $reasonNote, $settlementMode, $issueDate, $actor, $idempotencyKey
        ): CreditNote {
            if ($idempotencyKey !== null) {
                /** @var CreditNote|null $existing */
                $existing = CreditNote::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing; // A double-click returns the same avoir.
                }
            }

            // §11.2: deterministic lock on the invoice before any check-then-act.
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status !== InvoiceStatus::Issued) {
                throw new DomainException("Invoice {$invoiceId} is {$invoice->status->value}; a credit note corrects an ISSUED invoice.");
            }

            $total = Money::zero();
            $resolved = [];

            foreach ($lines as $input) {
                /** @var InvoiceLine $line */
                $line = InvoiceLine::query()->lockForUpdate()->findOrFail($input['invoice_line_id']);

                if ($line->invoice_id !== $invoice->id) {
                    throw new DomainException("Invoice line {$line->id} does not belong to invoice {$invoiceId}.");
                }

                $amount = Money::of($input['amount']);

                if ($amount->amount() <= 0) {
                    throw new DomainException('A credit note line amount must be positive.');
                }

                $this->assertCreditable($line, $amount);

                $total = $total->plus($amount);
                $resolved[] = ['line' => $line, 'amount' => $amount];
            }

            $year = Carbon::parse($issueDate)->format('Y');
            $number = $this->sequence->allocate('credit_note_no');
            $creditNoteNo = sprintf('AV/%s/%06d', $year, $number);

            $creditNote = CreditNote::query()->create([
                'credit_note_no' => $creditNoteNo,
                'invoice_id' => $invoice->id,
                'enrollment_id' => $invoice->enrollment_id,
                'student_id' => $invoice->student_id,
                'academic_year_id' => $invoice->academic_year_id,
                'fiscal_year_id' => $invoice->fiscal_year_id,
                'issue_date' => $issueDate,
                'reason_type' => $reasonType,
                'reason_note' => $reasonNote,
                'status' => CreditNoteStatus::Issued,
                'settlement_mode' => $settlementMode,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);

            foreach ($resolved as $item) {
                $creditNote->lines()->create([
                    'invoice_line_id' => $item['line']->id,
                    'description' => $item['line']->description,
                    'amount' => $item['amount']->amount(),
                    'tax_amount' => 0,
                    'revenue_account_id' => $item['line']->revenue_account_id,
                    'collection_basis' => $item['line']->collection_basis,
                ]);
            }

            $entry = $this->post->handle(
                PostingEvent::FeeCreditNoteIssued->value,
                $this->payload($invoice, $creditNoteNo, $resolved, $total),
                Carbon::parse($issueDate)->toDateString(),
                $actor,
                $creditNoteNo,
            );

            $creditNote->forceFill(['journal_entry_id' => (int) $entry->getKey()])->save();

            $this->audit->handle(
                AuditAction::Created,
                'fees',
                CreditNote::class,
                (int) $creditNote->getKey(),
                null,
                ['credit_note_no' => $creditNoteNo, 'invoice_id' => $invoice->id, 'total' => $total->amount()],
                $actor,
            );

            return $creditNote;
        });
    }

    /**
     * §9's invariant under the lock: amount ≤ invoiced(ℓ) − adjustments(ℓ)
     * − credit notes already issued against ℓ.
     */
    private function assertCreditable(InvoiceLine $line, Money $amount): void
    {
        $invoiced = Money::of($line->amount + $line->tax_amount);

        $adjusted = (int) DB::table('fee_adjustments')
            ->where('invoice_line_id', $line->id)
            ->where('status', 'approved')
            ->sum('amount');

        $credited = (int) DB::table('credit_note_lines')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_note_lines.invoice_line_id', $line->id)
            ->where('credit_notes.status', 'issued')
            ->sum('credit_note_lines.amount');

        $remaining = $invoiced->minus(Money::of($adjusted))->minus(Money::of($credited));

        if ($amount->amount() > $remaining->amount()) {
            throw new DomainException(sprintf(
                "Cannot credit %d against line '%s': only %d remains billed (invoiced %d − adjustments %d − credit notes %d). You cannot credit more than remains billed.",
                $amount->amount(),
                $line->description,
                $remaining->amount(),
                $invoiced->amount(),
                $adjusted,
                $credited,
            ));
        }
    }

    /**
     * @param  list<array{line: InvoiceLine, amount: Money}>  $resolved
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice, string $creditNoteNo, array $resolved, Money $total): array
    {
        $receivableAccountId = DB::table('chart_of_accounts')->where('code', '4111')->value('id');

        if ($receivableAccountId === null) {
            throw new DomainException('Account 4111 (Clients) is not in the chart of accounts; the credit note cannot post.');
        }

        $partner = ['type' => 'student', 'id' => $invoice->student_id];
        $lines = [];

        foreach ($resolved as $item) {
            $line = $item['line'];

            $creditSource = $line->collection_basis === InvoiceLine::BASIS_AGENT
                ? (int) DB::table('third_party_funds')->where('id', $line->third_party_fund_id)->value('liability_account_id')
                : (int) $line->revenue_account_id;

            $lines[] = [
                'amount' => $item['amount']->amount(),
                'revenue_account_id' => $creditSource,
                'label' => $line->description,
                'is_agent_collected' => $line->collection_basis === InvoiceLine::BASIS_AGENT,
                'partner' => $partner,
            ];
        }

        return [
            'invoice' => [
                'total' => $total->amount(),
                'reference' => $creditNoteNo,
                'partner' => $partner,
                'receivable_account_id' => (int) $receivableAccountId,
                'lines' => $lines,
            ],
        ];
    }
}
