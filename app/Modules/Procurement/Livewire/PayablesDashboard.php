<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire;

use App\Modules\Procurement\Actions\AgedPayables;
use App\Modules\Procurement\Actions\DuplicateRisk;
use App\Modules\Procurement\Actions\OpenCommitments;
use App\Modules\Procurement\Actions\ReceiptNotInvoiced;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the payables dashboard: aged
 * payables, due this week, open commitments, receipt-not-invoiced, match
 * exceptions, duplicate risk. Every figure comes from the §4.9 report
 * Actions - the dashboard invents no second definition of anything.
 */
#[Layout('layouts.app')]
final class PayablesDashboard extends Component
{
    public function mount(): void
    {
        Gate::authorize(ProcurementPermission::VIEW);
    }

    public function render(): mixed
    {
        $aged = app(AgedPayables::class)->handle();
        $commitments = app(OpenCommitments::class)->handle();
        $receiptNotInvoiced = app(ReceiptNotInvoiced::class)->handle();
        $duplicates = app(DuplicateRisk::class)->handle();

        $agedTotals = [
            'current' => 0, 'days_1_30' => 0, 'days_31_60' => 0,
            'days_61_90' => 0, 'days_90_plus' => 0, 'total' => 0,
        ];

        foreach ($aged['rows'] as $row) {
            $agedTotals['current'] += $row->current;
            $agedTotals['days_1_30'] += $row->days_1_30;
            $agedTotals['days_31_60'] += $row->days_31_60;
            $agedTotals['days_61_90'] += $row->days_61_90;
            $agedTotals['days_90_plus'] += $row->days_90_plus;
            $agedTotals['total'] += $row->total;
        }

        $today = BusinessDate::today();
        $weekEnd = Carbon::parse($today)->addDays(7)->toDateString();

        $dueThisWeek = DB::table('supplier_invoices')
            ->whereIn('status', ['posted', 'partially_paid'])
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $weekEnd)
            ->selectRaw('COUNT(*) as invoice_count, CAST(COALESCE(SUM(net_payable), 0) AS SIGNED) as total')
            ->first();

        $matchExceptions = DB::table('supplier_invoices')->where('status', 'match_exception')->count();

        return view('livewire.procurement.payables-dashboard', [
            'aged' => $aged,
            'agedTotals' => $agedTotals,
            'commitments' => $commitments,
            'receiptNotInvoiced' => $receiptNotInvoiced,
            'duplicates' => $duplicates,
            'dueThisWeekCount' => (int) ($dueThisWeek->invoice_count ?? 0),
            'dueThisWeekTotal' => (int) ($dueThisWeek->total ?? 0),
            'matchExceptions' => $matchExceptions,
        ]);
    }
}
