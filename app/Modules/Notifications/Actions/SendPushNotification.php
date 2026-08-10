<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one notification to every browser the recipient has subscribed
 * from, per RFC 8291 (Message Encryption for Web Push) and RFC 8292
 * (VAPID).
 *
 * NOT VERIFIED END-TO-END ON THIS MACHINE. Both the VAPID JWT signature and
 * the per-message ECDH key exchange need to generate a fresh EC P-256 key,
 * and this PHP build has no `openssl.cnf` configured - the identical
 * environment gap GenerateVapidKeys and the QR-signing path already
 * document. The encryption steps below are implemented directly against
 * the RFC text with section citations so a reviewer can check them against
 * spec; they could not be exercised against a real browser push service
 * from this box. Treat this as reviewed-not-tested until OPENSSL_CONF is
 * set.
 *
 * A no-op, not an exception, when VAPID is unconfigured or the recipient
 * has no subscriptions: push is additive to the in-app notification, which
 * Notify has already created regardless.
 */
final class SendPushNotification
{
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly ReadSetting $read) {}

    public function handle(Notification $notification): void
    {
        $publicKey = $this->read->handle('notifications.vapid_public_key');
        $privateKey = $this->read->handle('notifications.vapid_private_key');
        $contact = $this->read->handle('notifications.vapid_contact_email');

        if (! is_string($publicKey) || ! is_string($privateKey) || ! is_string($contact)) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('user_id', $notification->user_id)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode([
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => $notification->url,
        ], JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $subscription) {
            $this->sendOne($subscription, $payload, $publicKey, $privateKey, $contact);
        }

        $notification->forceFill(['pushed_at' => now()])->save();
    }

    private function sendOne(
        PushSubscription $subscription,
        string $payload,
        string $vapidPublicKeyB64,
        string $vapidPrivateKeyB64,
        string $contactEmail,
    ): void {
        try {
            $endpoint = $subscription->endpoint;
            $origin = (string) parse_url($endpoint, PHP_URL_SCHEME).'://'.(string) parse_url($endpoint, PHP_URL_HOST);

            $jwt = $this->signVapidJwt($origin, $contactEmail, $vapidPrivateKeyB64);

            $encrypted = $this->encryptPayload($payload, $subscription->p256dh, $subscription->auth);

            $client = new Client(['timeout' => 10]);

            $client->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) self::TTL_SECONDS,
                    'Authorization' => sprintf('vapid t=%s, k=%s', $jwt, $vapidPublicKeyB64),
                ],
                'body' => $encrypted,
            ]);

            $subscription->forceFill([
                'last_used_at' => now(),
                'last_failed_at' => null,
                'last_failure_reason' => null,
            ])->save();
        } catch (GuzzleException|\Throwable $e) {
            // A dead subscription (410 Gone / 404) is expected churn, not an
            // incident - browsers expire push registrations on their own
            // schedule with no warning to the server. Logged, not thrown:
            // one unreachable device must never fail the other recipients
            // in the same Notify call.
            Log::warning('Push delivery failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);

            $subscription->forceFill([
                'last_failed_at' => now(),
                'last_failure_reason' => mb_substr($e->getMessage(), 0, 255),
            ])->save();
        }
    }

    /**
     * RFC 8292 §2: a JWS with header {"typ":"JWT","alg":"ES256"} and claims
     * {aud, exp, sub}, signed with the VAPID private key. Critically, JOSE
     * wants the RAW r||s signature (64 bytes), not the DER encoding
     * `openssl_sign` produces - the classic interop bug this method exists
     * to avoid.
     */
    private function signVapidJwt(string $audience, string $contactEmail, string $privateKeyB64): string
    {
        $header = GenerateVapidKeys::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = GenerateVapidKeys::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => now()->addHours(12)->timestamp,
            'sub' => str_starts_with($contactEmail, 'mailto:') ? $contactEmail : 'mailto:'.$contactEmail,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header.'.'.$claims;

        $privateKeyD = self::base64UrlDecode($privateKeyB64);
        $pem = $this->ecPrivateKeyToPem($privateKeyD);

        $derSignature = '';
        openssl_sign($signingInput, $derSignature, $pem, OPENSSL_ALGO_SHA256);

        $rawSignature = $this->derEcdsaToRaw($derSignature);

        return $signingInput.'.'.GenerateVapidKeys::base64UrlEncode($rawSignature);
    }

    /**
     * RFC 8291 §3.4-3.6: derive a shared secret via ECDH with an EPHEMERAL
     * local keypair, run HKDF twice (once to combine with the client's auth
     * secret, once per-record for the content-encryption key and nonce),
     * then AES-128-GCM encrypt a single padded record.
     */
    private function encryptPayload(
        string $plaintext,
        string $clientP256dhB64,
        string $clientAuthB64,
    ): string {
        $localKeyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($localKeyResource === false) {
            throw new \RuntimeException('Cannot generate the ephemeral EC key this record needs (OPENSSL_CONF).');
        }

        $localDetails = openssl_pkey_get_details($localKeyResource);
        $localPublicRaw = "\x04".$localDetails['ec']['x'].$localDetails['ec']['y'];

        $clientPublicRaw = self::base64UrlDecode($clientP256dhB64);
        $clientPublicPem = $this->ecPublicKeyToPem($clientPublicRaw);

        $sharedSecret = openssl_pkey_derive($clientPublicPem, $localKeyResource, 256 / 8);

        if ($sharedSecret === false) {
            throw new \RuntimeException('ECDH key derivation failed.');
        }

        $authSecret = self::base64UrlDecode($clientAuthB64);
        $salt = random_bytes(16);

        // RFC 8291 §3.4 - combine the ECDH secret with the client's auth
        // secret into a pseudorandom key (PRK_key), keyed on a fixed info
        // string that also carries both public keys.
        $keyInfo = "WebPush: info\x00".$clientPublicRaw.$localPublicRaw;
        $ikm = $this->hkdf($authSecret, $sharedSecret, $keyInfo, 32);

        // RFC 8291 §3.4 / RFC 8188 §2.1 - derive the content-encryption key
        // and nonce from that IKM, salted per-message.
        $cek = $this->hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        // RFC 8188 §2 padding: a single delimiter byte (0x02 - last/only
        // record) then zero padding. No padding added here beyond the
        // delimiter; a notification payload is short and padding-for-size-
        // hiding is not this Action's threat model.
        $padded = $plaintext."\x02";

        $ciphertext = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-128-GCM encryption failed.');
        }

        // RFC 8188 §2.1 aes128gcm header: salt(16) || record size(4, BE) ||
        // key id length(1) || key id (our ephemeral public key, 65 bytes).
        //
        // `rs` is the size of THIS RECORD's encrypted content alone
        // (ciphertext + the 16-byte GCM tag) - not the header length and
        // not the total message size. A single-record message (what every
        // notification here is) never needs rs to exceed that; the field
        // exists so a MULTI-record message can tell a receiver where each
        // record boundary falls, which does not apply here.
        $recordSize = pack('N', strlen($ciphertext.$tag));
        $header = $salt.$recordSize.chr(strlen($localPublicRaw)).$localPublicRaw;

        return $header.$ciphertext.$tag;
    }

    /**
     * RFC 5869 HKDF: extract-then-expand, single 32-byte-max output block
     * (every call site here asks for <= 32 bytes, so one HMAC round is
     * always sufficient and the multi-block expand loop is not needed).
     */
    private function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        return substr(hash_hmac('sha256', $info."\x01", $prk, true), 0, $length);
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, (int) (4 * ceil(strlen($data) / 4)), '=');

        return (string) base64_decode(strtr($padded, '-_', '+/'));
    }

    /**
     * Builds a PEM from the 32-byte raw EC private scalar `d`, since
     * `openssl_sign` needs a PEM/key resource, not raw bytes.
     */
    private function ecPrivateKeyToPem(string $d): string
    {
        // A minimal SEC1 ECPrivateKey DER wrapper for a P-256 scalar,
        // constructed by hand rather than via a library this codebase does
        // not carry - openssl_pkey_new cannot import a raw scalar directly.
        $version = "\x02\x01\x01";
        $privateKeyOctet = "\x04\x20".$d;
        $curveOid = "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";

        $body = $version.$privateKeyOctet.$curveOid;
        $der = "\x30".$this->derLength(strlen($body)).$body;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";
    }

    private function ecPublicKeyToPem(string $rawPoint): string
    {
        // SubjectPublicKeyInfo wrapper for a raw P-256 uncompressed point,
        // the format openssl_pkey_get_public/openssl_pkey_derive expect.
        $algId = "\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $bitString = "\x03".$this->derLength(strlen($rawPoint) + 1)."\x00".$rawPoint;

        $body = $algId.$bitString;
        $der = "\x30".$this->derLength(strlen($body)).$body;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * openssl_sign() over an EC key returns a DER ECDSA-Sig-Value
     * (SEQUENCE of two INTEGERs r, s); JOSE/JWS wants the concatenation
     * r||s, each fixed-width 32 bytes, zero-padded or truncated as DER's
     * variable-length INTEGER encoding requires.
     */
    private function derEcdsaToRaw(string $der): string
    {
        $offset = 2; // skip SEQUENCE tag + length byte
        [$r, $offset] = $this->readDerInteger($der, $offset);
        [$s] = $this->readDerInteger($der, $offset);

        return str_pad($r, 32, "\x00", STR_PAD_LEFT).str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readDerInteger(string $der, int $offset): array
    {
        // Tag 0x02 (INTEGER) assumed; length is short-form for the sizes
        // ECDSA P-256 signatures actually produce (<= 33 bytes per integer).
        $offset++; // tag
        $length = ord($der[$offset]);
        $offset++;

        $value = substr($der, $offset, $length);
        // DER INTEGER strips a leading 0x00 sign-padding byte when the
        // high bit of the true value would otherwise read as negative.
        $value = ltrim($value, "\x00");

        return [$value, $offset + $length];
    }
}
