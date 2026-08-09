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
use App\Support\Money\Allocator;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/04-fees.md §4.1 - the batch invoice run, producing DRAFT
 * invoices (issue is a separate, posting-bearing step: IssueInvoice).
 *
 * Batch signature is mandatory (00-core §6.2 rule 5); the UI calls this with
 * one id. Unresolvable or ambiguous fee structures are collected into
 * `rejected`, never thrown - a mis-scoped batch must report, not abort.
 *
 * Structure resolution (§2.5): most specific match wins, specificity scored
 * class_level(8) + stream(4) + boarding(2) + enrollment_status(1) over
 * sentinel-0 / `any` discriminators; a tie is a configuration error and
 * rejects the enrollment.
 *
 * Instalments (§2.6): percentage plans run through Money::allocate and the
 * LAST tranche absorbs the residual (§15.2) - ratios are reversed on the way
 * in and the parts reversed back out, so the Allocator's earlier-index tie
 * break lands the spare francs on the last tranches.
 *
 * Cross-module reads (enrollments, fee reference data) go through the query
 * builder, never another module's Models (00-core §6.2 rule 2).
 *
 * @phpstan-type StructureRow object{id: int|string, class_level_id: int|string, stream_id: int|string, boarding_scope: string, enrollment_status_scope: string}
 * @phpstan-type RunOptions array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string, installment_plan_id?: int|null}
 * @phpstan-type RunResult array{created: list<int>, skipped: list<int>, rejected: array<int, string>}
 */
