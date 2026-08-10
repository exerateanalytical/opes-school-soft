<?php

declare(strict_types=1);

namespace App\Modules\Communication\Domain;

/**
 * The delivery channels the outbox knows about, mirroring the enum column
 * on `outbox_messages.channel` / `message_templates.channel` exactly
 * (2026_08_09_300004 / 300005).
 *
 * 08-operations 11.1 gates the actual SMS gateway behind a commercial
 * decision, so no channel here implies an integration: the channel says
 * WHAT would carry the message, and the configured driver decides what
 * actually happens to it.
 */
enum MessageChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'E-mail',
            self::Push => 'Push',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /** Only e-mail carries a subject line; the rest ignore it. */
    public function usesSubjectLine(): bool
    {
        return $this === self::Email;
    }
}
