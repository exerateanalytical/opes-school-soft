<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Domain\WhatsAppDeliveryStatus;
use App\Modules\Communication\Domain\WhatsAppMessageType;
use App\Modules\Communication\Models\WhatsAppDeliveryLog;
use App\Modules\Communication\Support\WhatsApp\WhatsAppConfig;
use App\Modules\Communication\Support\WhatsApp\WhatsAppNotConfiguredException;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands ONE message to Meta's WhatsApp Business Cloud API.
 *
 * Two things about this Action are deliberate and worth defending:
 *
 * 1. It THROWS when the channel is not configured, rather than returning a
 *    quiet "nothing to do". Everywhere else in this codebase an unconfigured
 *    channel is a normal outcome (DriverResult::disabled), and that stays
 *    true at the OUTBOX level - WhatsAppDriver converts this exception back
 *    into exactly that value. But a caller reaching this Action directly has
 *    asked for a specific parent to be messaged now, and answering "fine"
 *    would let a school believe a parent was told something nobody told
 *    them. Every refusal is logged before it is thrown, so the evidence
 *    exists whether or not the caller catches it.
 *
 * 2. It writes a delivery-log row on EVERY path, including the refusals that
 *    never reached the network. See WhatsAppDeliveryLog for why.
 *
 * The Meta reality this encodes: free-form text is only delivered inside the
 * 24-hour customer service window (within 24h of that parent last writing to
 * the school). A school-initiated message - which is nearly all of them -
 * needs a template approved in the Meta dashboard, or Meta rejects it with
 * error 131047. `text()` is therefore the exception here, not the default,
 * and it does not pretend otherwise.
 */
final class SendWhatsAppMessage
{
    public function __construct(private readonly WhatsAppConfig $config) {}

