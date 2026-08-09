<?php

declare(strict_types=1);

use App\Modules\Identity\Models\AuditLog;
use App\Modules\Operations\Actions\Licensing\ActivateOnline;
use App\Modules\Operations\Actions\Licensing\DeactivateLicence;
use App\Modules\Operations\Actions\Licensing\ImportLicenceFile;
use App\Modules\Operations\Actions\Licensing\OpportunisticRecheck;
use App\Modules\Operations\Domain\Licensing\LicenceState;
use App\Modules\Operations\Licensing\CanonicalJson;
use App\Modules\Operations\Licensing\LicenceStatus;
use App\Modules\Operations\Licensing\MachineFingerprint;
use App\Modules\Operations\Models\Licence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/P7F4LicensingTestHelpers.php';

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Canonical JSON (§4.3): the byte form both sides sign over
// ---------------------------------------------------------------------------

it('canonicalises with ordinal key order, compact output, unescaped slashes and unicode', function () {
    $encoded = CanonicalJson::encode([
        'z' => 1,
        'a' => ['c' => null, 'b' => ['beta', 'alpha']],
        'école' => 'Collège Bilingue/Yaoundé',
        'Upper' => 10,
    ]);

    // Ordinal (byte) order puts "Upper" before the lowercase keys and keeps
    // list element order untouched; integers and null stay unquoted.
    expect($encoded)->toBe('{"Upper":10,"a":{"b":["beta","alpha"],"c":null},"z":1,"école":"Collège Bilingue/Yaoundé"}');
});

it('produces byte-identical canonical form regardless of input key order', function () {
    $a = CanonicalJson::encode(['b' => 2, 'a' => ['y' => 1, 'x' => 2]]);
    $b = CanonicalJson::encode(['a' => ['x' => 2, 'y' => 1], 'b' => 2]);

    expect($a)->toBe($b);
});

// ---------------------------------------------------------------------------
// ImportLicenceFile: the offline route (§4.2 - needs internet NEVER)
// ---------------------------------------------------------------------------

it('imports a validly signed licence file with no network call, and status reads Valid', function () {
    Http::fake();
    p7f4Manager();
    $keys = p7f4Keys();

    Carbon::setTestNow(Carbon::parse('2027-01-15 09:00:00'));

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    $licence = app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(), $keys['file']),
        $user->toAuditActor(),
    );

    expect($licence->exists)->toBeTrue();
    expect($licence->source)->toBe(Licence::SOURCE_FILE);
    expect($licence->fingerprint)->toBeNull(); // file licences are not machine-bound
    expect($licence->expires_at?->toDateString())->toBe('2027-08-31');

    $evaluation = app(LicenceStatus::class)->evaluate();
    expect($evaluation->state)->toBe(LicenceState::Valid);
    expect($evaluation->trusted)->toBeTrue();
    expect($evaluation->failureKey)->toBeNull();

    // §4.2: the licence-file route NEVER touches the network.
    Http::assertNothingSent();
});

it('refuses unreadable, incomplete, tampered, foreign-product and expiry-less files with distinct sentences', function () {
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $actor = $user->toAuditActor();
    $import = app(ImportLicenceFile::class);

    // Not JSON at all.
    expect(fn () => $import->handle('this is not json', $actor))
        ->toThrow(DomainException::class, __('licence.import.not_json'));

    // JSON but missing its signature.
    $noSignature = json_encode(['payload' => p7f4Payload()], JSON_THROW_ON_ERROR);
    expect(fn () => $import->handle($noSignature, $actor))
        ->toThrow(DomainException::class, __('licence.import.malformed'));

    // Payload altered AFTER signing: one more student on the cap.
    $payload = p7f4Payload();
    $tampered = json_encode([
        'payload' => array_merge($payload, ['student_cap' => 601]),
        'signature' => p7f4Sign($payload, $keys['file']),
    ], JSON_THROW_ON_ERROR);
    expect(fn () => $import->handle($tampered, $actor))
        ->toThrow(DomainException::class, __('licence.import.signature_invalid'));

    // Signed correctly, for someone else's product.
    expect(fn () => $import->handle(p7f4FileEnvelope(p7f4Payload(['product' => 'other-product']), $keys['file']), $actor))
        ->toThrow(DomainException::class, __('licence.import.wrong_product'));

    // Signed correctly, no readable expiry.
    expect(fn () => $import->handle(p7f4FileEnvelope(p7f4Payload(['expires_at' => null]), $keys['file']), $actor))
        ->toThrow(DomainException::class, __('licence.import.expiry_missing'));

    expect(Licence::query()->count())->toBe(0);
});

