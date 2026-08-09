<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/**
 * docs/specs/06-assets-stores.md §10.4. `Overdue` is a PERSISTED state
 * promoted by the nightly job, never a computed `due_on < today`.
 */
enum IssueStatus: string
{
    case Open = 'open';
    case Returned = 'returned';
    case Overdue = 'overdue';
    case Lost = 'lost';
    case WrittenOff = 'written_off';
}
