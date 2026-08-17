<?php

declare(strict_types=1);

use App\Modules\Communication\Actions\SendWhatsAppMessage;
use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Domain\MessageStatus;
use App\Modules\Communication\Domain\WhatsAppDeliveryStatus;
use App\Modules\Communication\Domain\WhatsAppMessageType;
use App\Modules\Communication\Jobs\SendWhatsAppMessageJob;
use App\Modules\Communication\Livewire\WhatsAppSettings;
use App\Modules\Communication\Models\OutboxMessage;
use App\Modules\Communication\Models\WhatsAppDeliveryLog;
use App\Modules\Communication\Support\WhatsApp\WhatsAppConfig;
use App\Modules\Communication\Support\WhatsApp\WhatsAppNotConfiguredException;
use App\Modules\Communication\Support\WhatsAppDriver;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * NOTE ON CREDENTIALS: every token and phone number id below is an obvious
 * test fixture ('test-token-not-real', '000000000000000'). Nothing here is a
 * real Meta credential and none may ever be committed - the whole design of
 * this channel is that it stays inert until a school supplies its own.
 */
function whatsappConfigured(): void
{
    config()->set('whatsapp.enabled', true);
    config()->set('whatsapp.access_token', 'test-token-not-real');
    config()->set('whatsapp.phone_number_id', '000000000000000');
    config()->set('whatsapp.api_version', 'v21.0');
    config()->set('whatsapp.base_url', 'https://graph.facebook.com');

    // The seeded `whatsapp.enabled` row is authoritative once it exists, so
    // config() alone cannot switch the channel on. Written directly rather
    // than through WriteSetting because these tests have no acting user and
    // WriteSetting demands an audit actor.
    DB::table('settings')
        ->where('key', WhatsAppConfig::SETTING_ENABLED)
        ->where('scope', 'global')
        ->update(['value' => json_encode(true, JSON_THROW_ON_ERROR)]);

    Cache::forget(ReadSetting::cacheKey(WhatsAppConfig::SETTING_ENABLED, 'global', null));
}

function whatsappAdmin(): User
{
    (new RolePermissionSeeder)->run();

    // Administrator, not Principal: the Proviseur holds SettingView only, so
    // gating this screen on SettingEdit deliberately keeps him out of the
    // credential store. Asserting that here stops the screen from drifting
    // onto a permission no seeded role actually grants.
    $user = User::factory()->create();
    $user->assignRole(Role::Administrator->value);

    $user = $user->fresh() ?? $user;
    actingAs($user);

    return $user;
}

it('refuses to send when no credentials are configured, and says so in words', function () {
    // The default state of a fresh instance: no token, no phone number id.
    Http::fake();

    expect(fn () => app(SendWhatsAppMessage::class)->text('677123456', 'Fees are due.'))
        ->toThrow(
            WhatsAppNotConfiguredException::class,
            'WhatsApp is not configured',
        );

    // The refusal must never be silent about WHICH settings are missing.
    try {
        app(SendWhatsAppMessage::class)->text('677123456', 'Fees are due.');
    } catch (WhatsAppNotConfiguredException $e) {
        expect($e->getMessage())
            ->toContain('WHATSAPP_ACCESS_TOKEN')
            ->toContain('WHATSAPP_PHONE_NUMBER_ID')
            ->toContain('No message was sent.');
    }

    // Nothing may reach Meta on the unconfigured path.
    Http::assertNothingSent();
});

it('records every refusal in the delivery log, so an unsent message leaves evidence', function () {
    Http::fake();

    try {
        app(SendWhatsAppMessage::class)->text('677123456', 'Fees are due.', guardianId: 42);
    } catch (WhatsAppNotConfiguredException) {
        // expected
    }

    $log = WhatsAppDeliveryLog::query()->sole();

    expect($log->status)->toBe(WhatsAppDeliveryStatus::Refused)
        ->and($log->guardian_id)->toBe(42)
        ->and($log->provider_message_id)->toBeNull()
        ->and($log->error_message)->toContain('WhatsApp is not configured');
});

it('refuses when credentials exist but the channel is switched off', function () {
    whatsappConfigured();

    // The stored row wins even over WHATSAPP_ENABLED=true in .env - an
    // operator hitting the off switch must actually stop the sending.
    config()->set('whatsapp.enabled', true);

    DB::table('settings')
        ->where('key', WhatsAppConfig::SETTING_ENABLED)
        ->where('scope', 'global')
        ->update(['value' => json_encode(false, JSON_THROW_ON_ERROR)]);

    Cache::forget(ReadSetting::cacheKey(WhatsAppConfig::SETTING_ENABLED, 'global', null));

    Http::fake();

    expect(fn () => app(SendWhatsAppMessage::class)->text('677123456', 'Hello'))
        ->toThrow(WhatsAppNotConfiguredException::class, 'the channel is switched off');

    Http::assertNothingSent();
});