final class GenerateInvoices
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  list<int>  $enrollmentIds
     *
     * @phpstan-param RunOptions $options
     *
     * @phpstan-return RunResult
     */
    public function forEnrollments(array $enrollmentIds, array $options, Actor $actor): array
    {
        Gate::authorize(Permission::FeeCollect->value);

        $result = ['created' => [], 'skipped' => [], 'rejected' => []];

        foreach (array_chunk($enrollmentIds, 200) as $chunk) {
            DB::transaction(function () use ($chunk, $options, $actor, &$result): void {
                foreach ($chunk as $enrollmentId) {
                    $this->generateOne($enrollmentId, $options, $actor, $result);
                }
            });
        }

        return $result;
    }

    /**
     * @phpstan-param RunOptions $options
     *
     * @param-out array{created: list<int>, skipped: list<int>, rejected: array<int, string>} $result
     *
     * @phpstan-param RunResult $result
     */
    private function generateOne(int $enrollmentId, array $options, Actor $actor, array &$result): void
    {
        /** @var object{student_id: int|string, boarding_status: string, is_repeat: int|bool, enrollment_type: string, school_section_id: int|string, class_level_id: int|string, stream_id: int|string|null}|null $enrollment */
        $enrollment = DB::table('enrollments')->where('id', $enrollmentId)->first();

        if ($enrollment === null) {
            $result['rejected'][$enrollmentId] = 'Enrollment not found.';

            return;
        }

        $structureId = $this->resolveStructure($enrollment, $options['academic_year_id'], $error);

        if ($structureId === null) {
            $result['rejected'][$enrollmentId] = $error ?? 'No fee structure matched.';

            return;
        }

        // Issue idempotency backstop read (§4.1 step 2 / 00-core §10.3): the
        // generated-column UNIQUE (which binds ISSUED rows) is the hard
        // guarantee; this locked pre-check is what guards drafts and turns
        // the duplicate into a reported skip instead of a raised violation.
        // A CANCELLED invoice does not block regeneration.
        $existing = DB::table('invoices')
            ->where('enrollment_id', $enrollmentId)
            ->where('type', InvoiceType::Standard->value)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->whereRaw('COALESCE(fee_structure_id, 0) = ?', [$structureId])
            ->whereRaw('COALESCE(term_id, 0) = ?', [$options['term_id'] ?? 0])
            ->lockForUpdate()
            ->value('id');

        if ($existing !== null) {
            $result['skipped'][] = $enrollmentId;

            return;
        }

        $lines = $this->structureLines($structureId, $options['term_id']);

        if ($lines === []) {
            $result['rejected'][$enrollmentId] = 'The resolved fee structure has no billable lines for this scope.';

            return;
        }

        /** @var object{version: int} $structure */
        $structure = DB::table('fee_structures')->where('id', $structureId)->first();

        $invoice = Invoice::query()->create([
            'enrollment_id' => $enrollmentId,
            'student_id' => (int) $enrollment->student_id,
            'academic_year_id' => $options['academic_year_id'],
            'fiscal_year_id' => $options['fiscal_year_id'],
            'fee_structure_id' => $structureId,
            'fee_structure_version' => (int) $structure->version,
            'term_id' => $options['term_id'],
            'installment_plan_id' => $options['installment_plan_id'] ?? null,
            'type' => InvoiceType::Standard,
            'issue_date' => $options['issue_date'],
            'due_date' => $options['due_date'],
            'status' => InvoiceStatus::Draft,
            'created_by' => $actor->id,
        ]);

        $gross = Money::zero();

        foreach ($lines as $index => $line) {
            $amount = Money::of((int) $line->amount);
            $gross = $gross->plus($amount);

            $invoice->lines()->create([
                'line_no' => $index + 1,
                'fee_item_id' => (int) $line->fee_item_id,
                'description' => (string) $line->item_name,
                'description_fr' => $line->item_name_fr !== null ? (string) $line->item_name_fr : null,
                'fee_category_code' => $line->category_code !== null ? (string) $line->category_code : null,
                'collection_basis' => (string) $line->collection_basis,
                'third_party_fund_id' => $line->third_party_fund_id !== null ? (int) $line->third_party_fund_id : null,
                'revenue_account_id' => $line->revenue_account_id !== null ? (int) $line->revenue_account_id : null,
                'recognition_method' => (string) $line->recognition_method,
                'tax_code_id' => $line->tax_code_id !== null ? (int) $line->tax_code_id : null,
                'quantity' => 1,
                'unit_amount' => $amount->amount(),
                'amount' => $amount->amount(),
                'tax_amount' => 0,
                'service_period_start' => $line->service_period_start,
                'service_period_end' => $line->service_period_end,
            ]);
        }

        $this->buildInstallments($invoice, $gross, $options);

        $this->audit->handle(
            AuditAction::Created,
            'fees',
            Invoice::class,
            (int) $invoice->getKey(),
            null,
            ['enrollment_id' => $enrollmentId, 'gross' => $gross->amount(), 'status' => 'draft'],
            $actor,
        );

        $result['created'][] = (int) $invoice->getKey();
    }

    /**
     * §2.5 most-specific-match resolution over sentinel-0 discriminators.
     *
     * @param  object{boarding_status: string, is_repeat: int|bool, enrollment_type: string, school_section_id: int|string, class_level_id: int|string, stream_id: int|string|null}  $enrollment
     */
    private function resolveStructure(object $enrollment, int $academicYearId, ?string &$error = null): ?int
    {
        $boarding = ((string) $enrollment->boarding_status) === 'boarder' ? 'boarding' : 'day';
        $status = ((bool) $enrollment->is_repeat)
            ? 'repeating'
            : ((in_array((string) $enrollment->enrollment_type, ['new', 'transfer_in'], true)) ? 'new' : 'returning');

        /** @var list<StructureRow> $candidates */
        $candidates = DB::table('fee_structures')
            ->where('academic_year_id', $academicYearId)
            ->where('school_section_id', (int) $enrollment->school_section_id)
            ->where('status', 'active')
            ->whereIn('class_level_id', [0, (int) $enrollment->class_level_id])
            ->whereIn('stream_id', [0, (int) ($enrollment->stream_id ?? 0)])
            ->whereIn('boarding_scope', ['any', $boarding])
            ->whereIn('enrollment_status_scope', ['any', $status])
            ->get()
            ->all();

        if ($candidates === []) {
            $error = 'No active fee structure covers this enrollment.';

            return null;
        }

        usort($candidates, fn ($a, $b): int => $this->specificity($b) <=> $this->specificity($a));

        if (count($candidates) > 1 && $this->specificity($candidates[0]) === $this->specificity($candidates[1])) {
            $error = 'Ambiguous fee structures: two structures tie at the top specificity. Fix the configuration.';

            return null;
        }

        return (int) $candidates[0]->id;
    }

    /**
     * §2.5 specificity score: class_level(8) + stream(4) + boarding(2)
     * + enrollment_status(1) over sentinel-0 / `any` discriminators.
     *
     * @param  StructureRow  $structure
     */
    private function specificity(object $structure): int
    {
        return (((int) $structure->class_level_id) !== 0 ? 8 : 0)
            + (((int) $structure->stream_id) !== 0 ? 4 : 0)
            + ($structure->boarding_scope !== 'any' ? 2 : 0)
            + ($structure->enrollment_status_scope !== 'any' ? 1 : 0);
    }

    /**
     * @return list<object{fee_item_id: int|string, amount: int|string, service_period_start: string|null, service_period_end: string|null, item_name: string, item_name_fr: string|null, category_code: string|null, collection_basis: string, third_party_fund_id: int|string|null, revenue_account_id: int|string|null, recognition_method: string, tax_code_id: int|string|null}>
     */
    private function structureLines(int $structureId, ?int $termId): array
    {
        /** @var list<object{fee_item_id: int|string, amount: int|string, service_period_start: string|null, service_period_end: string|null, item_name: string, item_name_fr: string|null, category_code: string|null, collection_basis: string, third_party_fund_id: int|string|null, revenue_account_id: int|string|null, recognition_method: string, tax_code_id: int|string|null}> $rows */
        $rows = DB::table('fee_structure_lines')
            ->join('fee_items', 'fee_items.id', '=', 'fee_structure_lines.fee_item_id')
            ->leftJoin('fee_categories', 'fee_categories.id', '=', 'fee_items.fee_category_id')
            ->where('fee_structure_lines.fee_structure_id', $structureId)
            ->where('fee_structure_lines.is_optional', false)
            // F-config stores "annual" as sentinel 0, not NULL (§2.5's
            // MySQL-NULL-uniqueness rationale applies to lines too).
            ->where('fee_structure_lines.term_id', $termId ?? 0)
            ->orderBy('fee_structure_lines.display_order')
            ->select([
                'fee_structure_lines.fee_item_id',
                'fee_structure_lines.amount',
                'fee_structure_lines.service_period_start',
                'fee_structure_lines.service_period_end',
                'fee_items.name as item_name',
                'fee_items.name_fr as item_name_fr',
                'fee_categories.code as category_code',
                'fee_items.collection_basis',
                'fee_items.third_party_fund_id',
                'fee_items.revenue_account_id',
                'fee_items.recognition_method',
                'fee_items.tax_code_id',
            ])
            ->get()
            ->all();

        return $rows;
    }

    /**
     * @phpstan-param array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string, installment_plan_id?: int|null} $options
     */
    private function buildInstallments(Invoice $invoice, Money $gross, array $options): void
    {
        $planId = $options['installment_plan_id'] ?? null;

        if ($planId === null) {
            $invoice->installments()->create([
                'sequence_no' => 1,
                'label' => 'Full amount',
                'label_fr' => 'Montant total',
                'amount' => $gross->amount(),
                'due_date' => $options['due_date'],
            ]);

            return;
        }

        /** @var object{basis: string}|null $plan */
        $plan = DB::table('installment_plans')->where('id', $planId)->first();

        if ($plan === null) {
            throw new DomainException("Installment plan {$planId} does not exist.");
        }

        /** @var list<object{sequence_no: int|string, label: string, label_fr: string|null, percentage_bp: int|string|null, fixed_amount: int|string|null, due_date: string|null}> $planLines */
        $planLines = DB::table('installment_plan_lines')
            ->where('installment_plan_id', $planId)
            ->orderBy('sequence_no')
            ->get()
            ->all();

        if ($planLines === []) {
            throw new DomainException("Installment plan {$planId} has no lines.");
        }

        if ($plan->basis === 'percentage') {
            $ratios = array_map(static fn (object $line): int => (int) $line->percentage_bp, $planLines);
            // Reverse in, reverse out: the Allocator breaks remainder ties on
            // the EARLIER index; the spec wants the LAST tranche to absorb the
            // residual (§15.2), which is the same distribution mirrored.
            $parts = array_reverse(Allocator::allocate($gross, array_reverse($ratios)));
        } else {
            $parts = array_map(static fn (object $line): Money => Money::of((int) $line->fixed_amount), $planLines);
            $sum = Money::sum($parts);

            if ($sum->amount() !== $gross->amount()) {
                // §2.6: fixed plans are validated against the invoice total at
                // APPLICATION time, not at save.
                throw new DomainException(sprintf(
                    'Fixed installment plan totals %d but the invoice gross is %d; the plan does not fit this invoice.',
                    $sum->amount(),
                    $gross->amount(),
                ));
            }
        }

        foreach ($planLines as $index => $line) {
            $invoice->installments()->create([
                'sequence_no' => (int) $line->sequence_no,
                'label' => $line->label,
                'label_fr' => $line->label_fr,
                'amount' => $parts[$index]->amount(),
                'due_date' => $line->due_date ?? $options['due_date'],
            ]);
        }
    }
}
