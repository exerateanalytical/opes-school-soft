<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 3.5 - the cases v1 could not express.
 *
 * `DetacheEtat` (seconded State teacher, mis a disposition from
 * MINESEC/MINEDUB) is extremely common in Cameroonian private schools: on the
 * roster and the timetable, paid by the State, not on the school's DIPE.
 */
enum SocialSecurityStatus: string
{
    case AffilieCnps = 'affilie_cnps';
    case AssuranceVolontaire = 'assurance_volontaire';
    case ConventionBilaterale = 'convention_bilaterale';
    case DetacheEtat = 'detache_etat';
    case ExemptOther = 'exempt_other';

    /**
     * Statuses whose claim to exemption must be evidenced by a document
     * reference - an exemption is a claim the labour inspector will test.
     */
    public function requiresExemptionDocument(): bool
    {
        return $this === self::ConventionBilaterale || $this === self::ExemptOther;
    }
}
