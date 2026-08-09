<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Domain\InvoiceType;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/04-fees.md §4.6 - a SUPPLEMENTARY invoice from explicit lines
 * (`fee_structure_id` NULL, exempt from the standard-issue idempotency
 * UNIQUE by construction). This is the Fees-module DOOR other modules call
 * for ad-hoc student debt so it joins the ONE debt stream (§10.7 of
 * 06-assets-stores.md): merchandise credit sales (Inventory §8.5) and
 * student library fines (Library §10.5) both invoice THROUGH here - a
 * parallel receivable would be a review-blocking second debt stream.
 *
 * Issue (numbering + `fee.invoice.issued` posting) is delegated to the
 * real IssueInvoice, so the ledger consequence has exactly one shape.
 * Returns scalars, not models, so callers in other modules never import a
 * Fees Model (ModuleBoundaryTest).
 */
final class CreateSupplementaryInvoice
{
    public function __construct(
        private readonly IssueInvoice $issue,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     enrollment_id: int,
     *     academic_year_id: int,
     *     fiscal_year_id: int,
     *     issue_date: string,
     *     due_date: string,
     *     lines: list<array{description: string, revenue_account_id: int, amount: int, quantity?: int, unit_amount?: int, fee_item_id?: int|null, tax_amount?: int}>,
     *     idempotency_key?: string|null,
     *     issue?: bool,
     *     notes?: string|null,
     * } $data
     * @return array{invoice_id: int, invoice_no: string|null, student_id: int, journal_entry_id: int|null, total: int}
     */
    public function handle(array $data, Actor $actor): array
    {
        Gate::authorize(Permission::FeeCollect->value);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var Invoice|null $existing */
            $existing = Invoice::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $this->result($existing);
            }
        }

        if ($data['lines'] === []) {
            throw new DomainException('A supplementary invoice needs at least one line.');
        }

        foreach ($data['lines'] as $line) {
            if ($line['amount'] <= 0) {
                throw new DomainException(
                    'A supplementary invoice line must carry a positive amount; credits are credit notes (04-fees H).'
                );
            }
        }

        $shouldIssue = $data['issue'] ?? true;

        return DB::transaction(function () use ($data, $actor, $idempotencyKey, $shouldIssue): array {
            /** @var object{id: int|string, student_id: int|string}|null $enrollment */
            $enrollment = DB::table('enrollments')->where('id', $data['enrollment_id'])->first(['id', 'student_id']);

            if ($enrollment === null) {
                throw new DomainException("Enrollment {$data['enrollment_id']} does not exist; nothing to invoice.");
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->create([
                'enrollment_id' => (int) $enrollment->id,
                'student_id' => (int) $enrollment->student_id,
                'academic_year_id' => $data['academic_year_id'],
                'fiscal_year_id' => $data['fiscal_year_id'],
                'fee_structure_id' => null,
                'term_id' => null,
                'type' => InvoiceType::Supplementary,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'status' => InvoiceStatus::Draft,
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $total = Money::zero();

            foreach ($data['lines'] as $index => $line) {
                $amount = Money::of($line['amount']);
                $total = $total->plus($amount);

                $invoice->lines()->create([
                    'line_no' => $index + 1,
                    'fee_item_id' => $line['fee_item_id'] ?? null,
                    'description' => $line['description'],
                    'collection_basis' => 'own_revenue',
                    'revenue_account_id' => $line['revenue_account_id'],
                    'recognition_method' => 'on_issue',
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_amount' => $line['unit_amount'] ?? $line['amount'],
                    'amount' => $amount->amount(),
                    'tax_amount' => $line['tax_amount'] ?? 0,
                ]);
            }

            $this->audit->handle(
                AuditAction::Created,
                'fees',
                Invoice::class,
                (int) $invoice->getKey(),
                null,
                [
                    'type' => 'supplementary',
                    'enrollment_id' => (int) $enrollment->id,
                    'gross' => $total->amount(),
                ],
                $actor,
            );

            if ($shouldIssue) {
                [$invoice] = $this->issue->handle([(int) $invoice->getKey()], $actor);
            }

            return $this->result($invoice);
        });
    }

    /**
     * @return array{invoice_id: int, invoice_no: string|null, student_id: int, journal_entry_id: int|null, total: int}
     */
    private function result(Invoice $invoice): array
    {
        return [
            'invoice_id' => (int) $invoice->getKey(),
            'invoice_no' => $invoice->invoice_no,
            'student_id' => $invoice->student_id,
            'journal_entry_id' => $invoice->journal_entry_id,
            'total' => $invoice->grossTotal(),
        ];
    }
}
