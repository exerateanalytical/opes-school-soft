<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Licensing;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Licensing\CanonicalJson;
use App\Modules\Operations\Licensing\LicenceKeyType;
use App\Modules\Operations\Licensing\LicenceStatus;
use App\Modules\Operations\Licensing\LicenceVerifier;
use App\Modules\Operations\Models\Licence;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use JsonException;
use Throwable;

/**
 * The OFFLINE route to a licensed state (docs/specs/08-operations.md §4.2):
 * a signed `.opeslic` file the school imports. Needs the internet NEVER.
 *
 * Envelope: a JSON document `{"payload": {...}, "signature": "base64"}`
 * where the signature is ECDSA P-256/SHA-256 over the CanonicalJson form of
 * `payload`. Verified BEFORE anything is stored; every failure mode throws
 * its own distinct localized sentence (§4.3), and nothing about the file is
 * ever logged beyond the audit trail's non-sensitive summary.
 */
final class ImportLicenceFile
{
    public function __construct(
        private readonly LicenceVerifier $verifier,
        private readonly WriteAuditEntry $audit,
    ) {
    }

    public function handle(string $contents, Actor $actor): Licence
    {
        Gate::authorize(Permission::LicenceManage->value);

        try {
            /** @var mixed $envelope */
            $envelope = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException((string) __('licence.import.not_json'));
        }

        if (! is_array($envelope)) {
            throw new DomainException((string) __('licence.import.not_json'));
        }

        $payload = $envelope['payload'] ?? null;
        $signature = $envelope['signature'] ?? null;

        if (! is_array($payload) || $payload === [] || ! is_string($signature) || trim($signature) === '') {
            throw new DomainException((string) __('licence.import.malformed'));
        }

        /** @var array<string, mixed> $payload */
        if (! $this->verifier->verify(CanonicalJson::encode($payload), $signature, LicenceKeyType::File)) {
            throw new DomainException((string) __('licence.import.signature_invalid'));
        }

        if (($payload['product'] ?? null) !== LicenceStatus::PRODUCT) {
            throw new DomainException((string) __('licence.import.wrong_product'));
        }

        $expires = $this->parseDate($payload['expires_at'] ?? null);

        if ($expires === null) {
            throw new DomainException((string) __('licence.import.expiry_missing'));
        }

        return DB::transaction(function () use ($payload, $signature, $expires, $actor): Licence {
            // One cached licence at a time: importing replaces, never stacks.
            Licence::query()->delete();

            $licence = Licence::query()->create([
                'payload' => $payload,
                'signature' => $signature,
                'fingerprint' => null, // file licences are not machine-bound (§4.2)
                'source' => Licence::SOURCE_FILE,
                'expires_at' => $expires->toDateString(),
                'next_check_after' => null,
                'grace_days' => $this->intOrNull($payload['grace_days'] ?? null),
                'revoked_at' => null,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Operations',
                auditableType: Licence::class,
                auditableId: (int) $licence->getKey(),
                after: [
                    'source' => Licence::SOURCE_FILE,
                    'licence_id' => $payload['licence_id'] ?? null,
                    'expires_at' => $expires->toDateString(),
                ],
                actor: $actor,
            );

            return $licence;
        });
    }

    private function parseDate(mixed $raw): ?Carbon
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

    private function intOrNull(mixed $raw): ?int
    {
        return is_int($raw) ? $raw : null;
    }
}
