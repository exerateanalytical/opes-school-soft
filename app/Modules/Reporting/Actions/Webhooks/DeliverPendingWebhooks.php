<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions\Webhooks;

use App\Modules\Reporting\Domain\WebhookDeliveryStatus;
use App\Modules\Reporting\Models\WebhookDelivery;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Drains due webhook deliveries: sign, POST, record the outcome.
 *
 * Signature: `X-OPES-Signature: sha256=<hex hmac>` over
 * `"{timestamp}.{raw json body}"` using the endpoint's secret, plus
 * `X-OPES-Timestamp`, the same construction Stripe's webhook signing uses -
 * binding the timestamp into the signed material is what lets a receiver
 * reject an old, replayed delivery even though this server has no way to
 * enforce that on the RECEIVING end itself.
 *
 * Uses Laravel's `Http` facade rather than a raw Guzzle client - the
 * convention this codebase already uses for outbound HTTP
 * (LicenceVerificationTest fakes it via `Http::fake()`), and the only way
 * this Action's actual delivery outcome is fakeable in a test at all.
 *
 * Same claim-then-spend discipline as DispatchOutbox: each row is locked
 * and its attempt count incremented BEFORE the HTTP call, so an overlapping
 * run cannot double-send and an endpoint that always errors cannot loop
 * forever - it burns through MAX_ATTEMPTS and lands on `exhausted`.
 */
final class DeliverPendingWebhooks
{
    private const MAX_ATTEMPTS = 6;

    private const TIMEOUT_SECONDS = 10;

    /**
     * @return array{considered: int, delivered: int, failed: int, exhausted: int}
     */
    public function handle(int $limit = 100): array
    {
        $tally = ['considered' => 0, 'delivered' => 0, 'failed' => 0, 'exhausted' => 0];

        $dueIds = WebhookDelivery::query()
            ->whereIn('status', [WebhookDeliveryStatus::Pending->value, WebhookDeliveryStatus::Failed->value])
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($dueIds as $id) {
            $outcome = $this->deliverOne((int) $id);

            if ($outcome === null) {
                continue;
            }

            $tally['considered']++;
            $tally[$outcome]++;
        }

        return $tally;
    }

    private function deliverOne(int $deliveryId): ?string
    {
        return DB::transaction(function () use ($deliveryId): ?string {
            /** @var WebhookDelivery|null $delivery */
            $delivery = WebhookDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($delivery === null || $delivery->status === WebhookDeliveryStatus::Delivered) {
                return null;
            }

            $endpoint = $delivery->endpoint()->lockForUpdate()->first();

            if ($endpoint === null || ! $endpoint->is_active) {
                $delivery->forceFill(['status' => WebhookDeliveryStatus::Exhausted->value])->save();

                return 'exhausted';
            }

            // Spend the attempt BEFORE the network call: if the process
            // dies mid-request, the retry sees a used attempt rather than
            // repeating it for free on the next run.
            $delivery->attempts++;

            $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

            try {
                $response = Http::withBody($body, 'application/json')
                    ->withHeaders([
                        'X-OPES-Event' => $delivery->event,
                        'X-OPES-Timestamp' => $timestamp,
                        'X-OPES-Signature' => 'sha256='.$signature,
                    ])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->throw()
                    ->post($endpoint->url);

                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Delivered->value,
                    'response_code' => $response->status(),
                    'response_body' => mb_substr($response->body(), 0, 2000),
                    'delivered_at' => now(),
                    'next_retry_at' => null,
                ])->save();

                return 'delivered';
            } catch (Throwable $e) {
                // Only a RequestException (a non-2xx response, since
                // withThrow()) carries a $response; a ConnectionException
                // (DNS failure, timeout, refused connection - no response
                // was ever received) does not, and $e->response would be
                // undefined-property access on it.
                $responseCode = $e instanceof RequestException ? $e->response->status() : null;

                if ($delivery->attempts >= self::MAX_ATTEMPTS) {
                    $delivery->forceFill([
                        'status' => WebhookDeliveryStatus::Exhausted->value,
                        'response_code' => $responseCode,
                        'response_body' => mb_substr($e->getMessage(), 0, 2000),
                        'next_retry_at' => null,
                    ])->save();

                    return 'exhausted';
                }

                // Exponential backoff, capped at an hour: 2, 4, 8, 16,
                // 32 minutes, then hourly.
                $delayMinutes = min(2 ** $delivery->attempts, 60);

                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Failed->value,
                    'response_code' => $responseCode,
                    'response_body' => mb_substr($e->getMessage(), 0, 2000),
                    'next_retry_at' => now()->addMinutes($delayMinutes),
                ])->save();

                return 'failed';
            }
        });
    }
}
