<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Fees\Domain\InvoiceStatus;
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
 * docs/specs/04-fees.md §4.1 steps 6-8: flip a DRAFT invoice to ISSUED.
 *
 * - `invoice_no` comes from the row-locked sequence INSIDE the issuing
 *   transaction (00-core §12, gaps permitted, global scope, INV/{YYYY}/{######}).
 * - The ledger consequence goes through Accounting's PostFromEvent - the ONE
 *   posting path; Fees never writes journal entries itself. No posting rules
 *   ship seeded (00-core §16): if the accountant has not configured a rule
 *   for `fee.invoice.issued`, PostFromEvent FAILS LOUDLY, the transaction
 *   rolls back, and the invoice REMAINS DRAFT - an invoice may not claim
 *   `issued` (= "Posted", §3.1) without its journal entry.
 * - Recognition basis rides on each line's snapshotted `recognition_method`
 *   and `is_agent_collected` in the payload; which accounts each variant
 *   credits (7xxx, 4191, 47xx) is the accountant's posting-rule
 *   configuration, never a code default.
 * - Migrated invoices (`is_migration`) do not re-trigger posting rules.
 *
 * Idempotent: an already-issued invoice is returned as-is.
 */
final class IssueInvoice
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  list<int>  $invoiceIds
     * @return list<Invoice>
     */
    public function handle(array $invoiceIds, Actor $actor): array
    {
        Gate::authorize(Permission::FeeCollect->value);

        $issued = [];

        foreach ($invoiceIds as $invoiceId) {
            $issued[] = DB::transaction(fn (): Invoice => $this->issueOne($invoiceId, $actor));
        }

        return $issued;
    }

    private function issueOne(int $invoiceId, Actor $actor): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

        if ($invoice->status === InvoiceStatus::Issued) {
            return $invoice; // Idempotent - a retry returns the same invoice.
        }

        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new DomainException("Invoice {$invoiceId} is {$invoice->status->value}; only a draft can be issued.");
        }

        /** @var list<InvoiceLine> $lines */
        $lines = $invoice->lines()->get()->all();

        if ($lines === []) {
            throw new DomainException("Invoice {$invoiceId} has no lines; an empty invoice cannot be issued.");
        }

        $year = Carbon::parse($invoice->issue_date)->format('Y');
        $number = $this->sequence->allocate('invoice_no');
        $invoiceNo = sprintf('INV/%s/%06d', $year, $number);

        $invoice->forceFill([
            'invoice_no' => $invoiceNo,
            'status' => InvoiceStatus::Issued,
            'version' => $invoice->version + 1,
        ])->save();

        if (! $invoice->is_migration) {
            $entry = $this->post->handle(
                PostingEvent::FeeInvoiceIssued->value,
                $this->payload($invoice, $lines, $invoiceNo),
                Carbon::parse($invoice->issue_date)->toDateString(),
                $actor,
                $invoiceNo,
            );

            $invoice->forceFill(['journal_entry_id' => (int) $entry->getKey()])->save();
        }

        $this->audit->handle(
            AuditAction::Updated,
            'fees',
            Invoice::class,
            (int) $invoice->getKey(),
            ['status' => 'draft'],
            ['status' => 'issued', 'invoice_no' => $invoiceNo],
            $actor,
        );

        return $invoice;
    }

    /**
     * The §11.2-catalogue payload for `fee.invoice.issued`.
     *
     * @param  list<InvoiceLine>  $payloadLines
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice, array $payloadLines, string $invoiceNo): array
    {
        $receivableAccountId = DB::table('chart_of_accounts')->where('code', '4111')->value('id');

        if ($receivableAccountId === null) {
            throw new DomainException('Account 4111 (Clients) is not in the chart of accounts; invoicing cannot post.');
        }

        $partner = ['type' => 'student', 'id' => $invoice->student_id];
        $lines = [];
        $total = Money::zero();

        foreach ($payloadLines as $line) {
            $amount = Money::of($line->amount + $line->tax_amount);
            $total = $total->plus($amount);

            $lines[] = [
                'amount' => $amount->amount(),
                'revenue_account_id' => $this->creditAccountId($line),
                'label' => $line->description,
                'is_agent_collected' => $line->collection_basis === InvoiceLine::BASIS_AGENT,
                'partner' => $partner,
            ];
        }

        return [
            'invoice' => [
                'total' => $total->amount(),
                'reference' => $invoiceNo,
                'partner' => $partner,
                'receivable_account_id' => (int) $receivableAccountId,
                'lines' => $lines,
            ],
        ];
    }

    /**
     * The account each line's credit side resolves FROM (the posting rule
     * decides what to do with it): own revenue → the snapshotted revenue
     * account; agent-collected (C5) → the fund's class-47 liability account.
     * Both are NEEDS-VERIFICATION accountant configuration and BLOCK when
     * absent (00-core §16) - never default.
     */
    private function creditAccountId(InvoiceLine $line): int
    {
        if ($line->collection_basis === InvoiceLine::BASIS_AGENT) {
            $accountId = $line->third_party_fund_id === null
                ? null
                : DB::table('third_party_funds')->where('id', $line->third_party_fund_id)->value('liability_account_id');

            if ($accountId === null) {
                throw new DomainException(
                    "Line '{$line->description}' is agent-collected but its third-party fund has no configured class-47 liability account; the accountant must configure it before issuing (00-core §16)."
                );
            }

            return (int) $accountId;
        }

        if ($line->revenue_account_id === null) {
            throw new DomainException(
                "Line '{$line->description}' has no snapshotted revenue account; the fee item is not fully configured."
            );
        }

        return $line->revenue_account_id;
    }
}
