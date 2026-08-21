<?php

declare(strict_types=1);

namespace App\Support\Crypto;

/**
 * Supplies the `config` option that openssl_pkey_new() and friends need on
 * builds where OpenSSL cannot find its own openssl.cnf.
 *
 * Windows PHP ships openssl.cnf under extras/ssl but is not compiled to look
 * there: the built-in default is C:\Program Files\Common Files\SSL, which is
 * usually empty. Every key generation then fails with "configuration file
 * routines::no such file", which is why document QR signing, VAPID key
 * generation and the licensing tests all fall over on a fresh Laragon box.
 *
 * Setting OPENSSL_CONF in .env does NOT fix it, despite looking like it
 * should. PHP's openssl extension resolves the config during module start-up,
 * before any userland code runs, so putenv() - which is all .env can do - is
 * already too late. Verified directly: exporting the variable into the real
 * process environment works, putenv() from inside the same process does not.
 * The per-call `config` option is the only lever that works from PHP.
 *
 * On a platform where OpenSSL finds its own configuration - every Linux
 * distribution, and CI - nothing is detected and no `config` key is added, so
 * behaviour there is exactly as before. This deliberately never GUESSES a
 * path: passing a wrong one would break the working case to fix the broken.
 */
final class OpensslConfig
{
    /**
     * Merge the resolved config path into an openssl options array.
     *
     * An explicit `config` supplied by the caller always wins.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function options(array $options = []): array
    {
        if (array_key_exists('config', $options)) {
            return $options;
        }

        $path = self::path();

        if ($path !== null) {
            $options['config'] = $path;
        }

        return $options;
    }

    /**
     * The openssl.cnf this machine should use, or null to leave OpenSSL to its
     * own devices.
     */
    public static function path(): ?string
    {
        /*
         * 1. An explicit setting wins - someone who points this at a hardened
         *    configuration means it.
         *
         *    getenv() rather than config() on purpose. It already covers both
         *    ways the value can arrive: a genuinely exported OPENSSL_CONF, and
         *    OPENSSL_CONF in .env, which Laravel publishes through putenv().
         *    One source of truth, and it keeps this class free of the
         *    framework so it works during bootstrap and under a plain unit
         *    test.
         */
        $configured = getenv('OPENSSL_CONF');

        if (is_string($configured) && $configured !== '' && is_readable($configured)) {
            return $configured;
        }

        // 2. The copy Windows PHP ships beside its own binary. Located relative
        //    to PHP_BINARY rather than hard-coded, so it follows a version
        //    upgrade instead of silently pointing at a directory that no
        //    longer exists.
        $shipped = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'
            .DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

        if (is_readable($shipped)) {
            return $shipped;
        }

        return null;
    }
}
