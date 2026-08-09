<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Licensing;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Licensing\LicenceKeyType;
use App\Modules\Operations\Licensing\LicenceStatus;
use App\Modules\Operations\Licensing\LicenceVerifier;
use App\Modules\Operations\Licensing\MachineFingerprint;
use App\Modules\Operations\Models\Licence;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The ONLINE route to a licensed state (docs/specs/08-operations.md §4.2):
 * key + machine fingerprint -> signed, machine-bound licence. This is THE
 * ONLY HTTP call in normal licensing operation - "activation requires the
 * internet exactly once"; every later status check verifies the cached
 * response offline.
 *
 * The signed response is verified with the RSA activation public key BEFORE
 * anything is stored - there is no "but it came from our server" exemption
 * (§4.3). The licence key is NEVER logged, never placed in a URL (it
 * travels in the POST body), and never echoed in any error message.
 */
final class ActivateOnline
{
    public function __construct(
        private readonly LicenceVerifier $verifier,
        private readonly WriteAuditEntry $audit,
    ) {
    }

    public function handle(string $licenceKey, Actor $actor): Licence
    {
        Gate::authorize(Permission::LicenceManage->value);

        $url = config('opes.licensing.activation_url');

        if (! is_string($url) || trim($url) === '') {
            throw new DomainException((string) __('licence.activate.no_server'));
        }

        $fingerprint = MachineFingerprint::compute();

        if ($fingerprint === '') {
            // Empty, never random - and NO API call is made (§4.3).
            throw new DomainException((string) __('licence.activate.no_fingerprint'));
        }

        try {
            $response = Http::timeout(15)->acceptJson()->asJson()->post($url, [
                'action' => 'activate',
                'product' => LicenceStatus::PRODUCT,
                'key' => $licenceKey,
                'fingerprint' => $fingerprint,
            ]);
        } catch (ConnectionException) {
            throw new DomainException((string) __('licence.activate.unreachable'));
        }

        if ($response->failed()) {
            $error = $response->json('error');

            throw new DomainException((string) __(match ($error) {
                'invalid_key' => 'licence.activate.invalid_key',
                'no_seats' => 'licence.activate.no_seats',
                default => 'licence.activate.rejected',
            }));
        }

        /** @var mixed $payload */
        $payload = $response->json('payload');
        /** @var mixed $signature */
        $signature = $response->json('signature');

        if (! is_array($payload) || $payload === [] || ! is_string($signature) || trim($signature) === '') {
            throw new DomainException((string) __('licence.activate.malformed_response'));
        }

        /** @var array<string, mixed> $payload */
        if (! $this->verifier->verifyPayload($payload, $signature, LicenceKeyType::Activation)) {
            throw new DomainException((string) __('licence.activate.signature_invalid'));
        }

        if (($payload['product'] ?? null) !== LicenceStatus::PRODUCT) {
            throw new DomainException((string) __('licence.activate.wrong_product'));
        }

        $bound = $payload['fingerprint'] ?? null;

        if (! is_string($bound) || ! hash_equals($bound, $fingerprint)) {
            throw new DomainException((string) __('licence.activate.fingerprint_mismatch'));
        }

        $expires = $this->parseDay($payload['expires_at'] ?? null);

        if ($expires === null) {
            throw new DomainException((string) __('licence.activate.expiry_missing'));
        }

        $nextCheck = $this->parseInstant($payload['next_check_after'] ?? null);

        return DB::transaction(function () use ($payload, $signature, $fingerprint, $expires, $nextCheck, $actor): Licence {
            Licence::query()->delete();

            $licence = Licence::query()->create([
                'payload' => $payload,
                'signature' => $signature,
                'fingerprint' => $fingerprint,
                'source' => Licence::SOURCE_ACTIVATION,
                'expires_at' => $expires->toDateString(),
                'next_check_after' => $nextCheck?->toDateTimeString(),
                'grace_days' => is_int($payload['grace_days'] ?? null) ? $payload['grace_days'] : null,
                'revoked_at' => null,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Operations',
                auditableType: Licence::class,
                auditableId: (int) $licence->getKey(),
                after: [
                    // The licence KEY is deliberately absent (§4.3).
                    'source' => Licence::SOURCE_ACTIVATION,
                    'licence_id' => $payload['licence_id'] ?? null,
                    'expires_at' => $expires->toDateString(),
                ],
                actor: $actor,
            );

            return $licence;
        });
    }

    private function parseDay(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseInstant(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
