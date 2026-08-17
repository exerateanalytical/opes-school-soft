<?php

declare(strict_types=1);

namespace App\Modules\Communication\Support;

use App\Modules\Communication\Actions\SendWhatsAppMessage;
use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Domain\WhatsAppDeliveryStatus;
use App\Modules\Communication\Models\OutboxMessage;
use App\Modules\Communication\Support\WhatsApp\WhatsAppConfig;
use App\Modules\Communication\Support\WhatsApp\WhatsAppNotConfiguredException;

/**
 * The real gateway MessageDriver's docblock has been promising since v1:
 * "adding a real gateway later is a new class and a config value, not a
 * change to any Action or screen." This is that class. Nothing in
 * QueueMessage, DispatchOutbox, the outbox screen or the templates changed.
 *
 * Selected with `opes.communication.driver=whatsapp`.
 *
 * It converts SendWhatsAppMessage's exception back into a DriverResult,
 * which is not a contradiction of that Action's throw-loudly contract but the
 * point of the seam. A caller asking to message ONE parent right now must
 * hear "no"; a nightly run over 300 fee reminders must not abort on the first
 * row, and DriverResult::disabled is this codebase's existing, truthful way
 * of saying "this channel is not configured here" while keeping the evidence
 * on the row (00-core 3: degrades to a queued outbox, never a blocking
 * error).
 */
final class WhatsAppDriver implements MessageDriver
{
    public function __construct(
        private readonly SendWhatsAppMessage $send,
        private readonly WhatsAppConfig $config,
    ) {}

    public function name(): string
    {
        return 'whatsapp';
    }

    public function send(OutboxMessage $message): DriverResult
    {
        if (trim($message->recipient) === '') {
            return DriverResult::failed('No recipient number on the message.');
        }

        // A driver that quietly posted an SMS body to WhatsApp would make the
        // outbox lie about which channel carried it.
        if ($message->channel !== MessageChannel::WhatsApp) {
            return DriverResult::failed(
                'The WhatsApp driver was handed a '.$message->channel->value.' message. '
                .'Set opes.communication.driver per channel, or queue this on the whatsapp channel.'
            );
        }

        try {
            // A template named on the outbox row wins; otherwise the school's
            // configured default. With NEITHER, this falls back to free-form
            // text, which Meta delivers only inside the 24h service window -
            // so the failure, when it comes, is Meta's 131047 and is recorded
            // with that explanation rather than guessed at here.
            $template = $this->templateFor($message) ?? $this->config->defaultTemplate();

            $log = $template !== null
                ? $this->send->template(
                    to: $message->recipient,
                    templateName: $template,
                    parameters: $this->parametersFor($message),
                    guardianId: $this->guardianIdFor($message),
                    outboxMessageId: (int) $message->getKey(),
                )
                : $this->send->text(
                    to: $message->recipient,
                    body: $message->body,
                    guardianId: $this->guardianIdFor($message),
                    outboxMessageId: (int) $message->getKey(),
                );
        } catch (WhatsAppNotConfiguredException $e) {
            return DriverResult::disabled($e->getMessage());
        }

        return match ($log->status) {
            WhatsAppDeliveryStatus::Sent => DriverResult::sent(),
            WhatsAppDeliveryStatus::Refused => DriverResult::disabled($log->error_message ?? 'Refused.'),
            WhatsAppDeliveryStatus::Failed => DriverResult::failed($log->error_message ?? 'Failed.'),
        };
    }

    /**
     * The outbox row's payload may name the approved template to use, so a
     * caller that knows (a fee reminder) can pick one without this driver
     * needing to know anything about fees.
     */
    private function templateFor(OutboxMessage $message): ?string
    {
        $name = $message->payload['whatsapp_template'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Ordered {{1}}, {{2}}... values for the template body.
     *
     * @return list<string>
     */
    private function parametersFor(OutboxMessage $message): array
    {
        $params = $message->payload['whatsapp_parameters'] ?? null;

        if (! is_array($params)) {
            // No explicit variables: the rendered body is the single
            // substitution, which is what a one-variable "{{1}}" notification
            // template wants and is already merge-field rendered.
            return [$message->body];
        }

        return array_values(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $params,
        ));
    }

    /**
     * `subject_type`/`subject_id` are the outbox's free-string tag for who a
     * message is ABOUT (never a polymorphic relation - 00-core 6.2), so this
     * reads the guardian id without touching Guardians' models.
     */
    private function guardianIdFor(OutboxMessage $message): ?int
    {
        return $message->subject_type === 'guardian' ? $message->subject_id : null;
    }
}
