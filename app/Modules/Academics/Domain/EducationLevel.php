<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * The Cameroonian education ladder from docs/specs/00-core.md 8.
 *
 * secondary_1 is first cycle (Form 1-5 / 6e-3e), secondary_2 second cycle
 * (Lower/Upper Sixth / 2nde-Tle). Technical and teacher training are their
 * own levels, not tracks of secondary, because their class ladders, exam
 * classes and matricule formats differ structurally.
 */
enum EducationLevel: string
{
    case Nursery = 'nursery';
    case Primary = 'primary';
    case SecondaryFirstCycle = 'secondary_1';
    case SecondarySecondCycle = 'secondary_2';
    case Technical = 'technical';
    case TeacherTraining = 'teacher_training';

    public function label(string $locale = 'en'): string
    {
        return __('opes.education_levels.'.$this->value, [], $locale);
    }
}
