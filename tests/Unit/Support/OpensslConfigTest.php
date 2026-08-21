<?php

declare(strict_types=1);

use App\Support\Crypto\OpensslConfig;

/*
 * The behaviour under test is "openssl can actually generate a key on this
 * machine". Windows PHP ships openssl.cnf under extras/ssl but is not compiled
 * to look there, so every key generation failed with "configuration file
 * routines::no such file" - which took out document QR signing, VAPID key
 * generation, and roughly 130 tests.
 *
 * Setting OPENSSL_CONF in .env cannot fix that by itself: PHP's openssl
 * extension resolves its config during module start-up, before any userland
 * code runs, so putenv() is already too late to change what OpenSSL loaded.
 * Passing `config` per call is the only lever left, which is what this does.
 */

/*
 * Captured once, at file load, before any test has had the chance to change
 * it. Leaking OPENSSL_CONF into later tests would silently change how they
 * generate keys, so every test here puts it back exactly as it was.
 */
$originalConf = getenv('OPENSSL_CONF');

afterEach(function () use ($originalConf) {
    if (is_string($originalConf)) {
        putenv('OPENSSL_CONF='.$originalConf);
    } else {
        putenv('OPENSSL_CONF');
    }
});

it('generates a real EC keypair through the resolved options', function () {
    // The regression that mattered. If this fails, document signing is broken
    // on this machine whatever the rest of the suite reports.
    $key = @openssl_pkey_new(OpensslConfig::options([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]));

    expect($key)->not->toBeFalse();

    if ($key !== false) {
        $pem = '';
        expect(openssl_pkey_export($key, $pem, null, OpensslConfig::options()))->toBeTrue();
        expect($pem)->toContain('PRIVATE KEY');
    }
});

it('uses an explicitly set OPENSSL_CONF', function () {
    // Any readable file proves precedence; it is never parsed here.
    putenv('OPENSSL_CONF='.__FILE__);

    expect(OpensslConfig::path())->toBe(__FILE__);
    expect(OpensslConfig::options()['config'])->toBe(__FILE__);
});

it('ignores a setting that points at a file it cannot read', function () {
    // A stale setting must not be handed to OpenSSL as though it were valid:
    // that turns a working machine into a broken one.
    $missing = __DIR__.'/no-such-openssl.cnf';

    putenv('OPENSSL_CONF='.$missing);

    expect(OpensslConfig::path())->not->toBe($missing);
});

it('never overrides a config the caller passed itself', function () {
    putenv('OPENSSL_CONF='.__FILE__);

    expect(OpensslConfig::options(['config' => 'caller/wins.cnf'])['config'])
        ->toBe('caller/wins.cnf');
});

it('adds nothing when no configuration can be found', function () {
    /*
     * The Linux and CI case: OpenSSL finds its own configuration, we detect
     * nothing, and the options array comes back untouched. Guessing a path
     * there would break the working case in order to fix the broken one.
     */
    putenv('OPENSSL_CONF');

    $options = OpensslConfig::options(['curve_name' => 'prime256v1']);

    if (OpensslConfig::path() === null) {
        expect($options)->toBe(['curve_name' => 'prime256v1']);
    } else {
        // A shipped openssl.cnf was found beside the PHP binary. It must be a
        // file that actually exists, never a hopeful guess.
        expect(is_readable($options['config']))->toBeTrue();
    }
});
