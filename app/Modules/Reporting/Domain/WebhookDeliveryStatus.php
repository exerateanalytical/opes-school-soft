<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
}
