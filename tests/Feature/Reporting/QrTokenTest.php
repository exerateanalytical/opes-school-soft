<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\EnsureActiveSigningKey;
use App\Modules\Reporting\Actions\ResolveInstanceUuid;
use App\Modules\Reporting\Actions\RotateDocumentSigningKey;
use App\Modules\Reporting\Actions\VerifyDocumentQrToken;
use App\Modules\Reporting\Domain\QrToken;
use App\Modules\Reporting\Domain\QrTokenPayload;
use App\Modules\Reporting\Domain\VerificationStatus;
use App\Modules\Reporting\Models\DocumentSigningKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P13QrHelpers.php';

uses(RefreshDatabase::class);

/*
 * docs/specs/10-documents.md 17.1 - OPES1.<payload>.<sig> tokens: ECDSA
 * P-256/SHA-256 sign and verify through openssl, tamper resistance, the
 * no-PII payload contract, and key lifecycle (provision, rotate, verify
 * forever).
 */

if (! function_exists('p13qrPayload')) {
    function p13qrPayload(string $keyId = 'opesk-test'): QrTokenPayload
    {
        return QrTokenPayload::forContentHash(
            instanceUuid: '3e4bcb0e-1111-4222-8333-444455556666',
            templateCode: 'CERT-COMP',
            serial: 'HA/2026/COM/000123',
            contentHash: hash('sha256', 'the rendered pdf bytes'),
            issueDate: '2026-05-04',
            keyId: $keyId,
        );
    }
}

it('signs and verifies a token round trip', function () {
    $pair = p13qrKeypair();

    $token = QrToken::sign(p13qrPayload(), $pair['private']);

    expect($token)->toStartWith('OPES1.')
        ->and(substr_count($token, '.'))->toBe(2);

    $verified = QrToken::verify($token, $pair['public']);

    expect($verified)->not->toBeNull()
        ->and($verified?->serial)->toBe('HA/2026/COM/000123')
        ->and($verified?->templateCode)->toBe('CERT-COMP')
        ->and($verified?->issueDate)->toBe('2026-05-04')
        ->and($verified?->contentHashPrefix)->toBe(substr(hash('sha256', 'the rendered pdf bytes'), 0, 32));
});

