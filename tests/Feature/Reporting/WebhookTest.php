<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Actions\Webhooks\DeliverPendingWebhooks;
use App\Modules\Reporting\Actions\Webhooks\DispatchWebhookEvent;
use App\Modules\Reporting\Actions\Webhooks\RegisterWebhookEndpoint;
use App\Modules\Reporting\Actions\Webhooks\RevokeWebhookEndpoint;
use App\Modules\Reporting\Domain\WebhookDeliveryStatus;
use App\Modules\Reporting\Models\WebhookDelivery;
use App\Modules\Reporting\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function webhookActor(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return $user;
}

it('refuses to register a non-https endpoint', function (): void {
    $actor = webhookActor();

    expect(fn () => app(RegisterWebhookEndpoint::class)->handle(
        'Insecure', 'http://erp.example.test/hooks', ['fee.invoice_issued'], (int) $actor->getKey(),
    ))->toThrow(DomainException::class);
});

it('refuses an endpoint with no subscribed events', function (): void {
    $actor = webhookActor();

    expect(fn () => app(RegisterWebhookEndpoint::class)->handle(
        'Nothing', 'https://erp.example.test/hooks', [], (int) $actor->getKey(),
    ))->toThrow(DomainException::class);
});

it('generates a real random secret only visible at registration', function (): void {
    $actor = webhookActor();

    $endpoint = app(RegisterWebhookEndpoint::class)->handle(
        'ERP', 'https://erp.example.test/hooks', ['fee.invoice_issued'], (int) $actor->getKey(),
    );

    expect($endpoint->secret)->toHaveLength(64);
});

it('queues one delivery per matching endpoint and skips inactive or unsubscribed ones', function (): void {
    $actor = webhookActor();

    $subscribed = app(RegisterWebhookEndpoint::class)->handle('A', 'https://a.example.test/h', ['fee.invoice_issued'], (int) $actor->getKey());
    $wildcard = app(RegisterWebhookEndpoint::class)->handle('B', 'https://b.example.test/h', ['*'], (int) $actor->getKey());
    $unrelated = app(RegisterWebhookEndpoint::class)->handle('C', 'https://c.example.test/h', ['student.enrolled'], (int) $actor->getKey());
    $revoked = app(RegisterWebhookEndpoint::class)->handle('D', 'https://d.example.test/h', ['fee.invoice_issued'], (int) $actor->getKey());
    app(RevokeWebhookEndpoint::class)->handle((int) $revoked->getKey());

    $queued = app(DispatchWebhookEvent::class)->handle('fee.invoice_issued', ['invoice_id' => 1]);

    expect($queued)->toBe(2)
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $subscribed->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $wildcard->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $unrelated->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->where('webhook_endpoint_id', $revoked->getKey())->exists())->toBeFalse();
});

it('signs the delivery correctly and marks it delivered on a 2xx response', function (): void {
    $actor = webhookActor();

    $endpoint = app(RegisterWebhookEndpoint::class)->handle(
        'ERP', 'https://erp.example.test/hooks', ['fee.invoice_issued'], (int) $actor->getKey(),
    );

    app(DispatchWebhookEvent::class)->handle('fee.invoice_issued', ['invoice_id' => 7, 'amount' => 25000]);

    Http::fake(['erp.example.test/*' => Http::response('ok', 200)]);

    $tally = app(DeliverPendingWebhooks::class)->handle();

    expect($tally)->toBe(['considered' => 1, 'delivered' => 1, 'failed' => 0, 'exhausted' => 0]);

    Http::assertSent(function ($request) use ($endpoint) {
        $body = $request->body();
        $timestamp = $request->header('X-OPES-Timestamp')[0] ?? '';
        $expectedSignature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);

        return $request->hasHeader('X-OPES-Signature', $expectedSignature)
            && $request->header('X-OPES-Event')[0] === 'fee.invoice_issued';
    });

    $delivery = WebhookDelivery::query()->first();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->response_code)->toBe(200)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('retries with backoff on failure rather than exhausting on the first attempt', function (): void {
    $actor = webhookActor();

    app(RegisterWebhookEndpoint::class)->handle('ERP', 'https://erp.example.test/hooks', ['fee.invoice_issued'], (int) $actor->getKey());
    app(DispatchWebhookEvent::class)->handle('fee.invoice_issued', ['x' => 1]);

    Http::fake(['erp.example.test/*' => Http::response('server error', 500)]);

    app(DeliverPendingWebhooks::class)->handle();

    $delivery = WebhookDelivery::query()->first();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->next_retry_at->isFuture())->toBeTrue();
});

it('exhausts after MAX_ATTEMPTS rather than retrying forever', function (): void {
    $actor = webhookActor();

    app(RegisterWebhookEndpoint::class)->handle('ERP', 'https://erp.example.test/hooks', ['fee.invoice_issued'], (int) $actor->getKey());
    app(DispatchWebhookEvent::class)->handle('fee.invoice_issued', ['x' => 1]);

    Http::fake(['erp.example.test/*' => Http::response('server error', 500)]);

    $delivery = WebhookDelivery::query()->first();

    // Drive it past MAX_ATTEMPTS (6) by clearing next_retry_at between
    // rounds rather than waiting on the real clock.
    for ($i = 0; $i < 6; $i++) {
        WebhookDelivery::query()->whereKey($delivery->getKey())->update(['next_retry_at' => null]);
        app(DeliverPendingWebhooks::class)->handle();
    }

    $delivery->refresh();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Exhausted)
        ->and($delivery->attempts)->toBe(6);
});

it('does not deliver to a revoked endpoint even with a pending delivery already queued', function (): void {
    $actor = webhookActor();

    $endpoint = app(RegisterWebhookEndpoint::class)->handle('ERP', 'https://erp.example.test/hooks', ['fee.invoice_issued'], (int) $actor->getKey());
    app(DispatchWebhookEvent::class)->handle('fee.invoice_issued', ['x' => 1]);

    app(RevokeWebhookEndpoint::class)->handle((int) $endpoint->getKey());

    Http::fake(['erp.example.test/*' => Http::response('ok', 200)]);

    $tally = app(DeliverPendingWebhooks::class)->handle();

    expect($tally['exhausted'])->toBe(1)
        ->and($tally['delivered'])->toBe(0);

    Http::assertNothingSent();
});