it('rejects a licence file signed with the activation key - the §4.1 key split holds', function () {
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    // A compromised activation server trying to mint an offline file.
    $forged = p7f4FileEnvelope(p7f4Payload(), $keys['activation']);

    expect(fn () => app(ImportLicenceFile::class)->handle($forged, $user->toAuditActor()))
        ->toThrow(DomainException::class, __('licence.import.signature_invalid'));
});

it('replaces the cached licence on re-import instead of stacking rows', function () {
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $actor = $user->toAuditActor();
    $import = app(ImportLicenceFile::class);

    $import->handle(p7f4FileEnvelope(p7f4Payload(['expires_at' => '2027-08-31']), $keys['file']), $actor);
    $renewed = $import->handle(p7f4FileEnvelope(p7f4Payload(['expires_at' => '2028-08-31']), $keys['file']), $actor);

    expect(Licence::query()->count())->toBe(1);
    expect($renewed->expires_at?->toDateString())->toBe('2028-08-31');
});

it('requires licence.manage to import', function () {
    p7f4Keys();

    $user = App\Modules\Identity\Models\User::factory()->create();
    Pest\Laravel\actingAs($user);

    expect(fn () => app(ImportLicenceFile::class)->handle(p7f4FileEnvelope(p7f4Payload()), $user->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// ActivateOnline: the online route (§4.2 - internet exactly ONCE)
// ---------------------------------------------------------------------------

it('activates online, caches a machine-bound licence, and keeps the key out of URLs and logs', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    $fingerprint = p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $responsePayload = p7f4Payload([
        'fingerprint' => $fingerprint,
        'next_check_after' => '2027-03-01T00:00:00Z',
    ]);

    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['activation']),
        ]),
    ]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    $licenceKey = 'OPES-AAAA-BBBB-CCCC-DDDD';
    $licence = app(ActivateOnline::class)->handle($licenceKey, $user->toAuditActor());

    expect($licence->source)->toBe(Licence::SOURCE_ACTIVATION);
    expect($licence->fingerprint)->toBe($fingerprint);
    expect($licence->next_check_after)->not->toBeNull();

    // Exactly one call; the key travels in the POST body, never the URL (§4.3).
    Http::assertSentCount(1);
    Http::assertSent(function (ClientRequest $request) use ($licenceKey): bool {
        return ! str_contains($request->url(), $licenceKey)
            && str_contains($request->body(), $licenceKey);
    });

    // The key is never logged: no audit column anywhere contains it (§4.3).
    $auditDump = json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
    expect(str_contains($auditDump, $licenceKey))->toBeFalse();

    expect(app(LicenceStatus::class)->evaluate()->trusted)->toBeTrue();
});

it('makes NO activation call when the machine fingerprint is empty - empty, never random', function () {
    Http::fake();
    p7f4Manager();
    p7f4Keys();
    config([
        'opes.licensing.activation_url' => P7F4_SERVER,
        // The machine-with-no-readable-identity case (§4.3).
        'opes.licensing.fingerprint_source' => '',
    ]);

    expect(MachineFingerprint::compute())->toBe('');

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    expect(fn () => app(ActivateOnline::class)->handle('OPES-KEY', $user->toAuditActor()))
        ->toThrow(DomainException::class, __('licence.activate.no_fingerprint'));

    Http::assertNothingSent();
});

it('surfaces invalid_key and no_seats as distinct sentences and stores nothing', function () {
    p7f4Manager();
    p7f4Keys();
    p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $actor = $user->toAuditActor();

    Http::fake([
        'licence.opes.test/*' => Http::sequence()
            ->push(['error' => 'invalid_key'], 422)
            ->push(['error' => 'no_seats'], 409),
    ]);

    expect(fn () => app(ActivateOnline::class)->handle('OPES-KEY', $actor))
        ->toThrow(DomainException::class, __('licence.activate.invalid_key'));

    expect(fn () => app(ActivateOnline::class)->handle('OPES-KEY', $actor))
        ->toThrow(DomainException::class, __('licence.activate.no_seats'));

    expect(Licence::query()->count())->toBe(0);
});

