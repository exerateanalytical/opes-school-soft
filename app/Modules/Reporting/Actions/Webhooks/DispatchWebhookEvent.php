<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions\Webhooks;

use App\Modules\Reporting\Domain\WebhookDeliveryStatus;
use App\Modules\Reporting\Models\WebhookDelivery;
use App\Modules\Reporting\Models\WebhookEndpoint;

/**
 * Fans an event out to every active endpoint subscribed to it (or to `*`,
 * the wildcard-all subscription), queuing one pending delivery row per
 * match. No permission gate: this is called by other Actions after their
 * OWN write already happened and already passed its own authorization -
 * dispatching a webhook is a side effect of a change that was already
 * allowed, not a fresh privileged act.
 *
 * Queues only; DeliverPendingWebhooks does the actual signed HTTP send on
 * its own schedule, the same split DispatchOutbox uses for messages.
 */
final class DispatchWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $event, array $payload): int
    {
        $endpoints = WebhookEndpoint::query()->where('is_active', true)->get();

        $queued = 0;

        foreach ($endpoints as $endpoint) {
            $subscribed = in_array($event, $endpoint->events, true) || in_array('*', $endpoint->events, true);

            if (! $subscribed) {
                continue;
            }

            WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->getKey(),
                'event' => $event,
                'payload' => $payload,
                'attempts' => 0,
                'status' => WebhookDeliveryStatus::Pending->value,
            ]);

            $queued++;
        }

        return $queued;
    }
}
