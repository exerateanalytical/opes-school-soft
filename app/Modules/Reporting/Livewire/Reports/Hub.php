<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The Reports hub at /reports: a categorised catalogue of every report
 * screen in the system (docs: Reports module, 2026-08 build). Each category
 * links to its own dedicated report screen (built by module-cluster teams),
 * which owns its own filters, preview table, and Excel/PDF/Print export
 * actions - this hub is a directory, not a report renderer itself.
 *
 * Categories are static: a report screen is only linked here once it has
 * actually shipped, so this list can never dangle.
 */
#[Layout('layouts.app')]
final class Hub extends Component
{
    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView);
    }

    /**
     * @return list<array{category: string, description: string, route: string, icon: string}>
     */
    public function categories(): array
    {
        return array_filter([
            [
                'category' => 'Academic Reports',
                'description' => 'Class lists, subject allocations, timetables, promotion register.',
                'route' => 'reports.academic',
                'icon' => 'academics',
            ],
            [
                'category' => 'Assessment Reports',
                'description' => 'Mark sheets, results register, class statistics, report cards.',
                'route' => 'reports.assessment',
                'icon' => 'results',
            ],
            [
                'category' => 'Financial Reports',
                'description' => 'Trial balance, general ledger, journal register, account statements.',
                'route' => 'reports.financial',
                'icon' => 'ledger',
            ],
            [
                'category' => 'Fees Reports',
                'description' => 'Collection summary, outstanding balances, invoice register.',
                'route' => 'reports.fees',
                'icon' => 'finance',
            ],
            [
                'category' => 'HR & Payroll Reports',
                'description' => 'Staff register, payslips, leave register, contract register.',
                'route' => 'reports.hr',
                'icon' => 'payroll',
            ],
            [
                'category' => 'Procurement Reports',
                'description' => 'Supplier register, purchase order register, payables aging.',
                'route' => 'reports.procurement',
                'icon' => 'procurement',
            ],
            [
                'category' => 'Assets & Inventory Reports',
                'description' => 'Asset register, depreciation schedule, stock valuation, stock movements.',
                'route' => 'reports.assets-inventory',
                'icon' => 'assets',
            ],
            [
                'category' => 'Library Reports',
                'description' => 'Catalogue, circulation register, overdue and fines report.',
                'route' => 'reports.library',
                'icon' => 'library',
            ],
            [
                'category' => 'Welfare Reports',
                'description' => 'Transport roster, hostel occupancy, medical log, discipline register, insurance register.',
                'route' => 'reports.welfare',
                'icon' => 'hostel',
            ],
            [
                'category' => 'Tax Reports',
                'description' => 'Declarations register, withholding register, VAT summary.',
                'route' => 'reports.tax',
                'icon' => 'tax',
            ],
            [
                'category' => 'Students & Guardians Reports',
                'description' => 'Student register, guardian directory, admission register, attendance summary.',
                'route' => 'reports.students-guardians',
                'icon' => 'students',
            ],
        ], static fn (array $item): bool => \Illuminate\Support\Facades\Route::has($item['route']));
    }

    /**
     * The headline figures the reference's Reports screen carries.
     *
     * Every one is permission-gated and returns null - not zero - for a
     * reader who may not see it, so a reports viewer without students.view
     * gets four tiles rather than a lie about a roll of nobody.
     *
     * "Pass rate" is DELIBERATELY ABSENT. The reference shows it, and this
     * product cannot compute it honestly yet: a pass mark is per assessment
     * framework and no published results exist to average. A figure invented
     * for a tile on the reports screen would be the worst possible place to
     * start guessing.
     *
     * @return array<string, int|null>
     */
    private function headlineFigures(): array
    {
        $guard = static function (Permission $permission, callable $read): ?int {
            if (! Gate::allows($permission->value)) {
                return null;
            }

            try {
                return (int) $read();
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'students' => $guard(Permission::StudentsView, static fn (): int => (int) DB::table('students')
                ->where('is_archived', false)
                ->whereNull('left_on')
                ->count()),

            'staff' => $guard(Permission::StaffView, static fn (): int => (int) DB::table('staff_members')
                ->where('status', 'active')
                ->count()),

            'classes' => $guard(Permission::AcademicsView, static fn (): int => (int) DB::table('class_groups')->count()),

            'examinations' => $guard(Permission::AcademicsView, static fn (): int => (int) DB::table('exams')->count()),
        ];
    }

    public function render(): mixed
    {
        return view('livewire.reporting.reports.hub', [
            'categories' => $this->categories(),
            'headlineFigures' => $this->headlineFigures(),
        ]);
    }
}
