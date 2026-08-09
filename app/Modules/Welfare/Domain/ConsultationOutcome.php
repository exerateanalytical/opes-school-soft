<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * How a sick-bay visit ended (docs/plans/phase-10.md §3 row 8). `Referred`
 * is the only outcome that carries an obligation: a MedicalReferral row
 * records where the child was sent and stays open until followed up.
 */
enum ConsultationOutcome: string
{
    case ReturnedToClass = 'returned_to_class';
    case SentHome = 'sent_home';
    case Referred = 'referred';
    case Admitted = 'admitted';
}
