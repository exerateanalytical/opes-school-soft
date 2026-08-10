<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Domain\MessageStatus;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Models\OutboxMessage;
use App\Modules\Communication\Support\MergeFields;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * THE DOOR. Every other module queues messages through this one Action -
 * it is the API the Library / HR / Assets "degrades to a queued outbox"
 * comments were waiting for (00-core 3, 05-hr-payroll 11.3,
 * 06-assets-stores 10.4).
 *
 * The signature is deliberately small and meant to stay stable:
 *
 *     $queue->fromTemplate('FEE-REMINDER', $recipients, $variables, $actor);
 *     $queue->raw(MessageChannel::Sms, $recipients, 'body', $actor);
 *
 * A recipient is ['address' => '+2376...', 'language' => 'fr',
 * 'subject_type' => 'guardian', 'subject_id' => 12] - the last three are
 * optional. `subject_type`/`subject_id` stay a bare tag + id on purpose: a
 * real relation would mean importing another module's Models (00-core 6.2).
 *
 * Nothing here can fail the caller's own work for transport reasons: the
 * only refusals are programmer errors (no recipients, unknown template,
 * placeholder the template never declared). Delivery problems are the
 * dispatcher's business and land in a row's status, never in an exception
 * thrown at the fee desk.
 *
 * Callers running unattended (jobs, commands) pass Actor::system().
 */
