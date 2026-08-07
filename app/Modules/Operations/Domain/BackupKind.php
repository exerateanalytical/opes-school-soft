<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

enum BackupKind: string
{
    case Full = 'full';
    case Incremental = 'incremental';
}
