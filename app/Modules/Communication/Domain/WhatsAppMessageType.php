<?php

declare(strict_types=1);

namespace App\Modules\Communication\Domain;

/**
 * The two Meta Cloud API message shapes this platform sends, mirroring
 * `whatsapp_delivery_logs.message_type`.
 *
 * The distinction is a Meta POLICY constraint, not a formatting preference:
 *
 *  - `Text` (a `type: text` body) is delivered ONLY inside the 24-hour
 *    customer service window, i.e. within 24h of that parent last messaging
 *    the school. Outside it Meta rejects the send with error 131047.
 *  - `Template` uses a template the school had approved in the Meta
 *    dashboard, and is the ONLY way to open a conversation.
 *
 * Since a school almost always initiates (fees due, absence, results ready),
 * Template is the normal path here and Text is the exception - the opposite
 * of what the simpler-looking API surface suggests.
 */
enum WhatsAppMessageType: string
{
    case Text = 'text';
    case Template = 'template';

    /** Whether Meta will deliver this shape to a parent who has not written in. */
    public function canInitiateConversation(): bool
    {
        return $this === self::Template;
    }
}
