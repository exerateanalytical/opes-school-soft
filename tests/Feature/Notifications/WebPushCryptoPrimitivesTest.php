<?php

declare(strict_types=1);

use App\Modules\Notifications\Actions\GenerateVapidKeys;
use App\Modules\Notifications\Actions\SendPushNotification;
use App\Modules\SchoolProfile\Actions\ReadSetting;

/*
 * The pure-math pieces of RFC 8291/8292 Web Push encryption.
 *
 * These were once the only testable part of that path: every other step
 * (VAPID key generation, the per-message ECDH ephemeral key, the JWT
 * signature) needs a fresh EC P-256 key, and openssl_pkey_new() failed
 * closed for every EC operation on this PHP build. OpensslConfig now hands
 * openssl its configuration per call, so EC key generation works and that
 * limitation is gone - see tests/Unit/Support/OpensslConfigTest.php.
 *
 * HKDF is checked against RFC 5869 §A.1 Test Case 1 - a fixed, published
 * input/output pair with no dependency on any key generation at all - which
 * is exactly the kind of function most prone to a silent off-by-one.
 */

it('implements HKDF correctly against the RFC 5869 test vector', function (): void {
    $ikm = hex2bin('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b');
    $salt = hex2bin('000102030405060708090a0b0c');
    $info = hex2bin('f0f1f2f3f4f5f6f7f8f9');

    // RFC 5869 §A.1: OKM = 3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4
    // c5db02d56ecc4c5bf34007208d5b887185865 (42 octets, L=42). This checks
    // the first 16, since every call site in SendPushNotification asks for
    // <= 32 bytes and this implementation deliberately does not support
    // HKDF's multi-block expand loop (T(2), T(3)...) - unneeded for that,
    // and its absence would silently truncate wrong if a caller ever did
    // ask for more.
    $expectedFirst16 = hex2bin('3cb25f25faacd57a90434f64d0362f2a');

    $method = new ReflectionMethod(SendPushNotification::class, 'hkdf');
    $method->setAccessible(true);

    $instance = new SendPushNotification(app(ReadSetting::class));
    $actual = $method->invoke($instance, $salt, $ikm, $info, 16);

    expect(bin2hex($actual))->toBe(bin2hex($expectedFirst16));
});

it('round-trips base64url encode/decode including the padding-stripping edge cases', function (): void {
    foreach (['', 'a', 'ab', 'abc', 'abcd', random_bytes(65)] as $original) {
        $encoded = GenerateVapidKeys::base64UrlEncode($original);

        expect($encoded)->not->toContain('+')
            ->not->toContain('/')
            ->not->toContain('=');

        $method = new ReflectionMethod(SendPushNotification::class, 'base64UrlDecode');
        $method->setAccessible(true);
        $instance = new SendPushNotification(app(ReadSetting::class));
        $decoded = $method->invoke($instance, $encoded);

        expect($decoded)->toBe($original);
    }
});

it('encodes DER lengths correctly for both the short-form and long-form boundary', function (): void {
    $method = new ReflectionMethod(SendPushNotification::class, 'derLength');
    $method->setAccessible(true);
    $instance = new SendPushNotification(app(ReadSetting::class));

    // Short form: length fits in one byte, top bit clear.
    expect(bin2hex($method->invoke($instance, 65)))->toBe('41');
    expect(bin2hex($method->invoke($instance, 127)))->toBe('7f');

    // Long form: 0x80 | byte-count, then the length's big-endian bytes.
    expect(bin2hex($method->invoke($instance, 128)))->toBe('8180');
    expect(bin2hex($method->invoke($instance, 256)))->toBe('820100');
});

it('round-trips a DER ECDSA signature to raw r||s and back to fixed 32-byte halves', function (): void {
    // A hand-built DER ECDSA-Sig-Value with r needing a leading zero
    // (its own high bit set) - the exact case that breaks an implementation
    // which forgets to strip that DER sign-padding byte.
    $r = str_repeat("\xff", 32); // high bit set - DER must pad it
    $s = str_repeat("\x11", 32);

    $rEncoded = "\x00".$r; // DER INTEGER sign padding
    $sEncoded = $s;

    $der = "\x30".chr(2 + strlen($rEncoded) + 2 + strlen($sEncoded))
        ."\x02".chr(strlen($rEncoded)).$rEncoded
        ."\x02".chr(strlen($sEncoded)).$sEncoded;

    $method = new ReflectionMethod(SendPushNotification::class, 'derEcdsaToRaw');
    $method->setAccessible(true);
    $instance = new SendPushNotification(app(ReadSetting::class));

    $raw = $method->invoke($instance, $der);

    expect(strlen($raw))->toBe(64)
        ->and(bin2hex(substr($raw, 0, 32)))->toBe(bin2hex($r))
        ->and(bin2hex(substr($raw, 32, 32)))->toBe(bin2hex($s));
});
