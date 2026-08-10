<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * Where a batch is in the stage -> validate -> commit pipeline.
 */
enum ImportBatchStatus: string
{
    case Staged = 'staged';
    case Validated = 'validated';
    case Committed = 'committed';
    case Failed = 'failed';
}
