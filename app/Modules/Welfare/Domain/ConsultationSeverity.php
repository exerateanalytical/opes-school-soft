<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Triage severity of a sick-bay visit - the same three-step scale as
 * Students' MedicalSeverity (docs/plans/phase-10.md §3 row 8: "reusing
 * pattern of MedicalSeverity"), duplicated here rather than imported so
 * Welfare shares no Domain surface with Students and the two scales can
 * diverge independently if the clinic ever needs finer triage.
 */
enum ConsultationSeverity: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
}
