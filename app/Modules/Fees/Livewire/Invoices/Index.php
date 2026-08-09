<?php

declare(strict_types=1);

namespace App\Modules\Fees\Livewire\Invoices;

use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        ]);
    }
}
