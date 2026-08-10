<?php

declare(strict_types=1);

namespace App\Modules\Library\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Library Reports at /reports/library (route wired centrally), gated
 * `reports.view`. Four report types, selected via the `report` tab, each
 * with its own filter set and its own on-screen preview (paginated) plus
 * an unpaginated export twin consumed by ExcelExport/PdfExport.
 *
 * Table/column names are read straight from the migrations (never guessed):
 * books, book_copies, book_categories, library_members, library_issues,
 * library_fines.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** catalogue | circulation | overdue | fines. */
    #[Url]
    public string $report = 'catalogue';

    #[Url]
    public string $category = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $memberType = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);
    }

    public function selectReport(string $report): void
    {
        $this->report = in_array($report, ['catalogue', 'circulation', 'overdue', 'fines'], true)
            ? $report
            : 'catalogue';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['category', 'status', 'memberType']);
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

    public function updatedMemberType(): void
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

    public function exportExcel(): StreamedResponse
    {
        [$headers, $rows] = $this->exportData();

        return ExcelExport::download(
            $this->reportTitle(),
            $headers,
            $rows,
            $this->reportSlug().'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        [$headers, $rows] = $this->exportData();

        return PdfExport::download(
            $this->reportTitle(),
            $headers,
            $rows,
            $this->reportSlug().'.pdf',
        );
    }

    private function reportTitle(): string
    {
        return match ($this->report) {
            'circulation' => 'Circulation Register',
            'overdue' => 'Overdue Report',
            'fines' => 'Fines Register',
            default => 'Catalogue',
        };
    }

    private function reportSlug(): string
    {
        return str_replace('_', '-', $this->report);
    }

    /**
     * @return array{0: list<string>, 1: iterable<int, list<mixed>>}
     */
    private function exportData(): array
    {
        return match ($this->report) {
            'circulation' => [$this->circulationHeaders(), $this->circulationExportRows()],
            'overdue' => [$this->overdueHeaders(), $this->overdueExportRows()],
            'fines' => [$this->finesHeaders(), $this->finesExportRows()],
            default => [$this->catalogueHeaders(), $this->catalogueExportRows()],
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->report) {
            'circulation' => $this->circulationQuery()->paginate($this->perPage, page: $this->page),
            'overdue' => $this->overdueQuery()->paginate($this->perPage, page: $this->page),
            'fines' => $this->finesQuery()->paginate($this->perPage, page: $this->page),
            default => $this->catalogueQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    // ── Catalogue ────────────────────────────────────────────────────────

    private function catalogueQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('books as b')
            ->join('book_categories as bc', 'bc.id', '=', 'b.book_category_id')
            ->when($this->category !== '', fn ($q) => $q->where('b.book_category_id', (int) $this->category))
            ->when($this->status !== '', function ($q): void {
                $q->where('b.is_archived', $this->status === 'archived');
            })
            ->orderBy('b.title')
            ->select([
                'b.id', 'b.title', 'b.author', 'bc.name as category_name',
            ])
            ->selectSub(
                DB::table('book_copies')->whereColumn('book_id', 'b.id')->selectRaw('COUNT(*)'),
                'copies_total'
            )
            ->selectSub(
                DB::table('book_copies')->whereColumn('book_id', 'b.id')
                    ->where('status', 'available')->selectRaw('COUNT(*)'),
                'copies_available'
            );
    }

    /**
     * @return list<string>
     */
    private function catalogueHeaders(): array
    {
        return ['Title', 'Author', 'Category', 'Total Copies', 'Available Copies'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function catalogueExportRows(): iterable
    {
        foreach ($this->catalogueQuery()->get() as $row) {
            /** @var object{title: string, author: string, category_name: string, copies_total: int|string, copies_available: int|string} $row */
            yield [
                $row->title,
                $row->author,
                $row->category_name,
                (int) $row->copies_total,
                (int) $row->copies_available,
            ];
        }
    }

    // ── Circulation Register ────────────────────────────────────────────

    private function circulationQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('library_issues as li')
            ->join('book_copies as bcp', 'bcp.id', '=', 'li.book_copy_id')
            ->join('books as b', 'b.id', '=', 'bcp.book_id')
            ->join('library_members as lm', 'lm.id', '=', 'li.library_member_id')
            ->when($this->status !== '', fn ($q) => $q->where('li.status', $this->status))
            ->when($this->memberType !== '', fn ($q) => $q->where('lm.member_type', $this->memberType))
            ->orderByDesc('li.issued_on')
            ->select([
                'li.id', 'li.issue_no', 'li.issued_on', 'li.due_on', 'li.returned_on', 'li.status',
                'b.title as book_title', 'lm.member_no', 'lm.external_name',
            ]);
    }

    /**
     * @return list<string>
     */
    private function circulationHeaders(): array
    {
        return ['Member', 'Book', 'Issued On', 'Due On', 'Returned On', 'Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function circulationExportRows(): iterable
    {
        foreach ($this->circulationQuery()->get() as $row) {
            /** @var object{member_no: string, external_name: string|null, book_title: string, issued_on: string, due_on: string, returned_on: string|null, status: string} $row */
            yield [
                $row->member_no.($row->external_name !== null ? ' - '.$row->external_name : ''),
                $row->book_title,
                $row->issued_on,
                $row->due_on,
                $row->returned_on ?? '',
                ucfirst(str_replace('_', ' ', $row->status)),
            ];
        }
    }

    // ── Overdue Report ───────────────────────────────────────────────────

    private function overdueQuery(): \Illuminate\Database\Query\Builder
    {
        $today = Carbon::today()->toDateString();

        return DB::table('library_issues as li')
            ->join('book_copies as bcp', 'bcp.id', '=', 'li.book_copy_id')
            ->join('books as b', 'b.id', '=', 'bcp.book_id')
            ->join('library_members as lm', 'lm.id', '=', 'li.library_member_id')
            ->whereIn('li.status', ['open', 'overdue'])
            ->where('li.due_on', '<', $today)
            ->when($this->memberType !== '', fn ($q) => $q->where('lm.member_type', $this->memberType))
            ->orderBy('li.due_on')
            ->select([
                'li.id', 'li.due_on', 'b.title as book_title', 'lm.member_no', 'lm.external_name',
            ])
            ->selectRaw('DATEDIFF(?, li.due_on) as days_overdue', [$today]);
    }

    /**
     * @return list<string>
     */
    private function overdueHeaders(): array
    {
        return ['Member', 'Book', 'Days Overdue'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function overdueExportRows(): iterable
    {
        foreach ($this->overdueQuery()->get() as $row) {
            /** @var object{member_no: string, external_name: string|null, book_title: string, days_overdue: int|string} $row */
            yield [
                $row->member_no.($row->external_name !== null ? ' - '.$row->external_name : ''),
                $row->book_title,
                (int) $row->days_overdue,
            ];
        }
    }

    // ── Fines Register ───────────────────────────────────────────────────

    private function finesQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('library_fines as lf')
            ->join('library_members as lm', 'lm.id', '=', 'lf.library_member_id')
            ->leftJoin('library_issues as li', 'li.id', '=', 'lf.library_issue_id')
            ->leftJoin('book_copies as bcp', 'bcp.id', '=', 'li.book_copy_id')
            ->leftJoin('books as b', 'b.id', '=', 'bcp.book_id')
            ->when($this->status !== '', fn ($q) => $q->where('lf.status', $this->status))
            ->when($this->memberType !== '', fn ($q) => $q->where('lm.member_type', $this->memberType))
            ->orderByDesc('lf.assessed_on')
            ->select([
                'lf.id', 'lf.amount', 'lf.waived_amount', 'lf.status',
                'lm.member_no', 'lm.external_name', 'b.title as book_title',
            ]);
    }

    /**
     * @return list<string>
     */
    private function finesHeaders(): array
    {
        return ['Member', 'Book', 'Amount', 'Waived Amount', 'Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function finesExportRows(): iterable
    {
        foreach ($this->finesQuery()->get() as $row) {
            /** @var object{member_no: string, external_name: string|null, book_title: string|null, amount: int|string, waived_amount: int|string, status: string} $row */
            yield [
                $row->member_no.($row->external_name !== null ? ' - '.$row->external_name : ''),
                $row->book_title ?? '',
                (int) $row->amount,
                (int) $row->waived_amount,
                ucfirst(str_replace('_', ' ', $row->status)),
            ];
        }
    }

    // ── Filter option lists ─────────────────────────────────────────────

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
     * Per-report status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->report) {
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
            'catalogue' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'archived', 'label' => 'Archived'],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function memberTypeOptions(): array
    {
        return [
            ['value' => 'student', 'label' => 'Student'],
            ['value' => 'staff', 'label' => 'Staff'],
            ['value' => 'external', 'label' => 'External'],
        ];
    }

    /**
     * @return list<array{value: string, label: string, count: int}>
     */
    private function reportTabs(): array
    {
        return [
            ['value' => 'catalogue', 'label' => 'Catalogue', 'count' => 0],
            ['value' => 'circulation', 'label' => 'Circulation Register', 'count' => 0],
            ['value' => 'overdue', 'label' => 'Overdue Report', 'count' => 0],
            ['value' => 'fines', 'label' => 'Fines Register', 'count' => 0],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.library.reports.index', [
            'rows' => $this->rows(),
            'reportTabs' => $this->reportTabs(),
            'categoryOptions' => $this->categoryOptions(),
            'statusOptions' => $this->statusOptions(),
            'memberTypeOptions' => $this->memberTypeOptions(),
        ]);
    }
}
