<?php

declare(strict_types=1);

namespace App\Modules\HR\Livewire\Portal;

use App\Modules\HR\Support\StaffPortalContext;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/portal/staff` - the staff portal shell (docs/plans/phase-12-13.md 12.3).
 *
 * Profile and password change, own timetable, own leave balance/history and
 * own payslip PDF downloads. Every read is scoped through
 * `StaffPortalContext::current()->staffMemberId` (never trusts a
 * user-supplied id) - the same "read your own row only" boundary the
 * password-change action already relied on. All reads go through
 * `DB::table()`, not Academics/Payroll Models, matching the pattern already
 * used by `Academics\Livewire\Timetable\Index` and `HR\Livewire\Reports\Index`
 * (ModuleBoundaryTest).
 */
#[Layout('layouts.portal')]
final class Show extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /**
     * Self-service only: the authenticated user changing their OWN
     * password needs no staff permission - it is not `SetUserPassword`
     * (Identity\Actions), which is the ADMIN-resets-SOMEONE-ELSE's-password
     * door and rightly gates on `user.set_password`. Written via the query
     * builder (not the `User` model) for the same cross-module reason
     * StaffPortalContext gives.
     */
    public function changePassword(): void
    {
        $userId = auth()->id();

        if ($userId === null) {
            abort(403);
        }

        $hash = DB::table('users')->where('id', $userId)->value('password');

        if (! is_string($hash) || ! Hash::check($this->currentPassword, $hash)) {
            throw ValidationException::withMessages([
                'currentPassword' => __('opes.staff_portal.password_current_wrong'),
            ]);
        }

        if ($this->newPassword === '' || $this->newPassword !== $this->newPasswordConfirmation) {
            throw ValidationException::withMessages([
                'newPasswordConfirmation' => __('opes.staff_portal.password_mismatch'),
            ]);
        }

        if (strlen($this->newPassword) < 8) {
            throw ValidationException::withMessages([
                'newPassword' => __('opes.staff_portal.password_too_short'),
            ]);
        }

        DB::table('users')->where('id', $userId)->update([
            'password' => Hash::make($this->newPassword),
            'must_change_password_at' => null,
            'updated_at' => now(),
        ]);

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $this->dispatch('opes-portal-password-changed');
    }

    /**
     * Download the payslip PDF for one of THIS staff member's own payroll
     * items. `$payrollItemId` is attacker-controlled input (a button's
     * wire:click argument), so ownership is re-verified against
     * `StaffPortalContext` here rather than trusted from the render-time
     * list - the same discipline `changePassword` applies to `auth()->id()`.
     */
    public function downloadPayslip(int $payrollItemId): Response
    {
        $context = StaffPortalContext::current();

        if ($context === null) {
            abort(403);
        }

        $item = DB::table('payroll_items as pi')
            ->join('payroll_runs as pr', 'pr.id', '=', 'pi.payroll_run_id')
            ->where('pi.id', $payrollItemId)
            ->where('pi.staff_member_id', $context->staffMemberId)
            ->first(['pi.id', 'pi.gross', 'pi.total_employee_deductions', 'pi.net', 'pr.payroll_month', 'pr.run_type']);

        if ($item === null) {
            abort(404);
        }

        $lines = DB::table('payroll_lines as pl')
            ->join('payroll_components as pc', 'pc.id', '=', 'pl.payroll_component_id')
            ->where('pl.payroll_item_id', $item->id)
            ->orderBy('pc.calculation_order')
            ->get(['pc.name', 'pl.amount']);

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [$line->name, $line->amount];
        }

        $rows[] = ['Gross', $item->gross];
        $rows[] = ['Total deductions', $item->total_employee_deductions];
        $rows[] = ['Net pay', $item->net];

        return PdfExport::download(
            'Payslip - '.$item->payroll_month,
            ['Component', 'Amount'],
            $rows,
            'payslip-'.$item->payroll_month.'.pdf',
        );
    }

    /**
     * @return array<int, \stdClass>
     */
    private function timetableSlots(int $staffMemberId): array
    {
        $yearId = DB::table('academic_years')->where('is_current', true)->value('id');

        if ($yearId === null) {
            return [];
        }

        return DB::table('timetable_slots as ts')
            ->join('timetable_periods as tp', 'tp.id', '=', 'ts.timetable_period_id')
            ->join('subjects as sub', 'sub.id', '=', 'ts.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ts.class_group_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'ts.room_id')
            ->where('ts.staff_member_id', $staffMemberId)
            ->where('ts.academic_year_id', $yearId)
            ->orderBy('ts.day_of_week')
            ->orderBy('tp.sequence')
            ->get([
                'ts.day_of_week', 'tp.name as period_name', 'tp.sequence',
                'sub.name as subject_name', 'cg.name as class_group_name', 'r.name as room_name',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{balances: array<int, \stdClass>, requests: array<int, \stdClass>}
     */
    private function leaveData(int $staffMemberId): array
    {
        $contractIds = DB::table('staff_contracts')
            ->where('staff_member_id', $staffMemberId)
            ->pluck('id');

        if ($contractIds->isEmpty()) {
            return ['balances' => [], 'requests' => []];
        }

        $balances = DB::table('leave_accruals as la')
            ->join('leave_types as lt', 'lt.id', '=', 'la.leave_type_id')
            ->whereIn('la.staff_contract_id', $contractIds)
            ->groupBy('lt.id', 'lt.name')
            ->orderBy('lt.name')
            ->get(['lt.name as leave_type_name', DB::raw('SUM(la.delta_days) as balance')])
            ->values()
            ->all();

        $requests = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->whereIn('lr.staff_contract_id', $contractIds)
            ->orderByDesc('lr.starts_on')
            ->limit(50)
            ->get(['lr.starts_on', 'lr.ends_on', 'lr.working_days', 'lr.status', 'lt.name as leave_type_name'])
            ->values()
            ->all();

        return ['balances' => $balances, 'requests' => $requests];
    }

    /**
     * @return array<int, \stdClass>
     */
    private function payslips(int $staffMemberId): array
    {
        return DB::table('payroll_items as pi')
            ->join('payroll_runs as pr', 'pr.id', '=', 'pi.payroll_run_id')
            ->where('pi.staff_member_id', $staffMemberId)
            ->where('pi.is_cancelled', false)
            ->orderByDesc('pr.payroll_month')
            ->get([
                'pi.id as payroll_item_id', 'pr.payroll_month', 'pr.run_type',
                'pi.gross', 'pi.total_employee_deductions', 'pi.net',
            ])
            ->values()
            ->all();
    }

    public function render(): mixed
    {
        $context = StaffPortalContext::current();

        if ($context === null) {
            abort(403);
        }

        $staff = DB::table('staff_members')->where('id', $context->staffMemberId)->first([
            'staff_no', 'first_name', 'last_name', 'phone', 'email', 'status',
        ]);

        $contract = DB::table('staff_contracts')
            ->where('staff_member_id', $context->staffMemberId)
            ->whereNull('ends_on')
            ->orderByDesc('starts_on')
            ->first(['contract_role', 'starts_on']);

        $leave = $this->leaveData($context->staffMemberId);

        return view('livewire.hr.portal.show', [
            'staff' => $staff,
            'contract' => $contract,
            'timetableSlots' => $this->timetableSlots($context->staffMemberId),
            'leaveBalances' => $leave['balances'],
            'leaveRequests' => $leave['requests'],
            'payslips' => $this->payslips($context->staffMemberId),
        ]);
    }
}