it('rejects a tampered payload segment', function () {
    $pair = p13qrKeypair();
    $token = QrToken::sign(p13qrPayload(), $pair['private']);

    [$prefix, $payload, $sig] = explode('.', $token);

    // Swap the serial inside the signed payload: structurally valid JSON,
    // cryptographically someone else's document.
    $json = base64_decode(strtr($payload, '-_', '+/').str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
    $doctored = str_replace('000123', '000124', (string) $json);
    $forged = $prefix.'.'.rtrim(strtr(base64_encode($doctored), '+/', '-_'), '=').'.'.$sig;

    expect(QrToken::decode($forged))->not->toBeNull()
        ->and(QrToken::verify($forged, $pair['public']))->toBeNull();
});

it('rejects a tampered signature segment', function () {
    $pair = p13qrKeypair();
    $token = QrToken::sign(p13qrPayload(), $pair['private']);

    $flipped = substr($token, 0, -4).(str_ends_with($token, 'AAAA') ? 'BBBB' : 'AAAA');

    expect(QrToken::verify($flipped, $pair['public']))->toBeNull();
});

it('rejects a token signed by a different key', function () {
    $signer = p13qrKeypair();
    $other = p13qrKeypair();

    $token = QrToken::sign(p13qrPayload(), $signer['private']);

    expect(QrToken::verify($token, $other['public']))->toBeNull()
        ->and(QrToken::verify($token, $signer['public']))->not->toBeNull();
});

it('fails closed on structural garbage', function (string $garbage) {
    $pair = p13qrKeypair();

    expect(QrToken::verify($garbage, $pair['public']))->toBeNull()
        ->and(QrToken::decode($garbage))->toBeNull();
})->with([
    'empty' => [''],
    'not a token' => ['hello world'],
    'wrong prefix' => ['OPES2.YWJj.YWJj'],
    'two segments' => ['OPES1.YWJj'],
    'four segments' => ['OPES1.YWJj.YWJj.YWJj'],
    'invalid base64url' => ['OPES1.$$$$.YWJj'],
    'payload not json' => ['OPES1.YWJj.YWJj'],
]);

it('carries exactly the six 17.1 fields and no PII', function () {
    $pair = p13qrKeypair();
    $token = QrToken::sign(p13qrPayload(), $pair['private']);

    $segment = explode('.', $token)[1];
    $json = base64_decode(strtr($segment, '-_', '+/').str_repeat('=', (4 - strlen($segment) % 4) % 4), true);
    $decoded = json_decode((string) $json, true);

    $keys = array_keys((array) $decoded);
    sort($keys);

    // i, t, s, h, d, k - and NOTHING else: no name slot, no matricule slot,
    // no marks, no date of birth.
    expect($keys)->toBe(['d', 'h', 'i', 'k', 's', 't']);
});

it('rejects a payload smuggling extra fields past the six-key contract', function () {
    $pair = p13qrKeypair();

    // Hand-build a token whose payload carries a seventh key. Even correctly
    // signed, the verifier must refuse it: the field contract is structural.
    $json = json_encode([
        'i' => '3e4bcb0e-1111-4222-8333-444455556666',
        't' => 'CERT-COMP',
        's' => 'HA/2026/COM/000123',
        'h' => str_repeat('ab', 16),
        'd' => '2026-05-04',
        'k' => 'opesk-test',
        'name' => 'Ngu Peter',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $sig = '';
    openssl_sign($json, $sig, openssl_pkey_get_private($pair['private']) ?: '', OPENSSL_ALGO_SHA256);

    $token = 'OPES1.'
        .rtrim(strtr(base64_encode($json), '+/', '-_'), '=')
        .'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    expect(QrToken::verify($token, $pair['public']))->toBeNull();
});

// ---------------------------------------------------------------- key stack

it('provisions one active P-256 key on first use, idempotently, encrypted at rest', function () {
    p13qrUserAs(Role::Administrator);

    $first = app(EnsureActiveSigningKey::class)->handle();
    $second = app(EnsureActiveSigningKey::class)->handle();

    expect($second->id)->toBe($first->id)
        ->and($first->is_active)->toBeTrue()
        ->and($first->algorithm)->toBe('ES256')
        ->and($first->public_key)->toContain('BEGIN PUBLIC KEY')
        ->and($first->private_key)->toContain('BEGIN')
        ->and(DocumentSigningKey::query()->count())->toBe(1);

    // The COLUMN holds ciphertext, never the PEM: the model cast decrypts.
    $raw = (string) DB::table('document_signing_keys')->where('id', $first->id)->value('private_key');

    expect($raw)->not->toContain('PRIVATE KEY');
});

it('rotates the signing key: old retired but still verifying, new active', function () {
    $user = p13qrUserAs(Role::Administrator);

    $document = p13qrIssuedDocument();
    $token = p13qrTokenFor($document);

    $oldKey = DocumentSigningKey::active();
    $newKey = app(RotateDocumentSigningKey::class)->handle($user->toAuditActor());

    expect($newKey->id)->not->toBe($oldKey?->id)
        ->and($newKey->is_active)->toBeTrue()
        ->and(DocumentSigningKey::query()->where('is_active', true)->count())->toBe(1);

    $retired = $oldKey?->fresh();

    expect($retired?->is_active)->toBeFalse()
        ->and($retired?->retired_at)->not->toBeNull();

    // Retirement stops SIGNING, never verification (17.1): the token issued
    // under the old key still answers VALID after the rotation.
    $result = app(VerifyDocumentQrToken::class)->handle($token);

    expect($result->status)->toBe(VerificationStatus::Valid)
        ->and($result->serial)->toBe($document['serial']);
});

it('denies key rotation without documents.template_manage', function () {
    $user = p13qrUserAs(Role::Bursar);

    app(RotateDocumentSigningKey::class)->handle($user->toAuditActor());
})->throws(AuthorizationException::class);

it('mints one durable instance uuid and keeps it', function () {
    $first = app(ResolveInstanceUuid::class)->handle();
    $second = app(ResolveInstanceUuid::class)->handle();

    expect($first)->toBe($second)
        ->and($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});
