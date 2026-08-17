<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support\WhatsApp;

use RuntimeException;

/**
 * Thrown when something tries to send a WhatsApp message on an instance that
 * has no Meta credentials.
 *
 * This exists as a NAMED type, with a message that names the exact settings,
 * because the failure mode it prevents is the expensive one: a school
 * believing every parent was told about tomorrow's closure when in fact the
 * channel was never wired up. Silence here would be a lie, so the send path
 * refuses out loud and the reason lands in the log in words an office
 * administrator can act on without reading any code.
 */
final class WhatsAppNotConfiguredException extends RuntimeException
{
    public static function missing(string $what): self
    {
        return new self(
            "WhatsApp is not configured: {$what}. "
            .'Set WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID in .env, '
            .'or paste the credentials into the admin screen at /settings/whatsapp. '
            .'Both come from developers.facebook.com -> your App -> WhatsApp -> API Setup. '
            .'No message was sent.'
        );
    }

    public static function disabled(): self
    {
        return new self(
            'WhatsApp is not configured: the channel is switched off. '
            .'Turn it on at /settings/whatsapp (or set WHATSAPP_ENABLED=true). '
            .'No message was sent.'
        );
    }

    /** A recipient whose stored phone yields no digits at all. */
    public static function unusablePhone(string $raw): self
    {
        return new self(
            'WhatsApp cannot send to ['.$raw.']: the number has no usable digits, '
            .'so it cannot be put into E.164 form. Correct the guardian record. '
            .'No message was sent.'
        );
    }
}
