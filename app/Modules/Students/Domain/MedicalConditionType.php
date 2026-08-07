<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/** docs/specs/07-students.md 8.2. */
enum MedicalConditionType: string
{
    case Allergy = 'allergy';
    case ChronicCondition = 'chronic_condition';
    case Medication = 'medication';
    case Disability = 'disability';
    case Immunisation = 'immunisation';
    case Incident = 'incident';
}
