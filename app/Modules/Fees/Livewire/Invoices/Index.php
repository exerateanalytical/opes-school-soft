<?php

declare(strict_types=1);

namespace App\Modules\Fees\Livewire\Invoices;

use App\Modules\Fees\Actions\CreateFeeCategory;
use App\Modules\Fees\Actions\CreateFeeStructure;
use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Actions\IssueCreditNote;
use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\UpdateFeeStructure;
use App\Modules\Fees\Domain\CreditNoteReasonType;
use App\Modules\Fees\Domain\CreditNoteSettlementMode;
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Invoice list at /finance/invoices, gated `fee.view`.
 *
 * Balance is computed, never stored (04-fees A1), so every row's balance is
 * a set of correlated sub-selects over the §5 terms: gross lines, minus
 * non-voided non-bounced payment allocations, approved adjustments and
 * issued credit notes. The whole page is ONE query plus the KPI aggregates,
 * built on the query builder throughout - the Students/Academics joins
 * (student name, class filter, term filter) may not go through another
 * module's Models (ModuleBoundaryTest).
 *
 * "Unpaid" is a derived predicate (§3.1 - paid-ness is deliberately not a
 * status column): an ISSUED invoice whose computed balance is > 0.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public string $term = '';

    #[Url]
    public string $paidness = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Generate invoices form (GenerateInvoices) ──────────────────────
    public bool $showGenerateForm = false;

    public string $generateAcademicYearId = '';

    public string $generateFiscalYearId = '';

    public string $generateTermId = '';

    public string $generateClassGroupId = '';

    public string $generateIssueDate = '';

    public string $generateDueDate = '';

    // ── Issue invoice form (IssueInvoice) ───────────────────────────────
    public bool $showIssueForm = false;

    public string $issueInvoiceId = '';

    // ── Issue credit note form (IssueCreditNote) ────────────────────────
    public bool $showCreditForm = false;

    public string $creditInvoiceId = '';

    public string $creditInvoiceLineId = '';

    public string $creditAmount = '';

    public string $creditReasonType = '';

    public string $creditReasonNote = '';

    public string $creditSettlementMode = '';

    public string $creditIssueDate = '';

    // ── Structures section toggle ───────────────────────────────────────
    public bool $showStructures = false;

    // ── Create fee category (CreateFeeCategory) ─────────────────────────
    public bool $showCategoryForm = false;

    public string $categoryCode = '';

    public string $categoryName = '';

    public string $categoryNameFr = '';

    /**
     * 04-fees §2.1 - `fee_categories.display_order`, the integer every
     * category list in the app sorts by (categoryOptions() below orders on
     * it). It was pinned at 0 by the call site, which made the ordering of a
     * school's own categories unreachable from the screen that creates them.
     * 0 stays the default, so a school that never touches it keeps
     * creation-order lists.
     */
    public string $categoryDisplayOrder = '0';

    // ── Create fee structure (CreateFeeStructure) ───────────────────────
    // Simplified for the demo: a single line item per structure, and the
    // class level / stream / enrollment-status / boarding discriminators
    // stay at "any" - the full multi-line, fully-scoped form is future work.
    public bool $showStructureForm = false;

    public string $structureAcademicYearId = '';

    public string $structureSchoolSectionId = '';

    public string $structureName = '';

    public string $structureEffectiveFrom = '';

    public string $structureFeeItemId = '';

    public string $structureLineAmount = '';

    // ── Edit fee structure (UpdateFeeStructure) ─────────────────────────
    public bool $showEditForm = false;

    public string $editStructureId = '';

    public string $editStructureName = '';

    public string $editStructureEffectiveTo = '';

    public function mount(): void
    {
        Gate::authorize(Permission::FeeView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['status', 'classGroup', 'term', 'paidness']);
        $this->resetPage();
    }

    public function selectPaidness(string $paidness): void
    {
        $this->paidness = in_array($paidness, ['unpaid', 'paid'], true) ? $paidness : '';
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedTerm(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    // ── Generate invoices (GenerateInvoices) ────────────────────────────

    public function toggleGenerateForm(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->showGenerateForm = ! $this->showGenerateForm;
    }

    public function generateInvoices(GenerateInvoices $action): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->validate([
            'generateAcademicYearId' => ['required', 'integer'],
            'generateFiscalYearId' => ['required', 'integer'],
            'generateTermId' => ['nullable', 'integer'],
            'generateClassGroupId' => ['nullable', 'integer'],
            'generateIssueDate' => ['required', 'date'],
            'generateDueDate' => ['required', 'date', 'after_or_equal:generateIssueDate'],
        ], [], [
            'generateAcademicYearId' => 'academic year',
            'generateFiscalYearId' => 'fiscal year',
            'generateIssueDate' => 'issue date',
            'generateDueDate' => 'due date',
        ]);

        $enrollmentIds = $this->enrollmentIdsForGenerate();

        if ($enrollmentIds === []) {
            $this->addError('generateClassGroupId', 'No active enrolments match this academic year / class scope.');

            return;
        }

        $options = [
            'academic_year_id' => (int) $this->generateAcademicYearId,
            'fiscal_year_id' => (int) $this->generateFiscalYearId,
            'term_id' => $this->generateTermId === '' ? null : (int) $this->generateTermId,
            'issue_date' => $this->generateIssueDate,
            'due_date' => $this->generateDueDate,
        ];

        try {
            $result = $action->forEnrollments($enrollmentIds, $options, $this->actor());
        } catch (ValidationException $e) {
            $this->addError('generateIssueDate', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('generateIssueDate', $e->getMessage());

            return;
        }

        $this->reset([
            'showGenerateForm', 'generateAcademicYearId', 'generateFiscalYearId', 'generateTermId',
            'generateClassGroupId', 'generateIssueDate', 'generateDueDate',
        ]);
        $this->resetPage();

        session()->flash('status', sprintf(
            'Invoice run complete: %d created, %d skipped (already invoiced), %d rejected.',
            count($result['created']),
            count($result['skipped']),
            count($result['rejected']),
        ));
    }

    /**
     * @return list<int>
     */
    private function enrollmentIdsForGenerate(): array
    {
        $query = DB::table('enrollments')
            ->where('academic_year_id', (int) $this->generateAcademicYearId)
            ->where('status', 'active');

        if ($this->generateClassGroupId !== '') {
            $classGroupId = (int) $this->generateClassGroupId;
            $query->whereExists(function (QueryBuilder $inner) use ($classGroupId): void {
                $inner->selectRaw('1')
                    ->from('enrollment_segments as seg')
                    ->whereColumn('seg.enrollment_id', 'enrollments.id')
                    ->whereNull('seg.ends_on')
                    ->where('seg.class_group_id', $classGroupId);
            });
        }

        return array_map(static fn (int|string $id): int => (int) $id, $query->pluck('id')->all());
    }

    // ── Issue invoice (IssueInvoice) ─────────────────────────────────────

    public function toggleIssueForm(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->showIssueForm = ! $this->showIssueForm;
    }

    public function issueInvoice(IssueInvoice $action): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->validate([
            'issueInvoiceId' => ['required', 'integer'],
        ], [], [
            'issueInvoiceId' => 'invoice',
        ]);

        try {
            $action->handle([(int) $this->issueInvoiceId], $this->actor());
        } catch (ValidationException $e) {
            $this->addError('issueInvoiceId', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('issueInvoiceId', $e->getMessage());

            return;
        }

        $this->reset(['showIssueForm', 'issueInvoiceId']);
        $this->resetPage();
        session()->flash('status', 'Invoice issued.');
    }

    // ── Issue credit note (IssueCreditNote) ─────────────────────────────

    public function toggleCreditForm(): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->showCreditForm = ! $this->showCreditForm;
    }

    public function updatedCreditInvoiceId(): void
    {
        $this->creditInvoiceLineId = '';
    }

    public function issueCreditNote(IssueCreditNote $action): void
    {
        Gate::authorize(Permission::FeeCollect->value);

        $this->validate([
            'creditInvoiceId' => ['required', 'integer'],
            'creditInvoiceLineId' => ['required', 'integer'],
            'creditAmount' => ['required', 'integer', 'min:1'],
            'creditReasonType' => ['required', 'string'],
            'creditReasonNote' => ['required', 'string', 'min:5'],
            'creditSettlementMode' => ['required', 'string'],
            'creditIssueDate' => ['required', 'date'],
        ], [], [
            'creditInvoiceId' => 'invoice',
            'creditInvoiceLineId' => 'invoice line',
            'creditAmount' => 'amount',
            'creditReasonType' => 'reason',
            'creditReasonNote' => 'reason note',
            'creditSettlementMode' => 'settlement mode',
            'creditIssueDate' => 'issue date',
        ]);

        $reasonType = CreditNoteReasonType::tryFrom($this->creditReasonType);
        $settlementMode = CreditNoteSettlementMode::tryFrom($this->creditSettlementMode);

        if ($reasonType === null) {
            $this->addError('creditReasonType', 'Choose a valid reason.');

            return;
        }

        if ($settlementMode === null) {
            $this->addError('creditSettlementMode', 'Choose a valid settlement mode.');

            return;
        }

        try {
            $action->handle(
                (int) $this->creditInvoiceId,
                [['invoice_line_id' => (int) $this->creditInvoiceLineId, 'amount' => (int) $this->creditAmount]],
                $reasonType,
                $this->creditReasonNote,
                $settlementMode,
                $this->creditIssueDate,
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('creditAmount', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('creditAmount', $e->getMessage());

            return;
        }

        $this->reset([
            'showCreditForm', 'creditInvoiceId', 'creditInvoiceLineId', 'creditAmount',
            'creditReasonType', 'creditReasonNote', 'creditSettlementMode', 'creditIssueDate',
        ]);
        $this->resetPage();
        session()->flash('status', 'Credit note issued.');
    }

    // ── Structures section ──────────────────────────────────────────────

    public function toggleStructures(): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->showStructures = ! $this->showStructures;
    }

    // ── Create fee category (CreateFeeCategory) ─────────────────────────

    public function toggleCategoryForm(): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->showCategoryForm = ! $this->showCategoryForm;
    }

    public function createCategory(CreateFeeCategory $action): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->validate([
            'categoryCode' => ['required', 'string', 'max:30'],
            'categoryName' => ['required', 'string', 'max:160'],
            'categoryNameFr' => ['required', 'string', 'max:160'],
            'categoryDisplayOrder' => ['required', 'integer', 'min:0'],
        ], [], [
            'categoryCode' => 'code',
            'categoryName' => 'name',
            'categoryNameFr' => 'French name',
            'categoryDisplayOrder' => 'display order',
        ]);

        try {
            $action->handle(
                $this->categoryCode,
                $this->categoryName,
                $this->categoryNameFr,
                (int) $this->categoryDisplayOrder,
                $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('categoryCode', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('categoryCode', $e->getMessage());

            return;
        }

        $this->reset(['showCategoryForm', 'categoryCode', 'categoryName', 'categoryNameFr', 'categoryDisplayOrder']);
        session()->flash('status', 'Fee category created.');
    }

    // ── Create fee structure (CreateFeeStructure) ───────────────────────

    public function toggleStructureForm(): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->showStructureForm = ! $this->showStructureForm;
    }

    public function createStructure(CreateFeeStructure $action): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->validate([
            'structureAcademicYearId' => ['required', 'integer'],
            'structureSchoolSectionId' => ['required', 'integer'],
            'structureName' => ['required', 'string', 'max:160'],
            'structureEffectiveFrom' => ['required', 'date'],
            'structureFeeItemId' => ['required', 'integer'],
            'structureLineAmount' => ['required', 'integer', 'min:0'],
        ], [], [
            'structureAcademicYearId' => 'academic year',
            'structureSchoolSectionId' => 'school section',
            'structureName' => 'name',
            'structureEffectiveFrom' => 'effective from',
            'structureFeeItemId' => 'fee item',
            'structureLineAmount' => 'amount',
        ]);

        try {
            $action->handle(
                academicYearId: (int) $this->structureAcademicYearId,
                schoolSectionId: (int) $this->structureSchoolSectionId,
                name: $this->structureName,
                effectiveFrom: $this->structureEffectiveFrom,
                lines: [[
                    'fee_item_id' => (int) $this->structureFeeItemId,
                    'amount' => (int) $this->structureLineAmount,
                ]],
                actor: $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('structureName', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('structureName', $e->getMessage());

            return;
        }

        $this->reset([
            'showStructureForm', 'structureAcademicYearId', 'structureSchoolSectionId',
            'structureName', 'structureEffectiveFrom', 'structureFeeItemId', 'structureLineAmount',
        ]);
        session()->flash('status', 'Fee structure created as a draft.');
    }

    // ── Edit / publish / archive fee structure (UpdateFeeStructure) ─────

    public function toggleEditStructure(int $structureId): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        if ($this->showEditForm && $this->editStructureId === (string) $structureId) {
            $this->reset(['showEditForm', 'editStructureId', 'editStructureName', 'editStructureEffectiveTo']);

            return;
        }

        $row = DB::table('fee_structures')->where('id', $structureId)->first(['id', 'name', 'effective_to']);

        if ($row === null) {
            return;
        }

        /** @var object{id: int|string, name: string, effective_to: string|null} $row */
        $this->editStructureId = (string) $row->id;
        $this->editStructureName = $row->name;
        $this->editStructureEffectiveTo = (string) ($row->effective_to ?? '');
        $this->showEditForm = true;
    }

    public function saveStructureEdit(UpdateFeeStructure $action): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->validate([
            'editStructureId' => ['required', 'integer'],
            'editStructureName' => ['required', 'string', 'max:160'],
        ], [], [
            'editStructureName' => 'name',
        ]);

        try {
            $action->handle(
                feeStructureId: (int) $this->editStructureId,
                name: $this->editStructureName,
                effectiveTo: $this->editStructureEffectiveTo === '' ? null : $this->editStructureEffectiveTo,
                actor: $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('editStructureName', $e->getMessage());

            return;
        }

        $this->reset(['showEditForm', 'editStructureId', 'editStructureName', 'editStructureEffectiveTo']);
        session()->flash('status', 'Fee structure updated.');
    }

    public function publishStructure(int $structureId, UpdateFeeStructure $action): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        try {
            $action->handle(feeStructureId: $structureId, status: FeeStructureStatus::Active, actor: $this->actor());
        } catch (DomainException $e) {
            $this->addError('structures', $e->getMessage());

            return;
        }

        session()->flash('status', 'Fee structure published.');
    }

    public function archiveStructure(int $structureId, UpdateFeeStructure $action): void
    {
        Gate::authorize(Permission::FeeConfigure->value);

        try {
            $action->handle(feeStructureId: $structureId, status: FeeStructureStatus::Archived, actor: $this->actor());
        } catch (DomainException $e) {
            $this->addError('structures', $e->getMessage());

            return;
        }

        session()->flash('status', 'Fee structure archived.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function academicYearOptions(): array
    {
        $rows = DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function fiscalYearOptions(): array
    {
        $rows = DB::table('fiscal_years')->orderByDesc('starts_on')->get(['id', 'code']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->code];
        }

        return $options;
    }

    /**
     * All assessment periods of type "term", regardless of whether an
     * invoice references them yet - unlike termOptions() (the list filter),
     * the generate form must offer a brand-new term.
     *
     * @return list<array{id: int, name: string}>
     */
    private function generateTermOptions(): array
    {
        $rows = DB::table('assessment_periods')
            ->where('type', 'term')
            ->orderBy('starts_on')
            ->get(['id', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * Draft invoices, offered to the "Issue invoice" form.
     *
     * @return list<array{id: int, label: string}>
     */
    private function draftInvoiceOptions(): array
    {
        $rows = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->where('i.status', InvoiceStatus::Draft->value)
            ->orderByDesc('i.id')
            ->limit(200)
            ->get(['i.id', DB::raw("CONCAT(s.first_name, ' ', s.last_name) as student_name"), 's.matricule']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, student_name: string, matricule: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => sprintf('#%d - %s (%s)', (int) $row->id, $row->student_name, $row->matricule)];
        }

        return $options;
    }

    /**
     * Issued invoices, offered to the "Issue credit note" form.
     *
     * @return list<array{id: int, label: string}>
     */
    private function issuedInvoiceOptions(): array
    {
        $rows = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->where('i.status', InvoiceStatus::Issued->value)
            ->orderByDesc('i.id')
            ->limit(200)
            ->get(['i.id', 'i.invoice_no', DB::raw("CONCAT(s.first_name, ' ', s.last_name) as student_name")]);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, invoice_no: string|null, student_name: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => sprintf('%s - %s', (string) ($row->invoice_no ?? ('#'.$row->id)), $row->student_name)];
        }

        return $options;
    }

    /**
     * Lines of the currently selected credit-note invoice - populated only
     * once an invoice is chosen in the form.
     *
     * @return list<array{id: int, label: string}>
     */
    private function creditLineOptions(): array
    {
        if ($this->creditInvoiceId === '') {
            return [];
        }

        $rows = DB::table('invoice_lines')
            ->where('invoice_id', (int) $this->creditInvoiceId)
            ->orderBy('line_no')
            ->get(['id', 'description', 'amount', 'tax_amount']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, description: string, amount: int|string, tax_amount: int|string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => sprintf('%s (%d)', $row->description, (int) $row->amount + (int) $row->tax_amount),
            ];
        }

        return $options;
    }

    /**
     * The §5 balance terms as raw SQL fragments usable both in SELECT and in
     * the derived unpaid predicate. Each correlates on the outer `i` alias.
     *
     * @return literal-string
     */
    private function outstandingSql(): string
    {
        $gross = '(SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id)';

        $allocated = '(SELECT COALESCE(SUM(pa.amount), 0)
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            WHERE pa.invoice_id = i.id
              AND pa.reversed_at IS NULL
              AND p.clearing_state <> \'bounced\'
              AND NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id AND v.status = \'confirmed\'))';

        $adjusted = '(SELECT COALESCE(SUM(fa.amount), 0)
            FROM fee_adjustments fa
            JOIN invoice_lines al ON al.id = fa.invoice_line_id
            WHERE al.invoice_id = i.id AND fa.status = \'approved\')';

        $credited = '(SELECT COALESCE(SUM(cnl.amount + cnl.tax_amount), 0)
            FROM credit_note_lines cnl
            JOIN credit_notes cn ON cn.id = cnl.credit_note_id
            JOIN invoice_lines cl ON cl.id = cnl.invoice_line_id
            WHERE cl.invoice_id = i.id AND cn.status = \'issued\')';

        return "CASE WHEN i.status = 'issued' THEN ".$gross.' - '.$allocated.' - '.$adjusted.' - '.$credited.' ELSE 0 END';
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->leftJoin('assessment_periods as ap', 'ap.id', '=', 'i.term_id');

        if (InvoiceStatus::tryFrom($this->status) !== null) {
            $query->where('i.status', $this->status);
        }

        if ($this->term !== '') {
            $query->where('i.term_id', (int) $this->term);
        }

        if ($this->classGroup !== '') {
            $query->whereExists(function (QueryBuilder $inner): void {
                $inner->selectRaw('1')
                    ->from('enrollment_segments as seg')
                    ->whereColumn('seg.enrollment_id', 'i.enrollment_id')
                    ->whereNull('seg.ends_on')
                    ->where('seg.class_group_id', (int) $this->classGroup);
            });
        }

        if ($this->paidness === 'unpaid') {
            $query->where('i.status', 'issued')->whereRaw('('.$this->outstandingSql().') > 0');
        }

        if ($this->paidness === 'paid') {
            $query->where('i.status', 'issued')->whereRaw('('.$this->outstandingSql().') <= 0');
        }

        return $query;
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, \stdClass>
     */
    private function invoices(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->baseQuery()
            ->select([
                'i.id',
                'i.invoice_no',
                'i.student_id',
                'i.issue_date',
                'i.status',
                DB::raw("CONCAT(s.first_name, ' ', s.last_name) as student_name"),
                's.matricule',
                'ap.name as term_name',
                DB::raw('(SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id) as gross_total'),
                DB::raw($this->outstandingSql().' as outstanding'),
            ])
            ->orderByDesc('i.issue_date')
            ->orderByDesc('i.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    /**
     * Dataset-wide KPI numbers under the SAME status/class/term filters,
     * deliberately ignoring the paidness tab (the tabs read these counts).
     *
     * @return array{total: int, unpaid: int, invoiced: int, outstanding: int}
     */
    private function kpis(): array
    {
        $base = $this->buildKpiBase();
        $total = (clone $base)->count();

        $invoiced = (int) (clone $base)
            ->selectRaw('COALESCE(SUM((SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id)), 0) as agg')
            ->where('i.status', 'issued')
            ->value('agg');

        $outstanding = (int) (clone $base)
            ->selectRaw('COALESCE(SUM('.$this->outstandingSql().'), 0) as agg')
            ->value('agg');

        $unpaid = (clone $base)
            ->where('i.status', 'issued')
            ->whereRaw('('.$this->outstandingSql().') > 0')
            ->count();

        return [
            'total' => $total,
            'unpaid' => $unpaid,
            'invoiced' => $invoiced,
            'outstanding' => $outstanding,
        ];
    }

    /**
     * The KPI base: status/class/term filters, no paidness tab.
     */
    private function buildKpiBase(): QueryBuilder
    {
        $query = DB::table('invoices as i');

        if (InvoiceStatus::tryFrom($this->status) !== null) {
            $query->where('i.status', $this->status);
        }

        if ($this->term !== '') {
            $query->where('i.term_id', (int) $this->term);
        }

        if ($this->classGroup !== '') {
            $query->whereExists(function (QueryBuilder $inner): void {
                $inner->selectRaw('1')
                    ->from('enrollment_segments as seg')
                    ->whereColumn('seg.enrollment_id', 'i.enrollment_id')
                    ->whereNull('seg.ends_on')
                    ->where('seg.class_group_id', (int) $this->classGroup);
            });
        }

        return $query;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function classOptions(): array
    {
        $rows = DB::table('class_groups as cg')
            ->join('academic_years as ay', 'ay.id', '=', 'cg.academic_year_id')
            ->where('ay.is_current', true)
            ->orderBy('cg.name')
            ->get(['cg.id', 'cg.name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * Only terms that at least one invoice actually references - an
     * assessment-period list unrelated to billing would offer filters that
     * can never match.
     *
     * @return list<array{id: int, name: string}>
     */
    private function termOptions(): array
    {
        $rows = DB::table('assessment_periods as ap')
            ->whereIn('ap.id', DB::table('invoices')->whereNotNull('term_id')->select('term_id'))
            ->orderBy('ap.starts_on')
            ->get(['ap.id', 'ap.name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function schoolSectionOptions(): array
    {
        $rows = DB::table('school_sections')->orderBy('display_order')->get(['id', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function feeItemOptions(): array
    {
        $rows = DB::table('fee_items')->where('is_archived', false)->orderBy('name')->get(['id', 'code', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => sprintf('%s - %s', $row->code, $row->name)];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        $rows = DB::table('fee_categories')->where('is_archived', false)->orderBy('display_order')->get(['id', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * Fee structures for the Structures section, newest first.
     *
     * @return list<array{id: int, name: string, section: string, status: string, version: int, effective_from: string, effective_to: string}>
     */
    private function structureRows(): array
    {
        $rows = DB::table('fee_structures as fs')
            ->join('school_sections as ss', 'ss.id', '=', 'fs.school_section_id')
            ->orderByDesc('fs.id')
            ->limit(200)
            ->get(['fs.id', 'fs.name', 'ss.name as section_name', 'fs.status', 'fs.version', 'fs.effective_from', 'fs.effective_to']);

        $structures = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string, section_name: string, status: string, version: int|string, effective_from: string, effective_to: string|null} $row */
            $structures[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'section' => (string) $row->section_name,
                'status' => (string) $row->status,
                'version' => (int) $row->version,
                'effective_from' => (string) $row->effective_from,
                'effective_to' => (string) ($row->effective_to ?? ''),
            ];
        }

        return $structures;
    }

    public function render(): mixed
    {
        $paginator = $this->invoices();

        $rows = $paginator->through(static function (object $row): array {
            /** @var object{id: int|string, invoice_no: string|null, student_id: int|string, issue_date: string, status: string, student_name: string, matricule: string, term_name: string|null, gross_total: int|string, outstanding: int|string} $row */
            return [
                'id' => (int) $row->id,
                'invoice_no' => (string) ($row->invoice_no ?? ''),
                'student_id' => (int) $row->student_id,
                'student_name' => (string) $row->student_name,
                'matricule' => (string) $row->matricule,
                'term' => (string) ($row->term_name ?? ''),
                'date' => (string) $row->issue_date,
                'status' => (string) $row->status,
                'total' => (int) $row->gross_total,
                'outstanding' => (int) $row->outstanding,
            ];
        });

        return view('livewire.fees.invoices.index', [
            'invoices' => $rows,
            'kpis' => $this->kpis(),
            'statusOptions' => array_map(static fn (InvoiceStatus $s): string => $s->value, InvoiceStatus::cases()),
            'classOptions' => $this->classOptions(),
            'termOptions' => $this->termOptions(),
            'academicYearOptions' => $this->academicYearOptions(),
            'fiscalYearOptions' => $this->fiscalYearOptions(),
            'generateTermOptions' => $this->generateTermOptions(),
            'draftInvoiceOptions' => $this->draftInvoiceOptions(),
            'issuedInvoiceOptions' => $this->issuedInvoiceOptions(),
            'creditLineOptions' => $this->creditLineOptions(),
            'creditReasonOptions' => array_map(static fn (CreditNoteReasonType $r): string => $r->value, CreditNoteReasonType::cases()),
            'creditSettlementOptions' => array_map(static fn (CreditNoteSettlementMode $m): string => $m->value, CreditNoteSettlementMode::cases()),
            'canConfigureFees' => Gate::allows(Permission::FeeConfigure->value),
            'schoolSectionOptions' => $this->schoolSectionOptions(),
            'feeItemOptions' => $this->feeItemOptions(),
            'categoryOptions' => $this->categoryOptions(),
            'structureRows' => $this->structureRows(),
        ]);
    }
}
