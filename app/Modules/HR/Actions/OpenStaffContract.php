<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\CddLimitExceeded;
use App\Modules\HR\Domain\ContractType;
use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\SocialSecurityStatus;
use App\Modules\HR\Domain\WorkingTime;
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
 * Opens a contract - the single source of employment truth
 * (docs/specs/05-hr-payroll.md 3.4).
 *
 * Enforced here, under FOR UPDATE on the staff member row:
 *
 * - one contract per role per date: no overlap on [starts_on, ends_on)
 *   within a contract_role (the uq_active_contract_role generated column
 *   guards the concurrent-open-ended case at the DB as well);
 * - the CDD invariant: at most ONE renewal and at most TWO YEARS of total
 *   elapsed duration across the renewal chain. Crossing either raises
 *   CddLimitExceeded - the chain converts to CDI by operation of law, and
 *   silently allowing it is a standard labour-inspection finding;
 * - the MINTSS visa: a non-Cameroonian employee requires a work visa
 *   reference before the contract may open;
 * - exemption evidence: convention_bilaterale / exempt_other statuses carry
 *   a claim an inspector will test, so the document reference is mandatory
 *   (recorded per-branch on staff_contract_exemptions, 3.5).
 *
 * Vacataire default (5.5 legal note): hourly staff default to affilie_cnps.
 * Overriding that classification is F2/F5's permission-gated screen concern;
 * this Action simply refuses the combination without evidence.
 */
final class OpenStaffContract
{
    public function handle(
        int $staffMemberId,
        string $contractRole,
        ContractType $contractType,
        WorkingTime $workingTime,
        int $departmentId,
        int $positionId,
        string $startsOn,
        ?string $endsOn = null,
        ?int $salaryGradeId = null,
        ?int $collectiveAgreementId = null,
        ?string $category = null,
        ?string $echelon = null,
        ?string $probationEnd = null,
        ?int $renewedFromContractId = null,
        ?string $mintssVisaRef = null,
        SocialSecurityStatus $socialSecurityStatus = SocialSecurityStatus::AffilieCnps,
        bool $isPayrollEligible = true,
        ?string $seniorityReferenceDate = null,
        ?string $exemptionDocumentRef = null,
    ): StaffContract {
        Gate::authorize(HrPermission::MANAGE);

        $actor = $this->currentActor();

        return DB::transaction(function () use (
            $staffMemberId, $contractRole, $contractType, $workingTime, $departmentId,
            $positionId, $startsOn, $endsOn, $salaryGradeId, $collectiveAgreementId,
            $category, $echelon, $probationEnd, $renewedFromContractId, $mintssVisaRef,
            $socialSecurityStatus, $isPayrollEligible, $seniorityReferenceDate,
            $exemptionDocumentRef, $actor,
        ): StaffContract {
            // The concurrency door for everything below: role uniqueness and
            // the CDD chain are both per-person invariants (00-core 11).
            /** @var StaffMember $staff */
            $staff = StaffMember::query()->whereKey($staffMemberId)->lockForUpdate()->firstOrFail();

            if ($contractType === ContractType::Cdd && $endsOn === null) {
                throw ValidationException::withMessages([
                    'ends_on' => 'A CDD must carry an end date; an open-ended fixed-term contract is a CDI.',
                ]);
            }

            if ($endsOn !== null && ! Carbon::parse($endsOn)->gt(Carbon::parse($startsOn))) {
                throw ValidationException::withMessages([
                    'ends_on' => 'The end date must fall after the start date.',
                ]);
            }

            // MINTSS work visa: required for every non-Cameroonian employee.
            if ($staff->nationality !== 'CM' && ($mintssVisaRef === null || trim($mintssVisaRef) === '')) {
                throw ValidationException::withMessages([
                    'mintss_visa_ref' => 'A non-Cameroonian employee requires a MINTSS work visa reference before a contract may open.',
                ]);
            }

            if ($socialSecurityStatus->requiresExemptionDocument()
                && ($exemptionDocumentRef === null || trim($exemptionDocumentRef) === '')) {
                throw ValidationException::withMessages([
                    'exemption_document_ref' => 'This social-security status is a claim an inspector will test: an evidencing document reference is required.',
                ]);
            }

            $this->assertNoRoleOverlap($staffMemberId, $contractRole, $startsOn, $endsOn);

            $renewalCount = 0;
            $chainStartsOn = $startsOn;
            $renewedFrom = null;

            if ($renewedFromContractId !== null) {
                /** @var StaffContract $renewedFrom */
                $renewedFrom = StaffContract::query()
                    ->whereKey($renewedFromContractId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($renewedFrom->staff_member_id !== $staffMemberId
                    || $renewedFrom->contract_role !== $contractRole) {
                    throw ValidationException::withMessages([
                        'renewed_from_contract_id' => 'A renewal continues the same person in the same role; this contract does not.',
                    ]);
                }

                $renewalCount = $renewedFrom->renewal_count + 1;
                $chainStartsOn = $this->chainStartDate($renewedFrom);
            }

            // The CDD invariant (3.4). Both checks against the CHAIN, not
            // the single row: the two-year clock started when the first CDD
            // in the chain did.
            if ($contractType === ContractType::Cdd) {
                if ($renewalCount > ContractType::CDD_MAX_RENEWALS) {
                    throw CddLimitExceeded::renewals($renewalCount);
                }

                /** @var string $endsOn CDD ends_on is non-null, asserted above */
                $limit = Carbon::parse($chainStartsOn)->addYears(ContractType::CDD_MAX_YEARS);

                if (Carbon::parse($endsOn)->gt($limit)) {
                    throw CddLimitExceeded::duration($chainStartsOn, $endsOn);
                }
            }

            $contract = new StaffContract([
                'staff_member_id' => $staffMemberId,
                'contract_role' => $contractRole,
                'contract_type' => $contractType,
                'working_time' => $workingTime,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'salary_grade_id' => $salaryGradeId,
                'collective_agreement_id' => $collectiveAgreementId,
                'category' => $category,
                'echelon' => $echelon,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'probation_end' => $probationEnd,
                'renewal_count' => $renewalCount,
                'renewed_from_contract_id' => $renewedFromContractId,
                'mintss_visa_ref' => $mintssVisaRef,
                'social_security_status' => $socialSecurityStatus,
                'is_payroll_eligible' => $isPayrollEligible,
                // Drives prime d'anciennete: a renewal or conversion keeps
                // the seniority clock of the chain's first contract.
                'seniority_reference_date' => $seniorityReferenceDate ?? $chainStartsOn,
            ]);

            $contract->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'HR',
                auditableType: StaffContract::class,
                auditableId: (int) $contract->getKey(),
                after: [
                    'staff_no' => $staff->staff_no,
                    'contract_role' => $contractRole,
                    'contract_type' => $contractType->value,
                    'working_time' => $workingTime->value,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'renewal_count' => $renewalCount,
                ],
                actor: $actor,
            );

            return $contract;
        });
    }

