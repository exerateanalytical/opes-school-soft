<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/** `ChartOfAccount.dsf_statement` (02-accounting.md 2.1). */
enum DsfStatement: string
{
    case BilanActif = 'bilan_actif';
    case BilanPassif = 'bilan_passif';
    case Resultat = 'resultat';
    case Flux = 'flux';
    case Note = 'note';
}