it('discards a mis-signed activation response - no "it came from our server" exemption', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    $fingerprint = p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    // Signed with the FILE key: even the "right" vendor signing with the
    // wrong key is refused (§4.1 split, §4.3 verification).
    $responsePayload = p7f4Payload(['fingerprint' => $fingerprint]);

    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['file']),
        ]),
    ]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    expect(fn () => app(ActivateOnline::class)->handle('OPES-KEY', $user->toAuditActor()))
        ->toThrow(DomainException::class, __('licence.activate.signature_invalid'));

    expect(Licence::query()->count())->toBe(0);
});

it('discards an activation response bound to a different machine', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    p7f4Fingerprint('this-machine');
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $responsePayload = p7f4Payload([
        'fingerprint' => hash('sha256', 'opes-machine-fingerprint-v1|some-other-machine'),
    ]);

    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['activation']),
        ]),
    ]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    expect(fn () => app(ActivateOnline::class)->handle('OPES-KEY', $user->toAuditActor()))
        ->toThrow(DomainException::class, __('licence.activate.fingerprint_mismatch'));

    expect(Licence::query()->count())->toBe(0);
});

it('reports an unreachable activation server plainly', function () {
    p7f4Manager();
    p7f4Keys();
    p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    Http::fake(function (): never {
        throw new ConnectionException('DNS failure');
    });

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    expect(fn () => app(ActivateOnline::class)->handle('OPES-KEY', $user->toAuditActor()))
        ->toThrow(DomainException::class, __('licence.activate.unreachable'));
});

// ---------------------------------------------------------------------------
// Offline status ladder (§4.4) and cached-row re-verification (§4.3)
// ---------------------------------------------------------------------------

it('walks valid -> expiring -> grace -> enforced purely from the signed expiry date', function () {
    Http::fake();
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(['expires_at' => '2027-08-31']), $keys['file']),
        $user->toAuditActor(),
    );

    $status = app(LicenceStatus::class);

    Carbon::setTestNow(Carbon::parse('2027-06-01'));
    expect($status->evaluate()->state)->toBe(LicenceState::Valid);

    Carbon::setTestNow(Carbon::parse('2027-08-15'));
    expect($status->evaluate()->state)->toBe(LicenceState::Expiring);

    Carbon::setTestNow(Carbon::parse('2027-09-10'));
    expect($status->evaluate()->state)->toBe(LicenceState::Grace);

    Carbon::setTestNow(Carbon::parse('2027-10-15'));
    expect($status->evaluate()->state)->toBe(LicenceState::Enforced);

    // Not one of those checks touched the network (§4.2).
    Http::assertNothingSent();
});

it('distrusts a cached row whose payload was tampered with in the database', function () {
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);

    $licence = app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(['expires_at' => '2027-08-31']), $keys['file']),
        $user->toAuditActor(),
    );

    // A school (or a "helpful" technician) pushes the expiry out by SQL.
    $doctored = p7f4Payload(['expires_at' => '2099-08-31']);
    DB::table('licences')->where('id', $licence->getKey())->update([
        'payload' => json_encode($doctored, JSON_THROW_ON_ERROR),
        'expires_at' => '2099-08-31',
    ]);

    $evaluation = app(LicenceStatus::class)->evaluate();

    expect($evaluation->trusted)->toBeFalse();
    expect($evaluation->failureKey)->toBe('licence.failure.file_signature_invalid');
    // Falls back to the unlicensed ladder, not to a harder lockout: with no
    // trial anchor the state is the permissive Trial.
    expect($evaluation->state)->toBe(LicenceState::Trial);
});

it('distrusts an activation cached on a different machine, in constant time semantics', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    $oldMachine = p7f4Fingerprint('old-machine');
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $responsePayload = p7f4Payload(['fingerprint' => $oldMachine]);
    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['activation']),
        ]),
    ]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    app(ActivateOnline::class)->handle('OPES-KEY', $user->toAuditActor());

    // The database is copied wholesale to a new PC.
    p7f4Fingerprint('new-machine');

    $evaluation = app(LicenceStatus::class)->evaluate();

    expect($evaluation->trusted)->toBeFalse();
    expect($evaluation->failureKey)->toBe('licence.failure.fingerprint_mismatch');
});

it('reports Revoked for a licence the re-check has marked revoked', function () {
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $licence = app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(), $keys['file']),
        $user->toAuditActor(),
    );
    $licence->forceFill(['revoked_at' => Carbon::parse('2027-01-15 09:00:00')])->save();

    expect(app(LicenceStatus::class)->evaluate()->state)->toBe(LicenceState::Revoked);
});