    /**
     * One contract per role per date: no overlap on [starts_on, ends_on)
     * among this person's contracts in the same role. The generated-column
     * unique key catches two open-ended contracts; this catches the dated
     * cases MySQL's generated columns cannot express (CURDATE() is not
     * permitted in a stored column).
     */
    private function assertNoRoleOverlap(int $staffMemberId, string $contractRole, string $startsOn, ?string $endsOn): void
    {
        $overlapping = StaffContract::query()
            ->where('staff_member_id', $staffMemberId)
            ->where('contract_role', $contractRole)
            ->where(function ($query) use ($startsOn, $endsOn): void {
                // Existing [s, e) overlaps proposed [S, E) iff s < E and S < e.
                $query->when($endsOn !== null, fn ($q) => $q->where('starts_on', '<', $endsOn))
                    ->where(function ($q) use ($startsOn): void {
                        $q->whereNull('ends_on')->orWhere('ends_on', '>', $startsOn);
                    });
            })
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages([
                'contract_role' => sprintf(
                    'An overlapping %s contract already exists for this staff member; close it first or choose non-overlapping dates.',
                    $contractRole,
                ),
            ]);
        }
    }

    /**
     * Walk the renewal chain to its first contract's start date - the date
     * the statutory two-year CDD clock started.
     */
    private function chainStartDate(StaffContract $contract): string
    {
        $current = $contract;

        // Bounded walk: renewal_count caps chain length structurally, but a
        // hand-crafted cycle must not hang the save.
        for ($hops = 0; $hops < 16; $hops++) {
            if ($current->renewed_from_contract_id === null) {
                break;
            }

            /** @var StaffContract $current */
            $current = StaffContract::query()
                ->whereKey($current->renewed_from_contract_id)
                ->firstOrFail();
        }

        return $current->starts_on->toDateString();
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