it('posts a text message to the Meta graph endpoint with the documented payload', function () {
    whatsappConfigured();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.TEST123']],
        ], 200),
    ]);

    $log = app(SendWhatsAppMessage::class)->text('677123456', 'Fees are due.', guardianId: 7);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/000000000000000/messages'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token-not-real')
            && $request['messaging_product'] === 'whatsapp'
            && $request['type'] === 'text'
            // E.164 with the + stripped, which is the form Meta requires.
            && $request['to'] === '237677123456'
            && $request['text']['body'] === 'Fees are due.'
            && $request['text']['preview_url'] === false;
    });

    expect($log->status)->toBe(WhatsAppDeliveryStatus::Sent)
        ->and($log->message_type)->toBe(WhatsAppMessageType::Text)
        ->and($log->provider_message_id)->toBe('wamid.TEST123')
        ->and($log->guardian_id)->toBe(7)
        ->and($log->recipient_phone)->toBe('237677123456');
});

it('posts a template message in Meta\'s template shape with ordered body parameters', function () {
    whatsappConfigured();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200),
    ]);

    app(SendWhatsAppMessage::class)->template(
        to: '+237677123456',
        templateName: 'fee_reminder',
        parameters: ['Ngu Awa', '45 000 FCFA'],
        language: 'fr',
    );

    Http::assertSent(function ($request) {
        return $request['type'] === 'template'
            && $request['template']['name'] === 'fee_reminder'
            && $request['template']['language']['code'] === 'fr'
            && $request['template']['components'][0]['type'] === 'body'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Ngu Awa'
            && $request['template']['components'][0]['parameters'][1]['text'] === '45 000 FCFA';
    });
});

it('normalises Cameroonian phone numbers to E.164 the way the guardian matcher does', function () {
    whatsappConfigured();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]], 200)]);

    // The four spellings 07-students 7.7 names, which must all reach one
    // number - a second parser in this module would eventually disagree with
    // Guardians' and send to a number no guardian record matches.
    foreach (['677 12 34 56', '+237677123456', '00237 677123456', '0677123456'] as $spelling) {
        app(SendWhatsAppMessage::class)->text($spelling, 'x');
    }

    $recipients = WhatsAppDeliveryLog::query()->pluck('recipient_phone')->unique()->values()->all();

    expect($recipients)->toBe(['237677123456']);
});

it('refuses a phone number with no usable digits rather than posting nonsense', function () {
    whatsappConfigured();
    Http::fake();

    expect(fn () => app(SendWhatsAppMessage::class)->text('n/a', 'x'))
        ->toThrow(WhatsAppNotConfiguredException::class, 'no usable digits');

    Http::assertNothingSent();
});

it('logs a failed Meta response with its error code and message', function () {
    whatsappConfigured();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
        ], 401),
    ]);

    $log = app(SendWhatsAppMessage::class)->text('677123456', 'x');

    expect($log->status)->toBe(WhatsAppDeliveryStatus::Failed)
        ->and($log->http_status)->toBe(401)
        ->and($log->error_code)->toBe(190)
        ->and($log->error_message)->toContain('Invalid OAuth access token.')
        ->and($log->provider_message_id)->toBeNull();
});

it('explains the 24-hour service window when Meta rejects free text with 131047', function () {
    whatsappConfigured();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Re-engagement message', 'code' => 131047],
        ], 400),
    ]);

    $log = app(SendWhatsAppMessage::class)->text('677123456', 'x');

    // The point: an office reading this log must not conclude "WhatsApp is
    // broken" when the truth is "you need an approved template".
    expect($log->error_message)->toContain('24-hour service window')
        ->and($log->error_message)->toContain('template');
});

it('keeps the delivery log append-only', function () {
    whatsappConfigured();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]], 200)]);

    $log = app(SendWhatsAppMessage::class)->text('677123456', 'x');

    expect(function () use ($log) {
        $log->error_message = 'rewritten';
        $log->save();
    })->toThrow(RuntimeException::class, 'append-only');

    // Immutable10Year: a fresh row is nowhere near ten years old.
    expect(fn () => $log->fresh()?->delete())->toThrow(RuntimeException::class);
});

it('sends through a queued job so a slow Meta call never blocks a request', function () {
    Bus::fake();

    SendWhatsAppMessageJob::dispatch('677123456', 'Fees are due.');

    Bus::assertDispatched(SendWhatsAppMessageJob::class, function (SendWhatsAppMessageJob $job) {
        return $job->to === '677123456' && $job->body === 'Fees are due.';
    });
});

it('does not fail the job when the channel is unconfigured', function () {
    // One failed_jobs row per parent, on an instance whose only problem is an
    // empty token, would bury the real failures.
    Http::fake();

    $job = new SendWhatsAppMessageJob('677123456', 'x');

    $job->handle(app(SendWhatsAppMessage::class));

    expect(WhatsAppDeliveryLog::query()->sole()->status)->toBe(WhatsAppDeliveryStatus::Refused);
});

