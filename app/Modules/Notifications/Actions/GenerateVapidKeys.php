<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Generates and stores the school's VAPID (RFC 8292) application-server
 * keypair, the identity Web Push uses to prove a push originates from this
 * school rather than an arbitrary sender.
 *
 * A P-256 EC key, same as the QR document-signing key (10-documents §17.1)
 * and hitting the exact same environment dependency: `openssl_pkey_new()`
 * needs an `openssl.cnf` to create an EC key at all, and this PHP build on
 * this machine has none configured (OPENSSL_CONF unset). That failure is
 * NOT this Action's bug - it is the same pre-existing gap already
 * documented for QR signing, surfaced here as a DomainException with a
 * clear cause rather than a bare OpenSSL error.
 *
 * Idempotent: refuses to overwrite an existing keypair, because every
 * browser that has already subscribed did so against the OLD public key -
 * replacing it silently would make every existing subscription
 * undeliverable with no error until the next send.
 */
final class GenerateVapidKeys
{
    public function __construct(
        private readonly ReadSetting $read,
        private readonly WriteSetting $write,
    ) {}

    public function handle(Actor $actor, string $contactEmail): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $existing = $this->read->handle('notifications.vapid_public_key');

        if ($existing !== null) {
            throw new DomainException(
                'VAPID keys already exist. Regenerating would silently break every browser that has '
                .'already subscribed to push, since they hold the OLD public key. Revoke and re-subscribe '
                .'every device before rotating.'
            );
        }

        $resource = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($resource === false) {
            throw new DomainException(
                'Could not generate an EC key: this PHP build has no openssl.cnf configured '
                .'(OPENSSL_CONF is unset), the same environment gap already blocking QR document '
                .'signing. Set OPENSSL_CONF to the shipped openssl.cnf and restart PHP before retrying.'
            );
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || ! isset($details['ec']['d'], $details['ec']['x'], $details['ec']['y'])) {
            throw new DomainException('The generated key was not a usable EC keypair.');
        }

        // The uncompressed SEC1 public key point: 0x04 || X || Y, each 32
        // bytes - the exact format RFC 8292 §3 requires for the VAPID
        // public key and what a browser's PushManager.subscribe expects as
        // applicationServerKey.
        $publicKeyRaw = "\x04".$details['ec']['x'].$details['ec']['y'];

        $this->write->handle(
            'notifications.vapid_public_key',
            self::base64UrlEncode($publicKeyRaw),
            $actor,
        );
        $this->write->handle(
            'notifications.vapid_private_key',
            self::base64UrlEncode($details['ec']['d']),
            $actor,
        );
        $this->write->handle('notifications.vapid_contact_email', $contactEmail, $actor);
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
