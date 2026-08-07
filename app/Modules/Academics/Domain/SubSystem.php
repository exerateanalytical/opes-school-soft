<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * Cameroon's two education sub-systems (docs/specs/00-core.md 8).
 *
 * The sub-system is structural, not cosmetic: grading scales, exam bodies
 * (GCE Board vs Office du Baccalaureat) and class ladders differ between the
 * two, so it is part of the unique identity of a SchoolSection.
 */
enum SubSystem: string
{
    case Anglophone = 'anglophone';
    case Francophone = 'francophone';

    public function label(string $locale = 'en'): string
    {
        return __('opes.sub_systems.'.$this->value, [], $locale);
    }
}