// ---------------------------------------------------------------------------
// DeactivateLicence (§4.3: local clear UNCONDITIONAL, seat message honest)
// ---------------------------------------------------------------------------

it('clears a file licence locally with no seat involved and no network', function () {
    Http::fake();
    p7f4Manager();
    $keys = p7f4Keys();

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $actor = $user->toAuditActor();

    app(ImportLicenceFile::class)->handle(p7f4FileEnvelope(p7f4Payload(), $keys['file']), $actor);

    $result = app(DeactivateLicence::class)->handle($actor);

    expect($result['seat_released'])->toBeNull();
    expect(Licence::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('clears an activation locally even when the seat release fails, and says so', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    $fingerprint = p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $responsePayload = p7f4Payload(['fingerprint' => $fingerprint]);
    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['activation']),
        ]),
    ]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $actor = $user->toAuditActor();
    app(ActivateOnline::class)->handle('OPES-KEY', $actor);

    // The old PC has no internet on moving day (§4.3's exact scenario).
    Http::fake(function (): never {
        throw new ConnectionException('no internet');
    });

    $result = app(DeactivateLicence::class)->handle($actor);

    // Local clear happened anyway; the school is told the seat still counts.
    expect(Licence::query()->count())->toBe(0);
    expect($result['seat_released'])->toBeFalse();
});

it('reports the seat as freed when the server confirms the release', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    $fingerprint = p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $responsePayload = p7f4Payload(['fingerprint' => $fingerprint]);
    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['activation']),
        ]),
    ]);

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    $actor = $user->toAuditActor();
    app(ActivateOnline::class)->handle('OPES-KEY', $actor);

    $result = app(DeactivateLicence::class)->handle($actor);

    expect($result['seat_released'])->toBeTrue();
    expect(Licence::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// OpportunisticRecheck (§4.3: clears ONLY on signed revoked|invalid_key)
// ---------------------------------------------------------------------------

it('runs the re-check only when due, and never without a cached licence and a server', function () {
    p7f4Keys();

    // No licence at all.
    Http::fake();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);
    app(OpportunisticRecheck::class)->handle();
    Http::assertNothingSent();

    // A licence, but no server configured.
    $licence = p7f4CachedActivation(nextCheckAfter: '2020-01-01 00:00:00');
    config(['opes.licensing.activation_url' => null]);
    Http::fake();
    app(OpportunisticRecheck::class)->handle();
    Http::assertNothingSent();

    // A server, but next_check_after has not passed - the date is
    // scheduling metadata only (§4.2).
    config(['opes.licensing.activation_url' => P7F4_SERVER]);
    $licence->forceFill(['next_check_after' => Carbon::now()->addDay()->toDateTimeString()])->save();
    Http::fake();
    app(OpportunisticRecheck::class)->handle();
    Http::assertNothingSent();
    expect(Licence::query()->count())->toBe(1);
});

it('clears the licence on a SIGNED revoked or invalid_key answer - the one case that matters', function () {
    foreach (['revoked', 'invalid_key'] as $verdict) {
        Licence::query()->delete();
        $keys = p7f4Keys();
        p7f4CachedActivation(nextCheckAfter: '2020-01-01 00:00:00');
        config(['opes.licensing.activation_url' => P7F4_SERVER]);

        $answer = ['product' => 'opes-school', 'status' => $verdict];
        Http::fake([
            'licence.opes.test/*' => Http::response([
                'payload' => $answer,
                'signature' => p7f4Sign($answer, $keys['activation']),
            ]),
        ]);

        app(OpportunisticRecheck::class)->handle();

        expect(Licence::query()->count())->toBe(0, "a signed {$verdict} must clear the cached licence");
    }
});