it('plugs into the existing outbox driver seam without changing the outbox', function () {
    whatsappConfigured();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    $message = OutboxMessage::query()->create([
        'channel' => MessageChannel::WhatsApp,
        'recipient' => '677123456',
        'subject_type' => 'guardian',
        'subject_id' => 12,
        'language' => 'en',
        'body' => 'Term two fees are due on Friday.',
        'status' => MessageStatus::Queued,
        'attempts' => 0,
        'queued_at' => now(),
    ]);

    $result = app(WhatsAppDriver::class)->send($message);

    expect($result->status)->toBe(MessageStatus::Sent);

    $log = WhatsAppDeliveryLog::query()->sole();
    expect($log->guardian_id)->toBe(12)
        ->and($log->outbox_message_id)->toBe((int) $message->getKey());
});

it('reports an unconfigured channel to the outbox as disabled rather than aborting the run', function () {
    // 00-core 3: degrades to a queued outbox, never a blocking error. A
    // nightly run over 300 reminders must not die on the first row.
    Http::fake();

    $message = OutboxMessage::query()->create([
        'channel' => MessageChannel::WhatsApp,
        'recipient' => '677123456',
        'language' => 'en',
        'body' => 'x',
        'status' => MessageStatus::Queued,
        'attempts' => 0,
        'queued_at' => now(),
    ]);

    $result = app(WhatsAppDriver::class)->send($message);

    expect($result->status)->toBe(MessageStatus::Disabled)
        ->and($result->reason)->toContain('not configured');
});

it('gates the admin screen on setting.edit', function () {
    (new RolePermissionSeeder)->run();

    $teacher = User::factory()->create();
    $teacher->assignRole(Role::Teacher->value);
    actingAs($teacher->fresh() ?? $teacher);

    get('/settings/whatsapp')->assertForbidden();
});

it('lets an administrator save credentials and reports the channel connected', function () {
    whatsappAdmin();

    // Nothing configured yet: the screen must say so plainly.
    Livewire::test(WhatsAppSettings::class)
        ->assertSee('not connected')
        ->set('accessToken', 'pasted-token-not-real')
        ->set('phoneNumberId', '111222333444555')
        ->set('enabled', true)
        ->call('save')
        ->assertDispatched('settings-saved');

    // Stored in the audited settings store and preferred over .env, so a
    // principal can rotate a token without shell access.
    $config = app(WhatsAppConfig::class);

    expect($config->isConfigured())->toBeTrue()
        ->and($config->accessToken())->toBe('pasted-token-not-real')
        ->and($config->phoneNumberId())->toBe('111222333444555')
        ->and($config->missingReason())->toBeNull();
});

it('never renders a saved access token back to the page', function () {
    whatsappAdmin();

    Livewire::test(WhatsAppSettings::class)
        ->set('accessToken', 'super-secret-not-real')
        ->set('phoneNumberId', '111222333444555')
        ->set('enabled', true)
        ->call('save')
        // Re-hydrated after save: the field is blank and only a flag says a
        // token exists. This screen gets shown on a projector.
        ->assertSet('accessToken', '')
        ->assertSet('tokenIsSet', true)
        ->assertDontSee('super-secret-not-real');
});

it('refuses to switch the channel on without both credentials', function () {
    whatsappAdmin();

    Livewire::test(WhatsAppSettings::class)
        ->set('enabled', true)
        ->set('phoneNumberId', '')
        ->call('save')
        ->assertHasErrors('enabled');

    expect(app(WhatsAppConfig::class)->isConfigured())->toBeFalse();
});

it('switches the channel off when the token is cleared', function () {
    whatsappAdmin();

    $component = Livewire::test(WhatsAppSettings::class)
        ->set('accessToken', 'leaked-token-not-real')
        ->set('phoneNumberId', '111222333444555')
        ->set('enabled', true)
        ->call('save');

    expect(app(WhatsAppConfig::class)->isConfigured())->toBeTrue();

    $component->call('clearToken')->assertSet('tokenIsSet', false);

    // A channel with no token cannot send, so leaving it "on" would
    // misreport the instance as connected.
    expect(app(WhatsAppConfig::class)->isConfigured())->toBeFalse();
});

it('shows recent delivery attempts with the phone number masked', function () {
    whatsappAdmin();
    whatsappConfigured();

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SHOWN']]], 200)]);

    app(SendWhatsAppMessage::class)->text('677123456', 'x');

    Livewire::test(WhatsAppSettings::class)
        ->assertSee('wamid.SHOWN')
        // 237677123456 is 12 digits: 8 stars, then the last four, which are
        // enough for an office to recognise the parent without the full
        // number being readable over somebody's shoulder.
        ->assertSee('********3456')
        ->assertDontSee('237677123456');
});