    /**
     * Free-form text. Delivered ONLY inside the 24h service window; outside
     * it Meta returns 131047 and this records a failure, which is the honest
     * outcome rather than a silent non-delivery.
     *
     * @param  string  $to  any format - normalised through Guardians' PhoneNumber
     */
    public function text(
        string $to,
        string $body,
        ?int $guardianId = null,
        ?int $outboxMessageId = null,
    ): WhatsAppDeliveryLog {
        return $this->send(
            to: $to,
            type: WhatsAppMessageType::Text,
            payloadBuilder: static fn (string $recipient): array => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                // preview_url false: a fee reminder containing a link must
                // not silently pull a preview card off the school's site.
                'text' => ['preview_url' => false, 'body' => $body],
            ],
            guardianId: $guardianId,
            outboxMessageId: $outboxMessageId,
        );
    }

    /**
     * An approved template - the only shape that can OPEN a conversation.
     *
     * @param  list<string>  $parameters  ordered {{1}}, {{2}}... body variables,
     *                                    which must match the approved template's
     *                                    variable count or Meta rejects the send
     */
    public function template(
        string $to,
        string $templateName,
        array $parameters = [],
        ?string $language = null,
        ?int $guardianId = null,
        ?int $outboxMessageId = null,
    ): WhatsAppDeliveryLog {
        $language ??= $this->config->defaultTemplateLanguage();

        return $this->send(
            to: $to,
            type: WhatsAppMessageType::Template,
            payloadBuilder: static function (string $recipient) use ($templateName, $parameters, $language): array {
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => $language],
                    ],
                ];

                if ($parameters !== []) {
                    $payload['template']['components'] = [[
                        'type' => 'body',
                        'parameters' => array_map(
                            static fn (string $value): array => ['type' => 'text', 'text' => $value],
                            $parameters,
                        ),
                    ]];
                }

                return $payload;
            },
            guardianId: $guardianId,
            outboxMessageId: $outboxMessageId,
            templateName: $templateName,
            templateLanguage: $language,
        );
    }

    /**
     * @param  callable(string): array<string, mixed>  $payloadBuilder
     */
    private function send(
        string $to,
        WhatsAppMessageType $type,
        callable $payloadBuilder,
        ?int $guardianId,
        ?int $outboxMessageId,
        ?string $templateName = null,
        ?string $templateLanguage = null,
    ): WhatsAppDeliveryLog {
        // Gated for humans only, matching DispatchOutbox: the scheduler and
        // the queue worker have no authenticated user, and the gate exists to
        // protect the button in the UI, not the cron.
        if (auth()->check()) {
            Gate::authorize(Permission::CommunicationSend->value);
        }

        $base = [
            'guardian_id' => $guardianId,
            'outbox_message_id' => $outboxMessageId,
            'message_type' => $type,
            'template_name' => $templateName,
            'template_language' => $templateLanguage,
            'created_by' => auth()->id(),
        ];

        // Refusals first, and each one is RECORDED before it is thrown. An
        // exception that leaves no trace would mean the one situation a
        // school most needs explained - "why did nobody get this?" - is the
        // one situation with nothing in the log.
        try {
            $this->config->assertConfigured();
            $recipient = WhatsAppConfig::toMetaRecipient($to);
            $endpoint = $this->config->messagesEndpoint();
        } catch (WhatsAppNotConfiguredException $e) {
            $this->record($base, [
                // The raw string, truncated: there is no normalised form to
                // record when normalisation is what failed.
                'recipient_phone' => mb_substr(trim($to), 0, 24),
                'status' => WhatsAppDeliveryStatus::Refused,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('opes.whatsapp.refused', [
                'guardian_id' => $guardianId,
                'outbox_message_id' => $outboxMessageId,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        $base['recipient_phone'] = $recipient;

        try {
            $response = Http::withToken((string) $this->config->accessToken())
                ->timeout($this->config->timeout())
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payloadBuilder($recipient));
        } catch (ConnectionException $e) {
            // The school's uplink, not Meta. Failed, not refused: this one is
            // worth retrying, and the outbox's attempt counter will.
            return $this->record($base, [
                'status' => WhatsAppDeliveryStatus::Failed,
                'error_message' => mb_substr('Could not reach WhatsApp: '.$e->getMessage(), 0, 500),
            ]);
        }

        /** @var array<string, mixed> $json */
        $json = (array) ($response->json() ?? []);

        if ($response->failed()) {
            /** @var array<string, mixed> $error */
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];

            $message = is_string($error['message'] ?? null)
                ? $error['message']
                : 'WhatsApp rejected the message (HTTP '.$response->status().').';

            // 131047 is the one an office will meet most: the parent has not
            // written in for 24h, so only an approved template gets through.
            // Saying so here saves a support call that would otherwise arrive
            // as "WhatsApp is broken".
            $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

            if ($code === 131047) {
                $message .= ' (Outside the 24-hour service window - this parent '
                    .'has not messaged the school recently, so only an APPROVED '
                    .'template can reach them.)';
            }

            Log::warning('opes.whatsapp.failed', [
                'guardian_id' => $guardianId,
                'outbox_message_id' => $outboxMessageId,
                'http_status' => $response->status(),
                'error_code' => $code,
                'error' => $message,
            ]);

            return $this->record($base, [
                'status' => WhatsAppDeliveryStatus::Failed,
                'http_status' => $response->status(),
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, 500),
            ]);
        }

        // Meta's success shape: {"messages":[{"id":"wamid.HBg..."}]}.
        $messages = is_array($json['messages'] ?? null) ? $json['messages'] : [];
        $first = is_array($messages[0] ?? null) ? $messages[0] : [];
        $providerId = is_string($first['id'] ?? null) ? $first['id'] : null;

        return $this->record($base, [
            'status' => WhatsAppDeliveryStatus::Sent,
            'http_status' => $response->status(),
            'provider_message_id' => $providerId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $outcome
     */
    private function record(array $base, array $outcome): WhatsAppDeliveryLog
    {
        $attributes = [...$base, ...$outcome];

        try {
            return WhatsAppDeliveryLog::query()->create($attributes);
        } catch (Throwable $e) {
            // The log table being unwritable must not turn a delivered
            // message into an exception the caller reads as "not sent". The
            // send already happened; losing the receipt is the lesser harm,
            // and it goes to the application log so it is not lost silently.
            Log::error('opes.whatsapp.log_write_failed', [
                'error' => $e->getMessage(),
                'attributes' => $attributes,
            ]);

            return (new WhatsAppDeliveryLog)->forceFill($attributes);
        }
    }
}
