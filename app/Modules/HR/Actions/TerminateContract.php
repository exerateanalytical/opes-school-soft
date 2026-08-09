<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\CnpsRegistrationStatus;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\TerminationReason;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Closes one contract (docs/specs/05-hr-payroll.md 13).
 *
 * `ends_on` is EXCLUSIVE, like every effective-dated range here: the day
 * after the last working day. When the person's last in-force contract
 * closes, the person-level lifecycle follows (status terminated/retired)
 * and the CNPS departure declaration falls due (11.5) - the registration
 * status moves to declared_departed so the register and the next DIPE agree.
 *
 * The settlement itself - TerminationSettlement draft, the staff_departure
 * StatutoryDeclaration row, the certificat de travail and solde de tout
 * compte documents - is the F4 termination package's scope (its tables,
 * 2026_08_09_2900{16,18}, land with it); ComputeTerminationSettlement picks
 * up from the contract this Action closes. Severance CANNOT be computed
 * regardless: the schedule is NEEDS VERIFICATION (2.4) and ships empty.
 */
final class TerminateContract
{
    public function handle(
        int $contractId,
        TerminationReason $reason,
        string $lastWorkingDay,
    ): StaffContract {
        Gate::authorize(HrPermission::MANAGE);

        $actor = $this->currentActor();

        return DB::transaction(function () use ($contractId, $reason, $lastWorkingDay, $actor): StaffContract {
            /** @var StaffContract $contract */
            $contract = StaffContract::query()->whereKey($contractId)->lockForUpdate()->firstOrFail();

            if ($contract->termination_reason !== null) {
                throw ValidationException::withMessages([
                    'contract' => 'This contract is already terminated.',
                ]);
            }

            // Exclusive end: the contract covers the last working day.
            $endsOn = Carbon::parse($lastWorkingDay)->addDay();

            if (! $endsOn->gt($contract->starts_on)) {
                throw ValidationException::withMessages([
                    'last_working_day' => 'The last working day cannot precede the contract start.',
                ]);
            }

            if ($contract->ends_on !== null && $endsOn->gt($contract->ends_on)) {
                throw ValidationException::withMessages([
                    'last_working_day' => 'A contract cannot be terminated beyond its own end date.',
                ]);
            }

            $before = [
                'ends_on' => $contract->ends_on?->toDateString(),
                'termination_reason' => null,
            ];

            $contract->ends_on = $endsOn;
            $contract->termination_reason = $reason;
            $contract->save();

            /** @var StaffMember $staff */
            $staff = StaffMember::query()->whereKey($contract->staff_member_id)->lockForUpdate()->firstOrFail();

            // Person-level lifecycle follows only when NO contract remains in
            // force - a teacher losing the boarding-master role is still
            // staff. Checked on the closed contract's first uncovered day:
            // "when this one ends, does anything else still cover them?"
            $hasLiveContract = StaffContract::query()
                ->where('staff_member_id', $staff->id)
                ->whereKeyNot($contract->id)
                ->inForceOn($endsOn->toDateString())
                ->exists();

            if (! $hasLiveContract) {
                $staff->status = $reason === TerminationReason::Retirement ? 'retired' : 'terminated';

                // 11.5: departure must be declared to CNPS. Flagging the
                // status here is what puts the declaration on the compliance
                // calendar; F4's GenerateStatutoryDeclarations writes the row.
                if ($staff->cnps_registration_status === CnpsRegistrationStatus::Registered
                    || $staff->cnps_registration_status === CnpsRegistrationStatus::Pending) {
                    $staff->cnps_registration_status = CnpsRegistrationStatus::DeclaredDeparted;
                }

                $staff->save();
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: StaffContract::class,
                auditableId: (int) $contract->getKey(),
                before: $before,
                after: [
                    'ends_on' => $endsOn->toDateString(),
                    'termination_reason' => $reason->value,
                    'staff_status' => $staff->status,
                ],
                actor: $actor,
            );

            return $contract;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