final class QueueMessage
{
    /**
     * Queue a template-rendered message to one or more recipients.
     *
     * @param  string  $templateCode  case-SENSITIVE, e.g. 'FEE-REMINDER'
     * @param  list<array<string, mixed>>|array<string, mixed>  $recipients  one recipient array or a list of them
     * @param  array<string, mixed>  $variables  merge-field values; may be nested per address (see $perRecipient)
     * @param  array<string, mixed>|null  $payload  extra audit/debug data stored on every row
     * @return list<OutboxMessage>
     */
    public function fromTemplate(
        string $templateCode,
        array $recipients,
        array $variables,
        Actor $actor,
        ?array $payload = null,
        ?Carbon $queuedAt = null,
    ): array {
        // Gated for humans; an unattended caller (a queue worker, the
        // scheduler) has no authenticated user to check and passes
        // Actor::system() instead - see DispatchOutbox for the same rule.
        if (auth()->check()) {
            Gate::authorize(Permission::CommunicationSend->value);
        }

        /** @var MessageTemplate|null $template */
        $template = MessageTemplate::query()->where('code', $templateCode)->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'template' => "No message template carries the code '{$templateCode}'.",
            ]);
        }

        if (! $template->is_active) {
            throw ValidationException::withMessages([
                'template' => "The template '{$templateCode}' is deactivated and may not be sent.",
            ]);
        }

        return $this->write($template, $template->channel, $recipients, $variables, null, $actor, $payload, $queuedAt);
    }

    /**
     * Queue an ad-hoc message with no template behind it.
     *
     * @param  list<array<string, mixed>>|array<string, mixed>  $recipients
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>|null  $payload
     * @return list<OutboxMessage>
     */
    public function raw(
        MessageChannel $channel,
        array $recipients,
        string $body,
        Actor $actor,
        ?string $subjectLine = null,
        array $variables = [],
        ?array $payload = null,
        ?Carbon $queuedAt = null,
    ): array {
        // Gated for humans; an unattended caller (a queue worker, the
        // scheduler) has no authenticated user to check and passes
        // Actor::system() instead - see DispatchOutbox for the same rule.
        if (auth()->check()) {
            Gate::authorize(Permission::CommunicationSend->value);
        }

        if (trim($body) === '') {
            throw ValidationException::withMessages([
                'body' => 'An outbox message needs a body.',
            ]);
        }

        return $this->write(
            null,
            $channel,
            $recipients,
            $variables,
            ['body' => $body, 'subject' => $subjectLine],
            $actor,
            $payload,
            $queuedAt,
        );
    }

    /**
     * @param  list<array<string, mixed>>|array<string, mixed>  $recipients
     * @param  array<string, mixed>  $variables
     * @param  array{body: string, subject: string|null}|null  $rawText
     * @param  array<string, mixed>|null  $payload
     * @return list<OutboxMessage>
     */
    private function write(
        ?MessageTemplate $template,
        MessageChannel $channel,
        array $recipients,
        array $variables,
        ?array $rawText,
        Actor $actor,
        ?array $payload,
        ?Carbon $queuedAt,
    ): array {
        $normalised = $this->normaliseRecipients($recipients);

        if ($normalised === []) {
            throw ValidationException::withMessages([
                'recipients' => 'A message needs at least one recipient address.',
            ]);
        }

        $when = $queuedAt ?? Carbon::now();

        return DB::transaction(function () use (
            $template, $channel, $normalised, $variables, $rawText, $actor, $payload, $when
        ): array {
            $written = [];

            foreach ($normalised as $recipient) {
                $values = array_merge($variables, $recipient['variables']);

                $language = $recipient['language'];

                $bodySource = $template !== null
                    ? $template->bodyFor($language)
                    : (string) ($rawText['body'] ?? '');

                $subjectSource = $template !== null
                    ? $template->subjectFor($language)
                    : ($rawText['subject'] ?? null);

                $body = MergeFields::render($bodySource, $values);
                $subjectLine = $subjectSource === null
                    ? null
                    : MergeFields::render($subjectSource, $values);

                // The unresolved placeholders are recorded rather than
                // thrown: the message still tells the parent something, and
                // the outbox screen shows exactly which field was missing.
                $unresolved = MergeFields::missing($bodySource, $values);

                $rowPayload = $payload ?? [];
                $rowPayload['variables'] = $values;

                if ($unresolved !== []) {
                    $rowPayload['unresolved_variables'] = $unresolved;
                }

                $written[] = OutboxMessage::query()->create([
                    'channel' => $channel->value,
                    'recipient' => $recipient['address'],
                    'subject_type' => $recipient['subject_type'],
                    'subject_id' => $recipient['subject_id'],
                    'message_template_id' => $template?->getKey(),
                    'language' => $language,
                    'subject_line' => $channel->usesSubjectLine() ? $subjectLine : null,
                    'body' => $body,
                    'payload' => $rowPayload,
                    'status' => MessageStatus::Queued->value,
                    'attempts' => 0,
                    'queued_at' => $when,
                    'created_by' => $actor->id,
                ]);
            }

            return $written;
        });
    }

    /**
     * Accepts a single recipient array, a list of them, or a plain list of
     * address strings - whichever is least annoying at the call site.
     *
     * @param  list<array<string, mixed>>|array<string, mixed>  $recipients
     * @return list<array{address: string, language: string, subject_type: string|null, subject_id: int|null, variables: array<string, mixed>}>
     */
    private function normaliseRecipients(array $recipients): array
    {
        // One recipient passed as a bare associative array.
        if (array_key_exists('address', $recipients)) {
            $recipients = [$recipients];
        }

        $out = [];

        foreach ($recipients as $entry) {
            if (is_string($entry)) {
                $entry = ['address' => $entry];
            }

            if (! is_array($entry)) {
                continue;
            }

            $address = trim((string) ($entry['address'] ?? ''));

            if ($address === '') {
                throw ValidationException::withMessages([
                    'recipients' => 'A recipient with no address cannot be queued.',
                ]);
            }

            if (mb_strlen($address) > 160) {
                throw ValidationException::withMessages([
                    'recipients' => "The address '{$address}' exceeds the 160-character column.",
                ]);
            }

            $language = strtolower(trim((string) ($entry['language'] ?? 'en')));

            if (! in_array($language, ['en', 'fr'], true)) {
                $language = 'en';
            }

            $subjectType = $entry['subject_type'] ?? null;
            $subjectId = $entry['subject_id'] ?? null;

            /** @var array<string, mixed> $ownVariables */
            $ownVariables = is_array($entry['variables'] ?? null) ? $entry['variables'] : [];

            $out[] = [
                'address' => $address,
                'language' => $language,
                'subject_type' => $subjectType === null ? null : mb_substr((string) $subjectType, 0, 60),
                'subject_id' => $subjectId === null ? null : (int) $subjectId,
                'variables' => $ownVariables,
            ];
        }

        return $out;
    }
}
