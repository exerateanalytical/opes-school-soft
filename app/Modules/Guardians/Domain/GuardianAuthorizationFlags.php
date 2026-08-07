<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * The five authorization flags of ONE StudentGuardian link, plus the two
 * conjunctive gates that 7.5 attaches to every grant and that Phase 2 data can
 * actually answer: the link's validity (7.3) and `Guardian.status = 'active'`.
 *
 * A value object rather than the Eloquent row because docs/specs/00-core.md
 * 6.2 rule 1 keeps Domain/ free of Laravel and Eloquent. 7.5 asks for
 * `GuardianScopeMatrix::allows(StudentGuardian $link, Capability $c)`; passing
 * the model itself would drag Eloquent into Domain/ and break that rule, so
 * the link projects itself into this struct on the way in. The decision is
 * unchanged - only the argument's type is - and the projection lives in
 * StudentGuardian::authorizationFlags(), one method, easy to audit.
 *
 * `is_primary` is deliberately ABSENT. 7.5: "is_primary grants nothing on its
 * own"; it selects a default recipient. If it is not in this struct it cannot
 * accidentally become an input to a grant.
 *
 * The guardian-level `receives_reports` / `receives_invoices` (7.4 delivery
 * preferences) are likewise absent, and for the same reason: authorization is
 * always evaluated on the LINK.
 */
final readonly class GuardianAuthorizationFlags
{
    public function __construct(
        public bool $isValid,
        public bool $guardianIsActive,
        public bool $hasCustody,
        public bool $receivesReports,
        public bool $receivesInvoices,
        public bool $isFeePayer,
        public bool $isEmergencyContact,
    ) {}

    /**
     * The conjunctive precondition of every row in 7.5 that Phase 2 can
     * evaluate: a valid link held by an active guardian.
     *
     * 7.5's remaining conjuncts - `User.status`, the child's `Enrollment.status`
     * and `PeriodPublication.status` - belong to modules that do not exist yet
     * (Identity portal accounts, Enrollment, Assessment). They are additional
     * ANDs, so adding them can only ever narrow this answer, never widen it;
     * that is why it is safe to ship the matrix now rather than wait.
     */
    public function passesConjunctiveGate(): bool
    {
        return $this->isValid && $this->guardianIsActive;
    }
}
