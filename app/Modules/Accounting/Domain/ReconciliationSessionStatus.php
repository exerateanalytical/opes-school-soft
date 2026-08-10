<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §13.1 - a reconciliation session is being
 * worked on, or it is finished and its état de rapprochement is evidence.
 *
 * There is no `cancelled`: a session that should not have been opened is a
 * session with no matches, and deleting it is a data-correction question,
 * not a workflow state.
 */
enum ReconciliationSessionStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'In progress',
            self::Completed => 'Completed',
        };
    }
}
