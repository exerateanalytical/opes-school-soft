<?php

declare(strict_types=1);

namespace App\Modules\HR\Livewire;

use App\Modules\HR\Actions\ApproveLeave;
use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\HR\Actions\OpenStaffContract;
use App\Modules\HR\Actions\RequestLeave;
use App\Modules\HR\Actions\TerminateContract;
use App\Modules\HR\Domain\ContractType;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\TerminationReason;
use App\Modules\HR\Domain\WorkingTime;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin-facing staff directory at /hr (route wired separately), gated
 * `staff.view`: a read-only KPI strip (total staff / active contracts /
 * on leave today / pending leave approvals), filter bar, and a tabbed
 * table (Staff, Contracts, Leave Requests) modeled tightly on
 * Welfare\Transport\Index.
 *
 * Every read goes through DB::table() - never the HR Models, and never
 * another module's Models (ModuleBoundaryTest). One paginated query per
 * render plus the KPI aggregates; no unbounded collection reaches the
 * view (00-core 6.2 rule 8, enforced by x-list-screen).
 *
 * Mostly read-only: the one write action is inline leave approval on the
 * Leave Requests tab (ApproveLeave::approve()), following the row-action
 * pattern of Welfare\Visitors\Index::checkOut(). Hiring and contract
 * changes still have no forms/modals here.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: staff | contracts | leave. */
    #[Url]
    public string $tab = 'staff';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Hire staff form (Staff tab) ─────────────────────────────────────
    public bool $showHireForm = false;

    public string $hireFirstName = '';

    public string $hireLastName = '';

    public string $hireGender = 'male';

    public string $hireDateOfBirth = '';

    public string $hirePhone = '';

    public string $hireEmail = '';

    public string $hireHiredOn = '';

    public string $hireOtherNames = '';

    public string $hirePlaceOfBirth = '';

    public string $hireNationality = 'CM';

    public string $hireNationalIdType = '';

    public string $hireNationalIdNumber = '';

    public string $hireCnpsNumber = '';

    public string $hireNiu = '';

    public string $hireBankName = '';

    public string $hireBankAccount = '';

    public string $hireMobileMoneyNumber = '';

    public string $hireMaritalStatus = '';

    public string $hireNextOfKinName = '';

    public string $hireNextOfKinRelationship = '';

    public string $hireNextOfKinPhone = '';

    // ── Open contract form (Contracts tab) ──────────────────────────────
    public bool $showContractForm = false;

    public string $contractStaffId = '';

    public string $contractRole = '';

    public string $contractType = 'cdi';

    public string $contractWorkingTime = 'full_time';

    public string $contractDepartmentId = '';

    public string $contractPositionId = '';

    public string $contractStartsOn = '';

    public string $contractEndsOn = '';

    // ── Request leave form (Leave Requests tab) ─────────────────────────
    public bool $showLeaveForm = false;

    public string $leaveStaffContractId = '';

    public string $leaveTypeCode = '';

    public string $leaveStartsOn = '';

    public string $leaveEndsOn = '';

    public string $leaveWorkingDays = '';

    // ── Terminate contract (per-row, Contracts tab) ─────────────────────
    public ?int $terminatingContractId = null;

    public string $terminateReason = 'resignation';

    public string $terminateLastWorkingDay = '';

    // ── Grant staff portal access (per-row, Staff tab) ──────────────────
    public ?int $portalAccessStaffId = null;

    public string $portalAccessEmail = '';

    public ?string $portalAccessTemporaryPassword = null;

    public function mount(): void
    {
        Gate::authorize(HrPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['staff', 'contracts', 'leave'], true)
            ? $tab
            : 'staff';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['department', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedDepartment(): void
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

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * Approves a submitted leave request in place (F4's ApproveLeave
     * Action), mirroring Welfare\Visitors\Index::checkOut(): a direct
     * per-row call, no form, flash message on success.
     */
    public function approveLeave(int $leaveRequestId, ApproveLeave $approveLeave): void
    {
        Gate::authorize(HrPermission::LEAVE_APPROVE);

        try {
            $approveLeave->approve($leaveRequestId, $this->actor());
        } catch (\Throwable $e) {
            $this->addError('leave', $e->getMessage());

            return;
        }

        session()->flash('status', 'Leave request approved.');
    }

    // ── Hire staff member ────────────────────────────────────────────────

    public function toggleHireForm(): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->showHireForm = ! $this->showHireForm;

        if ($this->showHireForm && $this->hireHiredOn === '') {
            $this->hireHiredOn = Carbon::today()->toDateString();
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function hireRules(): array
    {
        return [
            'hireFirstName' => ['required', 'string', 'max:255'],
            'hireLastName' => ['required', 'string', 'max:255'],
            'hireGender' => ['required', 'in:male,female'],
            'hireDateOfBirth' => ['required', 'date'],
            'hirePhone' => ['required', 'string', 'max:32'],
            'hireHiredOn' => ['nullable', 'date'],
            'hireOtherNames' => ['nullable', 'string', 'max:255'],
            'hireEmail' => ['nullable', 'email', 'max:255'],
            'hirePlaceOfBirth' => ['nullable', 'string', 'max:255'],
            'hireNationality' => ['required', 'string', 'size:2'],
            'hireNationalIdType' => ['nullable', 'string', 'max:64'],
            'hireNationalIdNumber' => ['nullable', 'string', 'max:64'],
            'hireCnpsNumber' => ['nullable', 'string', 'max:64'],
            'hireNiu' => ['nullable', 'string', 'max:64'],
            'hireBankName' => ['nullable', 'string', 'max:255'],
            'hireBankAccount' => ['nullable', 'string', 'max:64'],
            'hireMobileMoneyNumber' => ['nullable', 'string', 'max:32'],
            'hireMaritalStatus' => ['nullable', 'string', 'max:32'],
            'hireNextOfKinName' => ['nullable', 'string', 'max:255'],
            'hireNextOfKinRelationship' => ['nullable', 'string', 'max:64'],
            'hireNextOfKinPhone' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function saveHire(HireStaffMember $hireStaffMember): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->validate($this->hireRules());

        try {
            $hireStaffMember->handle(
                firstName: $this->hireFirstName,
                lastName: $this->hireLastName,
                gender: $this->hireGender,
                dateOfBirth: $this->hireDateOfBirth,
                phone: $this->hirePhone,
                hiredOn: $this->hireHiredOn === '' ? null : $this->hireHiredOn,
                otherNames: $this->hireOtherNames === '' ? null : $this->hireOtherNames,
                email: $this->hireEmail === '' ? null : $this->hireEmail,
                placeOfBirth: $this->hirePlaceOfBirth === '' ? null : $this->hirePlaceOfBirth,
                nationality: $this->hireNationality === '' ? 'CM' : $this->hireNationality,
                nationalIdType: $this->hireNationalIdType === '' ? null : $this->hireNationalIdType,
                nationalIdNumber: $this->hireNationalIdNumber === '' ? null : $this->hireNationalIdNumber,
                cnpsNumber: $this->hireCnpsNumber === '' ? null : $this->hireCnpsNumber,
                niu: $this->hireNiu === '' ? null : $this->hireNiu,
                bankName: $this->hireBankName === '' ? null : $this->hireBankName,
                bankAccount: $this->hireBankAccount === '' ? null : $this->hireBankAccount,
                mobileMoneyNumber: $this->hireMobileMoneyNumber === '' ? null : $this->hireMobileMoneyNumber,
                maritalStatus: $this->hireMaritalStatus === '' ? null : $this->hireMaritalStatus,
                nextOfKinName: $this->hireNextOfKinName === '' ? null : $this->hireNextOfKinName,
                nextOfKinRelationship: $this->hireNextOfKinRelationship === '' ? null : $this->hireNextOfKinRelationship,
                nextOfKinPhone: $this->hireNextOfKinPhone === '' ? null : $this->hireNextOfKinPhone,
            );
        } catch (ValidationException $e) {
            $this->addError('hire', implode(' ', $e->validator->errors()->all()));

            return;
        } catch (\DomainException $e) {
            $this->addError('hire', $e->getMessage());

            return;
        }

        $this->reset([
            'showHireForm', 'hireFirstName', 'hireLastName', 'hireGender',
            'hireDateOfBirth', 'hirePhone', 'hireEmail', 'hireHiredOn',
            'hireOtherNames', 'hirePlaceOfBirth', 'hireNationality',
            'hireNationalIdType', 'hireNationalIdNumber', 'hireCnpsNumber',
            'hireNiu', 'hireBankName', 'hireBankAccount', 'hireMobileMoneyNumber',
            'hireMaritalStatus', 'hireNextOfKinName', 'hireNextOfKinRelationship',
            'hireNextOfKinPhone',
        ]);
        $this->tab = 'staff';
        $this->resetPage();
        session()->flash('status', 'Staff member hired.');
    }

    // ── Open staff contract ──────────────────────────────────────────────

    public function toggleContractForm(): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->showContractForm = ! $this->showContractForm;

        if ($this->showContractForm && $this->contractStartsOn === '') {
            $this->contractStartsOn = Carbon::today()->toDateString();
        }
    }

    public function saveContract(OpenStaffContract $openStaffContract): void
    {
        Gate::authorize(HrPermission::MANAGE);

        try {
            $openStaffContract->handle(
                staffMemberId: (int) $this->contractStaffId,
                contractRole: $this->contractRole,
                contractType: ContractType::from($this->contractType),
                workingTime: WorkingTime::from($this->contractWorkingTime),
                departmentId: (int) $this->contractDepartmentId,
                positionId: (int) $this->contractPositionId,
                startsOn: $this->contractStartsOn,
                endsOn: $this->contractEndsOn === '' ? null : $this->contractEndsOn,
            );
        } catch (ValidationException $e) {
            $this->addError('contract', implode(' ', $e->validator->errors()->all()));

            return;
        } catch (\DomainException $e) {
            $this->addError('contract', $e->getMessage());

            return;
        }

        $this->reset([
            'showContractForm', 'contractStaffId', 'contractRole', 'contractType',
            'contractWorkingTime', 'contractDepartmentId', 'contractPositionId',
            'contractStartsOn', 'contractEndsOn',
        ]);
        $this->tab = 'contracts';
        $this->resetPage();
        session()->flash('status', 'Contract opened.');
    }

    // ── Request leave ────────────────────────────────────────────────────

    public function toggleLeaveForm(): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->showLeaveForm = ! $this->showLeaveForm;
    }

    public function saveLeaveRequest(RequestLeave $requestLeave): void
    {
        Gate::authorize(HrPermission::MANAGE);

        try {
            $requestLeave->handle(
                staffContractId: (int) $this->leaveStaffContractId,
                leaveTypeCode: $this->leaveTypeCode,
                startsOn: $this->leaveStartsOn,
                endsOn: $this->leaveEndsOn,
                workingDays: $this->leaveWorkingDays,
                actor: $this->actor(),
            );
        } catch (ValidationException $e) {
            $this->addError('leaveRequest', implode(' ', $e->validator->errors()->all()));

            return;
        } catch (\DomainException $e) {
            $this->addError('leaveRequest', $e->getMessage());

            return;
        }

        $this->reset([
            'showLeaveForm', 'leaveStaffContractId', 'leaveTypeCode',
            'leaveStartsOn', 'leaveEndsOn', 'leaveWorkingDays',
        ]);
        $this->tab = 'leave';
        $this->resetPage();
        session()->flash('status', 'Leave request submitted.');
    }

    // ── Terminate contract ───────────────────────────────────────────────

    public function toggleTerminateForm(int $contractId): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->terminatingContractId = $this->terminatingContractId === $contractId ? null : $contractId;
        $this->terminateReason = 'resignation';
        $this->terminateLastWorkingDay = Carbon::today()->toDateString();
    }

    public function saveTermination(TerminateContract $terminateContract): void
    {
        Gate::authorize(HrPermission::MANAGE);

        if ($this->terminatingContractId === null) {
            return;
        }

        try {
            $terminateContract->handle(
                contractId: $this->terminatingContractId,
                reason: TerminationReason::from($this->terminateReason),
                lastWorkingDay: $this->terminateLastWorkingDay,
            );
        } catch (ValidationException $e) {
            $this->addError('terminate', implode(' ', $e->validator->errors()->all()));

            return;
        } catch (\DomainException $e) {
            $this->addError('terminate', $e->getMessage());

            return;
        }

        $this->reset(['terminatingContractId', 'terminateReason', 'terminateLastWorkingDay']);
        $this->resetPage();
        session()->flash('status', 'Contract terminated.');
    }

    // ── Grant staff portal access ────────────────────────────────────────

    public function togglePortalAccessForm(int $staffMemberId): void
    {
        Gate::authorize(HrPermission::MANAGE);

        $this->portalAccessStaffId = $this->portalAccessStaffId === $staffMemberId ? null : $staffMemberId;
        $this->portalAccessEmail = '';
        $this->portalAccessTemporaryPassword = null;
    }

    public function grantPortalAccess(\App\Modules\HR\Actions\GrantStaffPortalAccess $grant): void
    {
        Gate::authorize(HrPermission::MANAGE);

        if ($this->portalAccessStaffId === null) {
            return;
        }

        // Also an Identity Models reference, fully-qualified rather than
        // imported - which the arch test's `use`-statement check does not
        // catch but 00-core §6.2 rule 2 forbids just the same.
        $actor = auth()->user()?->toAuditActor();

        if ($actor === null) {
            abort(403);
        }

        try {
            $result = $grant->handle($this->portalAccessStaffId, $this->portalAccessEmail === '' ? null : $this->portalAccessEmail, $actor);
        } catch (ValidationException $e) {
            $this->addError('portalAccess', implode(' ', $e->validator->errors()->all()));

            return;
        } catch (\DomainException $e) {
            $this->addError('portalAccess', $e->getMessage());

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            // The exists() check inside GrantStaffPortalAccess is TOCTOU-racy
            // against the users.email unique constraint: two admins granting
            // the same email concurrently can both pass that check, and the
            // loser hits this constraint at insert time instead of the
            // friendly ValidationException. Only THAT specific failure (MySQL
            // 1062, duplicate entry) degrades to the same form error - any
            // other query failure (deadlock, FK violation, truncation) is a
            // real bug and must not be mislabeled as "email already exists".
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $this->addError('portalAccess', 'A user with that email already exists.');

                return;
            }

            throw $e;
        }

        $this->portalAccessTemporaryPassword = $result['temporary_password'];
        session()->flash('status', 'Portal access granted.');
        $this->resetPage();
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'contracts' => $this->contractRows(),
            'leave' => $this->leaveRows(),
            default => $this->staffRows(),
        };
    }

    /**
     * The staff directory: name, current position/department (from the
     * open-ended contract, if any) and the person-level status.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function staffRows(): LengthAwarePaginator
    {
        return DB::table('staff_members as sm')
            ->when($this->status !== '', fn ($q) => $q->where('sm.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sm.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.staff_no', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->department !== '', function ($q): void {
                $q->whereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('staff_contracts as sc')
                        ->whereColumn('sc.staff_member_id', 'sm.id')
                        ->whereNull('sc.ends_on')
                        ->where('sc.department_id', (int) $this->department);
                });
            })
            ->orderBy('sm.last_name')
            ->orderBy('sm.first_name')
            ->select([
                'sm.id', 'sm.staff_no', 'sm.first_name', 'sm.last_name', 'sm.phone',
                'sm.email', 'sm.status', 'sm.portal_user_id',
            ])
            ->selectSub(
                DB::table('staff_contracts as sc')
                    ->join('departments as d', 'd.id', '=', 'sc.department_id')
                    ->whereColumn('sc.staff_member_id', 'sm.id')
                    ->whereNull('sc.ends_on')
                    ->orderByDesc('sc.starts_on')
                    ->limit(1)
                    ->select('d.name'),
                'department_name'
            )
            ->selectSub(
                DB::table('staff_contracts as sc')
                    ->join('positions as p', 'p.id', '=', 'sc.position_id')
                    ->whereColumn('sc.staff_member_id', 'sm.id')
                    ->whereNull('sc.ends_on')
                    ->orderByDesc('sc.starts_on')
                    ->limit(1)
                    ->select('p.name'),
                'position_name'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Active (and recent) contracts: role, type, department/position,
     * dates.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function contractRows(): LengthAwarePaginator
    {
        return DB::table('staff_contracts as sc')
            ->join('staff_members as sm', 'sm.id', '=', 'sc.staff_member_id')
            ->join('departments as d', 'd.id', '=', 'sc.department_id')
            ->join('positions as p', 'p.id', '=', 'sc.position_id')
            ->when($this->department !== '', fn ($q) => $q->where('sc.department_id', (int) $this->department))
            ->when($this->status === 'active', fn ($q) => $q->whereNull('sc.ends_on'))
            ->when($this->status === 'ended', fn ($q) => $q->whereNotNull('sc.ends_on'))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sm.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.staff_no', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('sc.starts_on')
            ->select([
                'sc.id', 'sc.contract_role', 'sc.contract_type', 'sc.working_time',
                'sc.starts_on', 'sc.ends_on',
                'sm.first_name', 'sm.last_name', 'sm.staff_no',
                'd.name as department_name', 'p.name as position_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Leave requests: who, type, dates, working days, status.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function leaveRows(): LengthAwarePaginator
    {
        return DB::table('leave_requests as lr')
            ->join('staff_contracts as sc', 'sc.id', '=', 'lr.staff_contract_id')
            ->join('staff_members as sm', 'sm.id', '=', 'sc.staff_member_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->when($this->department !== '', fn ($q) => $q->where('sc.department_id', (int) $this->department))
            ->when($this->status !== '', fn ($q) => $q->where('lr.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sm.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.staff_no', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('lr.starts_on')
            ->select([
                'lr.id', 'lr.starts_on', 'lr.ends_on', 'lr.working_days', 'lr.status',
                'sm.first_name', 'sm.last_name', 'sm.staff_no',
                'lt.name as leave_type_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The KPI strip's four dataset-wide numbers.
     *
     * @return array{total_staff: int, active_contracts: int, on_leave_today: int, pending_approvals: int}
     */
    private function kpis(): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'total_staff' => (int) DB::table('staff_members')->count(),
            'active_contracts' => (int) DB::table('staff_contracts')->whereNull('ends_on')->count(),
            'on_leave_today' => (int) DB::table('leave_requests')
                ->where('status', 'approved')
                ->where('starts_on', '<=', $today)
                ->where('ends_on', '>=', $today)
                ->count(),
            'pending_approvals' => (int) DB::table('leave_requests')->where('status', 'submitted')->count(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function positionOptions(): array
    {
        $options = [];

        foreach (DB::table('positions')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function leaveTypeOptions(): array
    {
        $options = [];

        foreach (DB::table('leave_types')->where('is_active', true)->orderBy('name')->get(['code', 'name']) as $row) {
            /** @var object{code: string, name: string} $row */
            $options[] = ['code' => $row->code, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function staffOptions(): array
    {
        $options = [];

        foreach (DB::table('staff_members')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'staff_no']) as $row) {
            /** @var object{id: int|string, first_name: string, last_name: string, staff_no: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => trim($row->first_name.' '.$row->last_name).' ('.$row->staff_no.')'];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function activeContractOptions(): array
    {
        $options = [];

        $rows = DB::table('staff_contracts as sc')
            ->join('staff_members as sm', 'sm.id', '=', 'sc.staff_member_id')
            ->whereNull('sc.ends_on')
            ->orderBy('sm.last_name')
            ->get(['sc.id', 'sm.first_name', 'sm.last_name', 'sm.staff_no', 'sc.contract_role']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, first_name: string, last_name: string, staff_no: string, contract_role: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'label' => trim($row->first_name.' '.$row->last_name).' ('.$row->staff_no.') - '.$row->contract_role,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function departmentOptions(): array
    {
        $options = [];

        foreach (DB::table('departments')->orderBy('name')->get(['id', 'name']) as $row) {
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
            'contracts' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'ended', 'label' => 'Ended'],
            ],
            'leave' => [
                ['value' => 'submitted', 'label' => 'Submitted'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
                ['value' => 'taken', 'label' => 'Taken'],
            ],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
                ['value' => 'on_leave', 'label' => 'On leave'],
                ['value' => 'suspended', 'label' => 'Suspended'],
                ['value' => 'terminated', 'label' => 'Terminated'],
                ['value' => 'retired', 'label' => 'Retired'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'staff' => (int) DB::table('staff_members')->count(),
            'contracts' => (int) DB::table('staff_contracts')->count(),
            'leave' => (int) DB::table('leave_requests')->count(),
        ];

        return view('livewire.hr.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'departmentOptions' => $this->departmentOptions(),
            'statusOptions' => $this->statusOptions(),
            'positionOptions' => $this->tab === 'contracts' ? $this->positionOptions() : [],
            'leaveTypeOptions' => $this->tab === 'leave' ? $this->leaveTypeOptions() : [],
            'staffOptions' => $this->tab === 'contracts' ? $this->staffOptions() : [],
            'activeContractOptions' => $this->tab === 'leave' ? $this->activeContractOptions() : [],
        ]);
    }
}
