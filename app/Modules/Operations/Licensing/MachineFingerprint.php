<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

/**
 * The machine fingerprint an online activation binds to
 * (docs/specs/08-operations.md §4.3):
 *
 *     SHA-256("opes-machine-fingerprint-v1|" + source), lowercase hex
 *
 * where source is the OS machine GUID, falling back to the system volume
 * serial. It contains NO school name, user name, address, MAC address, or
 * any student or staff data. If neither source is readable the fingerprint
 * is EMPTY, NEVER RANDOM - a random value would activate, bind the seat to
 * a fingerprint this machine can never reproduce, and burn the seat. The
 * caller must refuse to make the activation API call on an empty value.
 */
final class MachineFingerprint
{
    public const PREFIX = 'opes-machine-fingerprint-v1|';

    public static function compute(): string
    {
        $source = self::source();

        return $source === '' ? '' : hash('sha256', self::PREFIX.$source);
    }

    /**
     * The raw machine identity source. `opes.licensing.fingerprint_source`
     * overrides when SET (including set-to-empty, which tests use to model
     * the machine-with-no-readable-identity case); the installer writes it
     * on platforms where reading the GUID needs elevated shell access.
     */
    public static function source(): string
    {
        $override = config('opes.licensing.fingerprint_source');

        if ($override !== null) {
            return is_string($override) ? trim($override) : '';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::windowsSource();
        }

        // Linux/macOS: the systemd machine id is this platform's GUID.
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
            if (! is_readable($path)) {
                continue;
            }

            $contents = file_get_contents($path);

            if (is_string($contents) && trim($contents) !== '') {
                return trim($contents);
            }
        }

        return '';
    }

    private static function windowsSource(): string
    {
        // Machine GUID first, volume serial as the fallback (§4.3). Both go
        // through shell_exec, which a hardened php.ini may disable - in that
        // case the answer is the honest one: empty, never random.
        if (! function_exists('shell_exec')) {
            return '';
        }

        $guid = shell_exec('reg query "HKLM\SOFTWARE\Microsoft\Cryptography" /v MachineGuid 2>nul');

        if (is_string($guid) && preg_match('/MachineGuid\s+REG_SZ\s+(\S+)/', $guid, $m) === 1) {
            return trim($m[1]);
        }

        $vol = shell_exec('vol C: 2>nul');

        if (is_string($vol) && preg_match('/Serial Number is\s+(\S+)/i', $vol, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }
}