it('changes nothing on unsigned revocations, server errors, timeouts and no_seats', function () {
    $keys = p7f4Keys();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $scenarios = [
        // An UNSIGNED "revoked" is an instruction from nobody.
        static fn () => Http::fake(['licence.opes.test/*' => Http::response([
            'payload' => ['product' => 'opes-school', 'status' => 'revoked'],
            'signature' => base64_encode('not a real signature'),
        ])]),
        // 5xx.
        static fn () => Http::fake(['licence.opes.test/*' => Http::response('boom', 503)]),
        // No internet / DNS failure / timeout.
        static fn () => Http::fake(function (): never {
            throw new ConnectionException('offline');
        }),
        // A signed answer that is merely unhappy about seats.
        static fn () => Http::fake(function () use ($keys) {
            $answer = ['product' => 'opes-school', 'status' => 'no_seats'];

            return Http::response([
                'payload' => $answer,
                'signature' => p7f4Sign($answer, $keys['activation']),
            ]);
        }),
    ];

    foreach ($scenarios as $fake) {
        Licence::query()->delete();
        p7f4CachedActivation(nextCheckAfter: '2020-01-01 00:00:00');
        $fake();

        app(OpportunisticRecheck::class)->handle();

        expect(Licence::query()->count())->toBe(1);
    }
});

it('pushes next_check_after out on a signed ok answer without touching validity', function () {
    $keys = p7f4Keys();
    $licence = p7f4CachedActivation(nextCheckAfter: '2020-01-01 00:00:00');
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $answer = ['product' => 'opes-school', 'status' => 'ok', 'next_check_after' => '2030-06-01T00:00:00Z'];
    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $answer,
            'signature' => p7f4Sign($answer, $keys['activation']),
        ]),
    ]);

    $before = $licence->payload;

    app(OpportunisticRecheck::class)->handle();

    $licence->refresh();
    expect($licence->next_check_after?->year)->toBe(2030);
    // The cached signed payload - the thing validity comes from - is untouched.
    expect($licence->payload)->toEqual($before);
});

// ---------------------------------------------------------------------------
// §4.3: every failure mode has a DISTINCT localized EN and FR sentence
// ---------------------------------------------------------------------------

it('keeps every EN and FR failure sentence distinct - the build fails if two collapse', function () {
    foreach (['en', 'fr'] as $locale) {
        /** @var array<array-key, mixed> $lines */
        $lines = require base_path("lang/{$locale}/licence.php");

        $flat = p7f4FlattenLang($lines);

        // Every failure-mode sentence: cached-row failures, import failures,
        // activation failures, the deactivation seat warning, and the two
        // blocked-operation refusals. Success confirmations ("done") and
        // panel chrome are not failure modes.
        $failureSentences = [];

        foreach ($flat as $key => $sentence) {
            $isFailureGroup = str_starts_with($key, 'failure.')
                || str_starts_with($key, 'import.')
                || str_starts_with($key, 'activate.')
                || str_starts_with($key, 'deactivate.')
                || str_starts_with($key, 'blocked.');

            if ($isFailureGroup && ! str_ends_with($key, '.done')) {
                $failureSentences[$key] = $sentence;
            }
        }

        expect(count($failureSentences))->toBeGreaterThanOrEqual(20);
        expect(array_unique($failureSentences))->toHaveCount(
            count($failureSentences),
            "two {$locale} licensing failure sentences collapsed onto the same text",
        );
    }
});

it('keeps the EN and FR licence lang files key-for-key parallel', function () {
    /** @var array<array-key, mixed> $en */
    $en = require base_path('lang/en/licence.php');
    /** @var array<array-key, mixed> $fr */
    $fr = require base_path('lang/fr/licence.php');

    $enKeys = array_keys(p7f4FlattenLang($en));
    $frKeys = array_keys(p7f4FlattenLang($fr));

    sort($enKeys);
    sort($frKeys);

    expect($frKeys)->toBe($enKeys);
});

// ---------------------------------------------------------------------------
// Local fixture: a genuinely signed activation licence in the cache
// ---------------------------------------------------------------------------

if (! function_exists('p7f4CachedActivation')) {
    /**
     * A signed, machine-bound activation licence written straight to the
     * cache (bypassing HTTP) for re-check and deactivation tests.
     */
    function p7f4CachedActivation(string $nextCheckAfter): Licence
    {
        $keys = p7f4Keys();
        $fingerprint = p7f4Fingerprint();

        $payload = p7f4Payload(['fingerprint' => $fingerprint]);

        return Licence::query()->create([
            'payload' => $payload,
            'signature' => p7f4Sign($payload, $keys['activation']),
            'fingerprint' => $fingerprint,
            'source' => Licence::SOURCE_ACTIVATION,
            'expires_at' => '2027-08-31',
            'next_check_after' => $nextCheckAfter,
            'grace_days' => 30,
            'revoked_at' => null,
        ]);
    }
}
