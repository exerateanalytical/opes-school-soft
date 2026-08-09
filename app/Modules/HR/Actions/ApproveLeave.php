<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\LeaveEntryType;
use App\Modules\HR\Domain\LeaveRequestStatus;
use App\Modules\HR\Models\LeaveAccrual;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\StaffContract;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Decides a submitted leave request (docs/specs/05-hr-payroll.md 12.2).
 *
 * Approval holds FOR UPDATE on the CONTRACT row while checking the overlap
 * invariant (no two approved requests for one contract may overlap), then
 * writes exactly one negative `taken` ledger row. Rejection touches the
 * ledger not at all. Both transitions are conditional UPDATEs from
 * `submitted` with affected-rows checks (00-core 10.4).
 */
final class ApproveLeave
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function approve(int $leaveRequestId, Actor $actor): LeaveRequest
    {
        Gate::authorize(HrPermission::LEAVE_APPROVE);

        return DB::transaction(function () use ($leaveRequestId, $actor): LeaveRequest {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($leaveRequestId)->lockForUpdate()->firstOrFail();

            // The lock window for the overlap invariant (12.2).
            StaffContract::query()->whereKey($request->staff_contract_id)->lockForUpdate()->firstOrFail();

            $overlapping = LeaveRequest::query()
                ->where('staff_contract_id', $request->staff_contract_id)
                ->whereKeyNot($request->id)
                ->where('status', LeaveRequestStatus::Approved->value)
                ->where('starts_on', '<=', $request->ends_on->toDateString())
                ->where('ends_on', '>=', $request->starts_on->toDateString())
                ->exists();

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'starts_on' => 'An approved leave request already covers part of these dates.',
                ]);
            }

            $updated = LeaveRequest::query()
                ->whereKey($request->id)
                ->where('status', LeaveRequestStatus::Submitted->value)
                ->update([
                    'status' => LeaveRequestStatus::Approved->value,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'version' => $request->version + 1,
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'status' => "Only a submitted request can be approved; this one is '{$request->status->value}'.",
                ]);
            }

            // The single `taken` delta - always negative (CHECK).
            LeaveAccrual::query()->create([
                'staff_contract_id' => $request->staff_contract_id,
                'leave_type_id' => $request->leave_type_id,
                'entry_type' => LeaveEntryType::Taken,
                'delta_days' => '-'.$request->working_days,
                'effective_on' => $request->starts_on->toDateString(),
                'source_type' => 'leave_request',
                'source_id' => $request->id,
                'reason' => null,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: LeaveRequest::class,
                auditableId: (int) $request->getKey(),
                before: ['status' => LeaveRequestStatus::Submitted->value],
                after: ['status' => LeaveRequestStatus::Approved->value],
                actor: $actor,
            );

            return $request->refresh();
        });
    }

    public function reject(int $leaveRequestId, string $reason, Actor $actor): LeaveRequest
    {
        Gate::authorize(HrPermission::LEAVE_APPROVE);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Rejecting a leave request requires a reason.',
            ]);
        }

        return DB::transaction(function () use ($leaveRequestId, $reason, $actor): LeaveRequest {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($leaveRequestId)->lockForUpdate()->firstOrFail();

            $updated = LeaveRequest::query()
                ->whereKey($request->id)
                ->where('status', LeaveRequestStatus::Submitted->value)
                ->update([
                    'status' => LeaveRequestStatus::Rejected->value,
                    'rejection_reason' => $reason,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'version' => $request->version + 1,
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'status' => "Only a submitted request can be rejected; this one is '{$request->status->value}'.",
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: LeaveRequest::class,
                auditableId: (int) $request->getKey(),
                before: ['status' => LeaveRequestStatus::Submitted->value],
                after: ['status' => LeaveRequestStatus::Rejected->value, 'rejection_reason' => $reason],
                actor: $actor,
            );

            return $request->refresh();
        });
    }

    /**
     * Cancels an APPROVED request by writing the compensating `adjustment`
     * row (12.2: "Nothing is ever deleted or edited").
     */
    public function cancel(int $leaveRequestId, string $reason, Actor $actor): LeaveRequest
    {
        Gate::authorize(HrPermission::LEAVE_APPROVE);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Cancelling approved leave requires a reason.',
            ]);
        }

        return DB::transaction(function () use ($leaveRequestId, $reason, $actor): LeaveRequest {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::query()->whereKey($leaveRequestId)->lockForUpdate()->firstOrFail();

            $updated = LeaveRequest::query()
                ->whereKey($request->id)
                ->where('status', LeaveRequestStatus::Approved->value)
                ->update([
                    'status' => LeaveRequestStatus::Cancelled->value,
                    'version' => $request->version + 1,
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'status' => "Only an approved request can be cancelled; this one is '{$request->status->value}'.",
                ]);
            }

            LeaveAccrual::query()->create([
                'staff_contract_id' => $request->staff_contract_id,
                'leave_type_id' => $request->leave_type_id,
                'entry_type' => LeaveEntryType::Adjustment,
                'delta_days' => $request->working_days,
                'effective_on' => $request->starts_on->toDateString(),
                'source_type' => 'leave_request',
                'source_id' => $request->id,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: LeaveRequest::class,
                auditableId: (int) $request->getKey(),
                before: ['status' => LeaveRequestStatus::Approved->value],
                after: ['status' => LeaveRequestStatus::Cancelled->value, 'reason' => $reason],
                actor: $actor,
            );

            return $request->refresh();
        });
    }
}
