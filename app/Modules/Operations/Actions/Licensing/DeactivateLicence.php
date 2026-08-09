<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Licensing;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Models\Licence;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * docs/specs/08-operations.md §4.3, "Deactivation frees a seat." The LOCAL
 * clear is UNCONDITIONAL - a school moving to a new PC must not be trapped
 * by having no internet on the old one. The seat release is best-effort;
 * where it could not be released the school is told PLAINLY (the
 * seat_released flag drives the §4.3 sentence: "the licence has been
 * removed from this computer, but this computer still counts against your
 * licence; deactivate it in your vendor account"). Saying nothing is how a
 * three-seat school quietly runs out of seats.
 *
 * @phpstan-type DeactivationResult array{seat_released: bool|null}
 */
final class DeactivateLicence
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return array{seat_released: bool|null}  null = not applicable (file
     *                                          licence, which holds no seat).
     */
    public function handle(Actor $actor): array
    {
        Gate::authorize(Permission::LicenceManage->value);

        $licence = Licence::query()->orderByDesc('id')->first();

        if ($licence === null) {
            throw new DomainException((string) __('licence.deactivate.none'));
        }

        /** @var array<string, mixed> $payload */
        $payload = $licence->payload;
        $wasActivation = $licence->source === Licence::SOURCE_ACTIVATION;
        $fingerprint = $licence->fingerprint;
        $licenceId = (int) $licence->getKey();

        // Local clear FIRST, unconditionally - before any network attempt.
        DB::transaction(function () use ($licenceId, $wasActivation, $payload, $actor): void {
            Licence::query()->delete();

            $this->audit->handle(
                action: AuditAction::Deleted,
                module: 'Operations',
                auditableType: Licence::class,
                auditableId: $licenceId,
                after: [
                    'source' => $wasActivation ? Licence::SOURCE_ACTIVATION : Licence::SOURCE_FILE,
                    'licence_id' => $payload['licence_id'] ?? null,
                ],
                actor: $actor,
            );
        });

        if (! $wasActivation) {
            return ['seat_released' => null];
        }

        return ['seat_released' => $this->releaseSeat($payload, $fingerprint)];
    }

    /**
     * Best-effort, never throws: the deactivation already succeeded locally
     * and no network condition may undo or obscure that.
     *
     * @param  array<string, mixed>  $payload
     */
    private function releaseSeat(array $payload, ?string $fingerprint): bool
    {
        $url = config('opes.licensing.activation_url');

        if (! is_string($url) || trim($url) === '' || $fingerprint === null || $fingerprint === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->asJson()->post($url, [
                'action' => 'deactivate',
                'licence_id' => $payload['licence_id'] ?? null,
                'fingerprint' => $fingerprint,
            ]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
