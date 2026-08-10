<?php

declare(strict_types=1);

namespace App\Modules\Communication\Domain;

enum ThreadKind: string
{
    case Conversation = 'conversation';
    case Announcement = 'announcement';
}
