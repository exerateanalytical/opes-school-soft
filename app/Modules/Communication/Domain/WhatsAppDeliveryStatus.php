<?php

declare(strict_types=1);

namespace App\Modules\Communication\Domain;

/**
 * The outcome of one attempt to hand a message to Meta, mirroring the enum
 * column on `whatsapp_delivery_logs.status` exactly.
 *
 * `Refused` is the case worth naming. It means the platform declined before
 * any network call - no credentials, channel switched off, or a number with
 * no usable digits - so nothing left the building and no gateway credit was
 * spent. Folding it into `Failed` would tell a school its messages were
 * bouncing off Meta when in truth they were never wired up, which sends
 * somebody debugging a network that is working fine.
 *
 * Note what is NOT here: `delivered` and `read`. Meta reports those
 * asynchronously on a webhook, and this table only knows what the send call
 * returned. Claiming delivery we have not been told about would defeat the
 * point of keeping the log at all.
 */
enum WhatsAppDeliveryStatus: string
{
    /** Meta accepted the message and returned a wamid. */
    case Sent = 'sent';

    /** The call happened and Meta rejected it, or the network failed. */
    case Failed = 'failed';

    /** Refused locally; nothing was transmitted. */
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Accepted by WhatsApp',
            self::Failed => 'Failed',
            self::Refused => 'Not sent',
        };
    }
}
