<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Operations\Licensing\CanonicalJson;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the P7-F4 licensing tests. Every helper is
 * `function_exists`-guarded and prefixed `p7f4` so the names stay globally
 * unique across the Pest suite (HANDOVER standing rule).
 *
 * The key pairs are THROWAWAY and generated IN MEMORY (08-operations §4.1:
 * "no private key of any kind lives in the application repository; tests
 * generate a throwaway pair in memory"). Only the PUBLIC halves are pushed
 * into config, exactly where the application reads its embedded keys.
 */

if (! defined('P7F4_SERVER')) {
    /** The fake activation server both licensing test files point config at. */
    define('P7F4_SERVER', 'https://licence.opes.test/api');
}

if (! function_exists('p7f4Keys')) {
    /**
     * Generate (once per process) the two deliberately split key pairs of
     * §4.1 - ECDSA P-256 for licence FILES, RSA-2048 for ACTIVATION
     * responses - and embed the public halves in config for this test.
     *
     * @return array{file: OpenSSLAsymmetricKey, activation: OpenSSLAsymmetricKey}
     */
    function p7f4Keys(): array
    {
        /** @var array{file: OpenSSLAsymmetricKey, activation: OpenSSLAsymmetricKey}|null $pairs */
        static $pairs = null;

        if ($pairs === null) {
            $file = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]);
            $activation = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ]);

            if ($file === false || $activation === false) {
                throw new RuntimeException('openssl could not generate the throwaway test key pairs.');
            }

            $pairs = ['file' => $file, 'activation' => $activation];
        }

        $fileDetails = openssl_pkey_get_details($pairs['file']);
        $activationDetails = openssl_pkey_get_details($pairs['activation']);

        if ($fileDetails === false || $activationDetails === false) {
            throw new RuntimeException('openssl could not export the throwaway public keys.');
        }

        config([
            'opes.licensing.licence_file_public_key' => $fileDetails['key'],
            'opes.licensing.activation_public_key' => $activationDetails['key'],
        ]);

        return $pairs;
    }
}

if (! function_exists('p7f4Sign')) {
    /**
     * Sign the CanonicalJson form of a payload - exactly what the vendor's
     * signing tool and the activation server do (§4.3).
     *
     * @param  array<string, mixed>  $payload
     */
    function p7f4Sign(array $payload, OpenSSLAsymmetricKey $privateKey): string
    {
        $signature = '';
        $ok = openssl_sign(CanonicalJson::encode($payload), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($ok !== true) {
            throw new RuntimeException('openssl could not sign the test payload.');
        }

        return base64_encode($signature);
    }
}

if (! function_exists('p7f4Payload')) {
    /**
     * A structurally complete licence payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function p7f4Payload(array $overrides = []): array
    {
        return array_merge([
            'product' => 'opes-school',
            'licence_id' => 'LIC-2027-0042',
            'school' => 'Collège Bilingue de Test',
            'edition' => 'finance',
            'student_cap' => 600,
            'section_count' => 2,
            'expires_at' => '2027-08-31',
            'grace_days' => 30,
        ], $overrides);
    }
}

if (! function_exists('p7f4FileEnvelope')) {
    /**
     * The `.opeslic` file: `{"payload": {...}, "signature": "base64"}`,
     * signed with the FILE (ECDSA) key unless another key is handed in.
     *
     * @param  array<string, mixed>  $payload
     */
    function p7f4FileEnvelope(array $payload, ?OpenSSLAsymmetricKey $signWith = null): string
    {
        $key = $signWith ?? p7f4Keys()['file'];

        return json_encode([
            'payload' => $payload,
            'signature' => p7f4Sign($payload, $key),
        ], JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('p7f4Manager')) {
    /**
     * A logged-in operator holding `licence.manage` plus any extra
     * permissions a gate-side test needs.
     *
     * @param  list<string>  $extraPermissions
     */
    function p7f4Manager(array $extraPermissions = []): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();

        foreach (array_merge(['licence.manage'], $extraPermissions) as $permission) {
            SpatiePermission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p7f4Fingerprint')) {
    /**
     * Pin this "machine's" identity source so the fingerprint is
     * deterministic, and return the fingerprint an activation binds to.
     */
    function p7f4Fingerprint(string $source = 'p7f4-test-machine'): string
    {
        config(['opes.licensing.fingerprint_source' => $source]);

        return hash('sha256', 'opes-machine-fingerprint-v1|'.$source);
    }
}

if (! function_exists('p7f4FlattenLang')) {
    /**
     * Flatten a lang array into dot-keyed sentences.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    function p7f4FlattenLang(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = array_merge($flat, p7f4FlattenLang($value, $dotted));

                continue;
            }

            if (is_string($value)) {
                $flat[$dotted] = $value;
            }
        }

        return $flat;
    }
}
