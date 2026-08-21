<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Audit\Actor;
use App\Support\Crypto\OpensslConfig;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Generates and stores the school's VAPID (RFC 8292) application-server
 * keypair, the identity Web Push uses to prove a push originates from this
 * school rather than an arbitrary sender.
 *
 * A P-256 EC key, same as the QR document-signing key (10-documents §17.1).
 * `openssl_pkey_new()` needs an `openssl.cnf` to create an EC key at all,
 * and Windows PHP does not point at the one it ships; OpensslConfig supplies
 * the path per call, so this works without anything being set up first.
 *
 * The DomainException below is kept for the case where no openssl.cnf can be
 * found anywhere, so that failure still arrives with a clear cause rather
 * than as a bare OpenSSL error.
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

        $resource = @openssl_pkey_new(OpensslConfig::options([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]));

        if ($resource === false) {
            throw new DomainException(
                'Could not generate an EC key: no openssl.cnf could be found for this PHP build. '
                .'OpensslConfig looks for an explicit OPENSSL_CONF setting, then the copy shipped '
                .'beside the PHP binary under extras/ssl. Point OPENSSL_CONF at a readable '
                .'openssl.cnf in .env - it is used as a path here, not as a process variable.'
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
