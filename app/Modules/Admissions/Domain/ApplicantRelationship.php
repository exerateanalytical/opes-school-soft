<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Domain;

/**
 * How a proposed guardian relates to the applicant, docs/specs/07-students.md
 * 7.2.
 *
 * Declared in Admissions rather than imported from Guardians on purpose. The
 * value set is identical and must stay identical - conversion passes it
 * straight through - but a module may not depend on another module's internals
 * (00-core 6.2), and an enum consumed across a boundary is an internal. The
 * cost of the duplication is one test in AdmissionFlowTest asserting the two
 * sets still match once the Guardians enum exists; the cost of the import
 * would be a build order between two modules that are developed in parallel.
 */
enum ApplicantRelationship: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Stepfather = 'stepfather';
    case Stepmother = 'stepmother';
    case Grandparent = 'grandparent';
    case Uncle = 'uncle';
    case Aunt = 'aunt';
    case Sibling = 'sibling';
    case LegalGuardian = 'legal_guardian';
    case Sponsor = 'sponsor';
    case Other = 'other';

    /** `relationship_other` is mandatory for exactly this one case (7.2). */
    public function requiresFreeText(): bool
    {
        return $this === self::Other;
    }

    public function label(string $locale = 'en'): string
    {
        $labels = trans('opes.admissions_screen.relationships', [], $locale);

        if (is_array($labels) && is_string($labels[$this->value] ?? null)) {
            return $labels[$this->value];
        }

        return 'opes.admissions_screen.relationships.'.$this->value;
    }
}
