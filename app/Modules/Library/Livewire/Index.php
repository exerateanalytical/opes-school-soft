<?php

declare(strict_types=1);

namespace App\Modules\Library\Livewire;

use App\Modules\Library\Actions\AddBookCopies;
use App\Modules\Library\Actions\EnrollLibraryMember;
use App\Modules\Library\Actions\IssueBook;
use App\Modules\Library\Actions\RegisterBook;
use App\Modules\Library\Actions\RenewIssue;
use App\Modules\Library\Actions\ReturnBook;
use App\Modules\Library\Actions\WaiveFine;
use App\Modules\Library\Domain\LibraryPermission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Library catalogue & circulation Index, gated `library.view`: KPI strip
 * (titles / copies on loan / overdue / unpaid fines), filter bar, tabbed
 * table (Books, Members, Circulation, Fines).
 *
 * Cross-module reads (student names) go through DB::table joins only -
 * never another module's Models (ModuleBoundaryTest). One paginated query
 * per render plus the KPI aggregates; no unbounded collection reaches the
 * view (00-core 6.2 rule 8, enforced by x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: books | members | circulation | fines. */
    #[Url]
    public string $tab = 'books';

    #[Url]
    public string $category = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Issue book form ─────────────────────────────────────────────────
    public bool $showIssueForm = false;

    public string $issueBookCopyId = '';

    public string $issueLibraryMemberId = '';

    public string $issuedOn = '';

    // ── Enroll member form ──────────────────────────────────────────────
    public bool $showEnrollForm = false;

    public string $enrollMemberType = 'student';

    public string $enrollMembershipClassId = '';

    public string $enrollAcademicYearId = '';

    public string $enrollEnrollmentId = '';

    public string $enrollStaffMemberId = '';

    public string $enrollExternalName = '';

    public string $enrollExternalContact = '';

    public string $enrollJoinedOn = '';

    public string $enrollExpiresOn = '';

    // ── Register book form ──────────────────────────────────────────────
    public bool $showRegisterBookForm = false;

    public string $registerTitle = '';

    public string $registerAuthor = '';

    public string $registerIsbn = '';

    public string $registerBookCategoryId = '';

    public string $registerReplacementCost = '';

    public bool $registerIsReferenceOnly = false;

    // ── Add book copies form ────────────────────────────────────────────
    public bool $showAddCopiesForm = false;

    public string $addCopiesBookId = '';

    public string $addCopiesShelfLocationId = '';

    public string $addCopiesCount = '1';

    public string $addCopiesUnitCost = '';

    public function mount(): void
    {
        Gate::authorize(LibraryPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['books', 'members', 'circulation', 'fines'], true)
            ? $tab
            : 'books';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['category', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleIssueForm(): void
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        $this->showIssueForm = ! $this->showIssueForm;

        if ($this->showIssueForm && $this->issuedOn === '') {
            $this->issuedOn = Carbon::now()->toDateString();
        }
    }

    public function saveIssue(IssueBook $issueBook): void
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        $this->validate([
            'issueBookCopyId' => ['required', 'integer'],
            'issueLibraryMemberId' => ['required', 'integer'],
            'issuedOn' => ['required', 'date'],
        ], [], [
            'issueBookCopyId' => 'book copy',
            'issueLibraryMemberId' => 'member',
            'issuedOn' => 'issued on',
        ]);

        try {
            $issueBook->handle([
                'book_copy_id' => (int) $this->issueBookCopyId,
                'library_member_id' => (int) $this->issueLibraryMemberId,
                'issued_on' => $this->issuedOn,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('issueBookCopyId', $e->getMessage());

            return;
        }

        $this->reset(['showIssueForm', 'issueBookCopyId', 'issueLibraryMemberId', 'issuedOn']);
        $this->tab = 'circulation';
        $this->resetPage();
        session()->flash('status', 'Book issued.');
    }

    public function toggleEnrollForm(): void
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $this->showEnrollForm = ! $this->showEnrollForm;

        if ($this->showEnrollForm && $this->enrollJoinedOn === '') {
            $this->enrollJoinedOn = Carbon::now()->toDateString();
        }
    }

    public function saveEnroll(EnrollLibraryMember $enroll): void
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $this->validate([
            'enrollMemberType' => ['required', 'in:student,staff,external'],
            'enrollMembershipClassId' => ['required', 'integer'],
            'enrollAcademicYearId' => ['required', 'integer'],
            'enrollJoinedOn' => ['required', 'date'],
            'enrollEnrollmentId' => ['nullable', 'integer'],
            'enrollStaffMemberId' => ['nullable', 'integer'],
            'enrollExternalName' => ['nullable', 'string'],
            'enrollExternalContact' => ['nullable', 'string'],
            'enrollExpiresOn' => ['nullable', 'date'],
        ], [], [
            'enrollMembershipClassId' => 'membership class',
            'enrollAcademicYearId' => 'academic year',
            'enrollJoinedOn' => 'joined on',
        ]);

        $data = [
            'member_type' => $this->enrollMemberType,
            'membership_class_id' => (int) $this->enrollMembershipClassId,
            'academic_year_id' => (int) $this->enrollAcademicYearId,
            'joined_on' => $this->enrollJoinedOn,
            'expires_on' => $this->enrollExpiresOn === '' ? null : $this->enrollExpiresOn,
            'enrollment_id' => $this->enrollEnrollmentId === '' ? null : (int) $this->enrollEnrollmentId,
            'staff_member_id' => $this->enrollStaffMemberId === '' ? null : (int) $this->enrollStaffMemberId,
            'external_name' => $this->enrollExternalName === '' ? null : $this->enrollExternalName,
            'external_contact' => $this->enrollExternalContact === '' ? null : $this->enrollExternalContact,
        ];

        try {
            $enroll->handle($data, $this->actor());
        } catch (DomainException $e) {
            $this->addError('enrollMembershipClassId', $e->getMessage());

            return;
        }

        $this->reset([
            'showEnrollForm', 'enrollMemberType', 'enrollMembershipClassId', 'enrollAcademicYearId',
            'enrollEnrollmentId', 'enrollStaffMemberId', 'enrollExternalName', 'enrollExternalContact',
            'enrollJoinedOn', 'enrollExpiresOn',
        ]);
        $this->tab = 'members';
        $this->resetPage();
        session()->flash('status', 'Member enrolled.');
    }

    public function toggleRegisterBookForm(): void
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $this->showRegisterBookForm = ! $this->showRegisterBookForm;
    }

    public function saveRegisterBook(RegisterBook $registerBook): void
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $this->validate([
            'registerTitle' => ['required', 'string'],
            'registerAuthor' => ['required', 'string'],
            'registerIsbn' => ['nullable', 'string'],
            'registerBookCategoryId' => ['required', 'integer'],
            'registerReplacementCost' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'registerBookCategoryId' => 'category',
            'registerReplacementCost' => 'replacement cost',
        ]);

        try {
            $registerBook->handle(null, [
                'title' => $this->registerTitle,
                'author' => $this->registerAuthor,
                'isbn' => $this->registerIsbn === '' ? null : $this->registerIsbn,
                'book_category_id' => (int) $this->registerBookCategoryId,
                'replacement_cost' => $this->registerReplacementCost === '' ? 0 : (int) $this->registerReplacementCost,
                'is_reference_only' => $this->registerIsReferenceOnly,
            ], $this->actor());
        } catch (DomainException|ValidationException $e) {
            $this->addError('registerTitle', $e->getMessage());

            return;
        }

        $this->reset([
            'showRegisterBookForm', 'registerTitle', 'registerAuthor', 'registerIsbn',
            'registerBookCategoryId', 'registerReplacementCost', 'registerIsReferenceOnly',
        ]);
        $this->tab = 'books';
        $this->resetPage();
        session()->flash('status', 'Book registered.');
    }

    public function toggleAddCopiesForm(): void
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $this->showAddCopiesForm = ! $this->showAddCopiesForm;
    }

    public function saveAddCopies(AddBookCopies $addBookCopies): void
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $this->validate([
            'addCopiesBookId' => ['required', 'integer'],
            'addCopiesShelfLocationId' => ['required', 'integer'],
            'addCopiesCount' => ['required', 'integer', 'min:1'],
            'addCopiesUnitCost' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'addCopiesBookId' => 'book',
            'addCopiesShelfLocationId' => 'shelf location',
            'addCopiesCount' => 'copy count',
        ]);

        try {
            $addBookCopies->handle([
                'book_id' => (int) $this->addCopiesBookId,
                'shelf_location_id' => (int) $this->addCopiesShelfLocationId,
                'count' => (int) $this->addCopiesCount,
                'unit_cost' => $this->addCopiesUnitCost === '' ? 0 : (int) $this->addCopiesUnitCost,
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('addCopiesBookId', $e->getMessage());

            return;
        }

        $this->reset(['showAddCopiesForm', 'addCopiesBookId', 'addCopiesShelfLocationId', 'addCopiesCount', 'addCopiesUnitCost']);
        $this->tab = 'books';
        $this->resetPage();
        session()->flash('status', 'Copies added.');
    }

    public function returnIssue(int $issueId, ReturnBook $returnBook): void
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        try {
            $returnBook->handle([
                'issue_id' => $issueId,
                'returned_on' => Carbon::now()->toDateString(),
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('circulation', $e->getMessage());

            return;
        }

        session()->flash('status', 'Book returned.');
    }

    public function renewIssue(int $issueId, RenewIssue $renewIssue): void
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        try {
            $renewIssue->handle([
                'issue_id' => $issueId,
                'renewed_on' => Carbon::now()->toDateString(),
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('circulation', $e->getMessage());

            return;
        }

        session()->flash('status', 'Loan renewed.');
    }

    public function waiveFine(int $fineId, WaiveFine $waiveFine): void
    {
        Gate::authorize(LibraryPermission::WAIVE_FINE);

        try {
            $waiveFine->handle([
                'fine_id' => $fineId,
                'reason' => 'Waived from the library screen',
            ], $this->actor());
        } catch (DomainException $e) {
            $this->addError('fines', $e->getMessage());

            return;
        }

        session()->flash('status', 'Fine waived.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * Available copies for the issue form, labelled with book title.
     *
     * @return list<array{id: int, label: string}>
     */
    private function availableCopyOptions(): array
    {
        $options = [];

        $rows = DB::table('book_copies as bc')
            ->join('books as b', 'b.id', '=', 'bc.book_id')
            ->where('bc.status', 'available')
            ->orderBy('b.title')
            ->limit(200)
            ->get(['bc.id', 'bc.accession_no', 'b.title']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, accession_no: string, title: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => $row->title.' ('.$row->accession_no.')'];
        }

        return $options;
    }

    /**
     * Active members for the issue form.
     *
     * @return list<array{id: int, label: string}>
     */
    private function activeMemberOptions(): array
    {
        $options = [];

        $rows = DB::table('library_members')
            ->where('status', 'active')
            ->orderBy('member_no')
            ->limit(200)
            ->get(['id', 'member_no', 'external_name']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, member_no: string, external_name: string|null} $row */
            $options[] = ['id' => (int) $row->id, 'label' => $row->member_no.($row->external_name !== null ? ' - '.$row->external_name : '')];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function membershipClassOptions(): array
    {
        $options = [];

        foreach (DB::table('membership_classes')->where('is_archived', false)->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function academicYearOptions(): array
    {
        $options = [];

        foreach (DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function bookOptions(): array
    {
        $options = [];

        foreach (DB::table('books')->where('is_archived', false)->orderBy('title')->limit(200)->get(['id', 'title']) as $row) {
            /** @var object{id: int|string, title: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->title];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function shelfLocationOptions(): array
    {
        $options = [];

        foreach (DB::table('shelf_locations')->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->code.' - '.$row->name];
        }

        return $options;
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'members' => $this->memberRows(),
            'circulation' => $this->circulationRows(),
            'fines' => $this->fineRows(),
            default => $this->bookRows(),
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function bookRows(): LengthAwarePaginator
    {
        return DB::table('books as b')
            ->join('book_categories as bc', 'bc.id', '=', 'b.book_category_id')
            ->when($this->category !== '', fn ($q) => $q->where('b.book_category_id', (int) $this->category))
            ->when($this->status !== '', function ($q): void {
                $q->where('b.is_archived', $this->status === 'archived');
            })
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('b.title', 'like', '%'.$this->search.'%')
                        ->orWhere('b.author', 'like', '%'.$this->search.'%')
                        ->orWhere('b.isbn', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('b.title')
            ->select([
                'b.id', 'b.title', 'b.author', 'b.isbn', 'b.is_archived', 'b.is_reference_only',
                'bc.name as category_name',
            ])
            ->selectSub(
                DB::table('book_copies')->whereColumn('book_id', 'b.id')->selectRaw('COUNT(*)'),
                'copies_total'
            )
            ->selectSub(
                DB::table('book_copies')->whereColumn('book_id', 'b.id')
                    ->where('status', 'available')->selectRaw('COUNT(*)'),
                'copies_available'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function memberRows(): LengthAwarePaginator
    {
        return DB::table('library_members as lm')
            ->join('membership_classes as mc', 'mc.id', '=', 'lm.membership_class_id')
            ->when($this->status !== '', fn ($q) => $q->where('lm.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('lm.member_no', 'like', '%'.$this->search.'%')
                        ->orWhere('lm.external_name', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('lm.member_no')
            ->select([
                'lm.id', 'lm.member_no', 'lm.member_type', 'lm.status', 'lm.joined_on',
                'lm.expires_on', 'lm.external_name', 'mc.name as class_name',
            ])
            ->selectSub(
                DB::table('library_issues')->whereColumn('library_member_id', 'lm.id')
                    ->whereIn('status', ['open', 'overdue'])->selectRaw('COUNT(*)'),
                'active_issues'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function circulationRows(): LengthAwarePaginator
    {
        return DB::table('library_issues as li')
            ->join('book_copies as bc', 'bc.id', '=', 'li.book_copy_id')
            ->join('books as b', 'b.id', '=', 'bc.book_id')
            ->join('library_members as lm', 'lm.id', '=', 'li.library_member_id')
            ->when($this->status !== '', fn ($q) => $q->where('li.status', $this->status))
            ->when($this->status === '', fn ($q) => $q->whereIn('li.status', ['open', 'overdue']))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('b.title', 'like', '%'.$this->search.'%')
                        ->orWhere('lm.member_no', 'like', '%'.$this->search.'%')
                        ->orWhere('li.issue_no', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('li.due_on')
            ->select([
                'li.id', 'li.issue_no', 'li.issued_on', 'li.due_on', 'li.status',
                'li.renewal_count', 'b.title as book_title', 'lm.member_no',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function fineRows(): LengthAwarePaginator
    {
        return DB::table('library_fines as lf')
            ->join('library_members as lm', 'lm.id', '=', 'lf.library_member_id')
            ->when($this->status !== '', fn ($q) => $q->where('lf.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('lf.fine_no', 'like', '%'.$this->search.'%')
                        ->orWhere('lm.member_no', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('lf.assessed_on')
            ->select([
                'lf.id', 'lf.fine_no', 'lf.fine_type', 'lf.assessed_on', 'lf.amount',
                'lf.waived_amount', 'lf.status', 'lm.member_no',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Dataset-wide KPI numbers, never filter-dependent.
     *
     * @return array{titles: int, copies_on_loan: int, overdue: int, unpaid_fines: int}
     */
    private function kpis(): array
    {
        return [
            'titles' => (int) DB::table('books')->where('is_archived', false)->count(),
            'copies_on_loan' => (int) DB::table('book_copies')->where('status', 'issued')->count(),
            'overdue' => (int) DB::table('library_issues')->where('status', 'overdue')->count(),
            'unpaid_fines' => (int) DB::table('library_fines')
                ->whereIn('status', ['assessed', 'invoiced'])
                ->sum(DB::raw('amount - waived_amount')),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        $options = [];

        foreach (DB::table('book_categories')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'members' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'suspended', 'label' => 'Suspended'],
                ['value' => 'expired', 'label' => 'Expired'],
                ['value' => 'closed', 'label' => 'Closed'],
            ],
            'circulation' => [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'overdue', 'label' => 'Overdue'],
                ['value' => 'returned', 'label' => 'Returned'],
                ['value' => 'lost', 'label' => 'Lost'],
                ['value' => 'written_off', 'label' => 'Written off'],
            ],
            'fines' => [
                ['value' => 'assessed', 'label' => 'Assessed'],
                ['value' => 'invoiced', 'label' => 'Invoiced'],
                ['value' => 'paid', 'label' => 'Paid'],
                ['value' => 'waived', 'label' => 'Waived'],
                ['value' => 'written_off', 'label' => 'Written off'],
            ],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'archived', 'label' => 'Archived'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'books' => (int) DB::table('books')->count(),
            'members' => (int) DB::table('library_members')->count(),
            'circulation' => (int) DB::table('library_issues')->whereIn('status', ['open', 'overdue'])->count(),
            'fines' => (int) DB::table('library_fines')->whereIn('status', ['assessed', 'invoiced'])->count(),
        ];

        return view('livewire.library.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'categoryOptions' => $this->categoryOptions(),
            'statusOptions' => $this->statusOptions(),
            'availableCopyOptions' => $this->showIssueForm ? $this->availableCopyOptions() : [],
            'activeMemberOptions' => $this->showIssueForm ? $this->activeMemberOptions() : [],
            'membershipClassOptions' => $this->showEnrollForm ? $this->membershipClassOptions() : [],
            'academicYearOptions' => $this->showEnrollForm ? $this->academicYearOptions() : [],
            'bookOptions' => $this->showAddCopiesForm ? $this->bookOptions() : [],
            'shelfLocationOptions' => $this->showAddCopiesForm ? $this->shelfLocationOptions() : [],
        ]);
    }
}
