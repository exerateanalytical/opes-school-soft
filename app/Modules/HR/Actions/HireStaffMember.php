<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\CnpsRegistrationStatus;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates the PERSON - identity, statutory identifiers, payment coordinates
 * (docs/specs/05-hr-payroll.md 3.3). Employment is a separate act:
 * OpenStaffContract writes the contract, and nothing employment-related
 * lands on the staff_members row.
 *
 * 11.5: CNPS worker registration is due within 8 DAYS of hire. This Action
 * sets `cnps_registration_status = pending` with the deadline stamped, or
 * `registered` when the worker already has a CNPS number (a teacher hired
 * from another school keeps their number for life). The deadline drives
 * alerts at T-3, T-1 and overdue, and an overdue registration appears on
 * every payroll run's exception report.
 */
final class HireStaffMember
{
    public const STAFF_NO_FORMAT = 'STF/%d/%04d';

    /**
     * @param  string  $dateOfBirth  Y-m-d
     * @param  string|null  $hiredOn  Y-m-d; defaults to the business date
     */
    public function handle(
        string $firstName,
        string $lastName,
        string $gender,
        string $dateOfBirth,
        string $phone,
        ?string $hiredOn = null,
        ?string $otherNames = null,
        ?string $email = null,
        ?string $placeOfBirth = null,
        string $nationality = 'CM',
        ?string $nationalIdType = null,
        ?string $nationalIdNumber = null,
        ?string $cnpsNumber = null,
        ?string $niu = null,
        ?string $bankName = null,
        ?string $bankAccount = null,
        ?string $mobileMoneyNumber = null,
        ?string $maritalStatus = null,
        ?string $nextOfKinName = null,
        ?string $nextOfKinRelationship = null,
        ?string $nextOfKinPhone = null,
    ): StaffMember {
        Gate::authorize(HrPermission::MANAGE);

        $actor = $this->currentActor();
        $hireDate = $hiredOn ?? BusinessDate::today();
        $year = (int) Carbon::parse($hireDate)->format('Y');

        return DB::transaction(function () use (
            $firstName, $lastName, $gender, $dateOfBirth, $phone, $otherNames, $email,
            $placeOfBirth, $nationality, $nationalIdType, $nationalIdNumber, $cnpsNumber,
            $niu, $bankName, $bankAccount, $mobileMoneyNumber, $maritalStatus,
            $nextOfKinName, $nextOfKinRelationship, $nextOfKinPhone, $hireDate, $year, $actor,
        ): StaffMember {
            // Row-locked sequence INSIDE the transaction (00-core 12), never
            // max()+1: two clerks hiring at once would mint one staff number.
            $sequence = app(SequenceAllocator::class)->allocate('staff_no.'.$year);
            $staffNo = sprintf(self::STAFF_NO_FORMAT, $year, $sequence);

            $staff = new StaffMember([
                'staff_no' => $staffNo,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'other_names' => $otherNames,
                'gender' => $gender,
                'date_of_birth' => $dateOfBirth,
                'place_of_birth' => $placeOfBirth,
                'nationality' => strtoupper($nationality),
                'phone' => $phone,
                'email' => $email,
                'national_id_type' => $nationalIdType,
                'national_id_number' => $nationalIdNumber,
                'cnps_number' => $cnpsNumber,
                'niu' => $niu,
                'bank_name' => $bankName,
                'bank_account' => $bankAccount,
                'mobile_money_number' => $mobileMoneyNumber,
                'marital_status' => $maritalStatus,
                'next_of_kin_name' => $nextOfKinName,
                'next_of_kin_relationship' => $nextOfKinRelationship,
                'next_of_kin_phone' => $nextOfKinPhone,
                'status' => 'active',
            ]);

            // 00-core 9.5: encrypted columns cannot be indexed, so uniqueness
            // of both statutory identifiers rides on an HMAC of the plaintext.
            $staff->national_id_blind_index = StaffMember::blindIndexFor($nationalIdNumber);
            $staff->cnps_number_blind_index = StaffMember::blindIndexFor($cnpsNumber);

            // 11.5. A worker who arrives with a CNPS number is already
            // registered; everyone else is pending with the statutory
            // 8-day clock running from the hire date.
            if ($cnpsNumber !== null) {
                $staff->cnps_registration_status = CnpsRegistrationStatus::Registered;
                $staff->cnps_registered_on = Carbon::parse($hireDate);
            } else {
                $staff->cnps_registration_status = CnpsRegistrationStatus::Pending;
                $staff->cnps_registration_deadline = Carbon::parse($hireDate)
                    ->addDays(StaffMember::CNPS_REGISTRATION_DAYS);
            }

            try {
                $staff->save();
            } catch (UniqueConstraintViolationException) {
                // The sequence makes a staff_no collision essentially
                // impossible; in practice this is a blind index catching a
                // person already on the register under another staff number.
                throw ValidationException::withMessages([
                    'national_id_number' => 'This identity is already recorded against another staff member.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'HR',
                auditableType: StaffMember::class,
                auditableId: (int) $staff->getKey(),
                after: [
                    'staff_no' => $staffNo,
                    // No national ID, no CNPS number, no bank coordinates:
                    // 00-core 9.5 keeps encrypted-field values out of logs,
                    // and an audit payload is a log.
                    'name' => $staff->fullName(),
                    'cnps_registration_status' => $staff->cnps_registration_status->value,
                    'cnps_registration_deadline' => $staff->cnps_registration_deadline?->toDateString(),
                ],
                actor: $actor,
            );

            return $staff;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
