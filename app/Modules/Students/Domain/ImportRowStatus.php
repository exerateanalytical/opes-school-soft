<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * A single row's verdict. `imported` carries the created record's id, which
 * is what makes a commit resumable rather than duplicating on a second run.
 */
enum ImportRowStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Imported = 'imported';
    case Skipped = 'skipped';
}
