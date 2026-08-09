<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The employer's CNPS regime (docs/specs/05-hr-payroll.md 3.1, fixing
 * defect N2): the PF rate differs by regime - the one for personnel de
 * l'enseignement prive is materially lower than the regime general - and
 * the school's own regime is printed on its CNPS notification letter.
 */
enum CnpsRegime: string
{
    case General = 'general';
    case Agricole = 'agricole';
    case EnseignementPrive = 'enseignement_prive';
}
