<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Licensing;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Operations\Licensing\LicenceKeyType;
use App\Modules\Operations\Licensing\LicenceStatus;
use App\Modules\Operations\Licensing\LicenceVerifier;
use App\Modules\Operations\Models\Licence;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The OPPORTUNISTIC re-check (docs/specs/08-operations.md §4.3). Fired from
 * exactly one place - the Licence panel's mount - and only when a licence
 * is cached, a server is configured, and `next_check_after` has passed.
 * That date is scheduling metadata ONLY: passing it never changes whether
 * the licence is valid (§4.2).
 *
 * On a SIGNED `revoked` or `invalid_key` answer it clears the local licence
 * - the one case that matters. On ANYTHING else - no internet, DNS failure,
 * timeout, 5xx, captive portal, no seats, an unsigned or mis-signed answer
 * - it changes nothing and the school never learns it ran. This method
 * therefore never throws.
 */
final class OpportunisticRecheck
{
    public function __construct(
        private readonly LicenceVerifier $verifier,
        private readonly WriteAuditEntry $audit,
    ) {
    }

    public function handle(): void
    {
        try {
            $this->attempt();
        } catch (Throwable) {
            // Silent by specification: a failed re-check must leave no trace
            // the school can be alarmed by.
        }
    }

    private function attempt(): void
    {
        $licence = Licence::query()->orderByDesc('id')->first();

        if ($licence === null) {
            return;
        }

        $url = config('opes.licensing.activation_url');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $next = $licence->next_check_after;

        if ($next === null || Carbon::now()->lessThan($next)) {
            return;
        }

        /** @var array<string, mixed> $cached */
        $cached = is_array($licence->payload) ? $licence->payload : [];

        $response = Http::timeout(10)->acceptJson()->asJson()->post($url, [
            'action' => 'check',
            'product' => LicenceStatus::PRODUCT,
            // Never the licence key (§4.3) - the id and fingerprint identify us.
            'licence_id' => $cached['licence_id'] ?? null,
            'fingerprint' => $licence->fingerprint,
        ]);

        if (! $response->successful()) {
            return;
        }

        /** @var mixed $payload */
        $payload = $response->json('payload');
        /** @var mixed $signature */
        $signature = $response->json('signature');

        if (! is_array($payload) || $payload === [] || ! is_string($signature)) {
            return;
        }

        /** @var array<string, mixed> $payload */
        if (! $this->verifier->verifyPayload($payload, $signature, LicenceKeyType::Activation)) {
            // An unsigned "revoked" is an instruction from nobody. Ignore.
            return;
        }

        $status = $payload['status'] ?? null;

        if ($status === 'revoked' || $status === 'invalid_key') {
            $licenceId = (int) $licence->getKey();
            Licence::query()->delete();

            $this->audit->handle(
                action: AuditAction::Deleted,
                module: 'Operations',
                auditableType: Licence::class,
                auditableId: $licenceId,
                after: [
                    'reason' => is_string($status) ? $status : 'revoked',
                    'via' => 'opportunistic_recheck',
                ],
                actor: Actor::system(),
            );

            return;
        }

        if ($status === 'ok') {
            // Push the next opportunistic attempt out; nothing else changes -
            // validity comes from the cached signed payload, never from here.
            $nextCheck = $payload['next_check_after'] ?? null;

            if (is_string($nextCheck) && trim($nextCheck) !== '') {
                $licence->forceFill([
                    'next_check_after' => Carbon::parse($nextCheck)->toDateTimeString(),
                ])->save();
            }
        }
    }
}
