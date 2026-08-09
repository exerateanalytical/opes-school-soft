<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\LeaveRequestStatus;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\StaffContract;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Captures a leave request as `submitted` (docs/specs/05-hr-payroll.md 12.2).
 * Nothing touches the ledger here - only approval writes a `taken` row.
 */
final class RequestLeave
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $staffContractId,
        string $leaveTypeCode,
        string $startsOn,
        string $endsOn,
        string $workingDays,
        ?int $medicalCertificateDocumentId = null,
        ?int $replacementStaffContractId = null,
        ?Actor $actor = null,
    ): LeaveRequest {
        Gate::authorize(HrPermission::MANAGE);

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use (
            $staffContractId, $leaveTypeCode, $startsOn, $endsOn, $workingDays,
            $medicalCertificateDocumentId, $replacementStaffContractId, $actor
        ): LeaveRequest {
            /** @var StaffContract $contract */
            $contract = StaffContract::query()->whereKey($staffContractId)->firstOrFail();

            $start = Carbon::parse($startsOn);
            $end = Carbon::parse($endsOn);

            if ($end->lt($start)) {
                throw ValidationException::withMessages([
                    'ends_on' => 'Leave cannot end before it starts.',
                ]);
            }

            if (! (float) $workingDays > 0) {
                throw ValidationException::withMessages([
                    'working_days' => 'A leave request covers at least part of a working day.',
                ]);
            }

            // The contract must actually cover the leave.
            if ($start->lt($contract->starts_on)
                || ($contract->ends_on !== null && ! $end->lt($contract->ends_on))) {
                throw ValidationException::withMessages([
                    'staff_contract_id' => 'The contract does not cover the requested leave dates.',
                ]);
            }

            /** @var LeaveType $type */
            $type = LeaveType::query()
                ->where('code', $leaveTypeCode)
                ->where('is_active', true)
                ->firstOrFail();

            if ($type->requires_medical_certificate && $medicalCertificateDocumentId === null) {
                throw ValidationException::withMessages([
                    'medical_certificate_document_id' => "Leave type '{$type->code}' requires a medical certificate.",
                ]);
            }

            if ($type->max_consecutive_days !== null
                && (float) $workingDays > (float) $type->max_consecutive_days) {
                throw ValidationException::withMessages([
                    'working_days' => "Leave type '{$type->code}' allows at most {$type->max_consecutive_days} consecutive days.",
                ]);
            }

            $request = LeaveRequest::query()->create([
                'staff_contract_id' => $contract->id,
                'leave_type_id' => $type->id,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'working_days' => $workingDays,
                'status' => LeaveRequestStatus::Submitted,
                'medical_certificate_document_id' => $medicalCertificateDocumentId,
                'replacement_staff_contract_id' => $replacementStaffContractId,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'HR',
                auditableType: LeaveRequest::class,
                auditableId: (int) $request->getKey(),
                after: [
                    'staff_contract_id' => $contract->id,
                    'leave_type' => $type->code,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                    'working_days' => $workingDays,
                ],
                actor: $actor,
            );

            return $request;
        });
    }
}
