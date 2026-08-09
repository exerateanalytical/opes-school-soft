<?php

declare(strict_types=1);

namespace App\Modules\Operations\Licensing;

use App\Modules\Operations\Domain\Licensing\LicenceState;
use App\Modules\Operations\Models\Licence;
use App\Support\Clock\TrialClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The OFFLINE entitlement state machine (docs/specs/08-operations.md §4.3-
 * §4.4). Every status check runs, in order: canonicalise -> verify the
 * signature -> parse -> assert the product slug -> assert the fingerprint in
 * constant time -> assert expires_at. NO NETWORK CALL, EVER, from this
 * class - there is no start-up ping, no heartbeat, and no grace period that
 * can run out from having no internet. The only HTTP in the whole licensing
 * stack lives in ActivateOnline (once) and the panel-only re-check.
 *
 * A cached row that fails verification does NOT harden the school's
 * position: it falls back to the same unlicensed ladder as having no row at
 * all, with the failure named for the panel. Locking a school out harder
 * for importing a corrupt file than for importing nothing would punish the
 * attempt to pay.
 *
 * Unlicensed ladder (trial = 30 days or 25 students, whichever first;
 * plan §5 risk 1 makes "no licence + trial clock not started" permissive by
 * construction):
 *
 *   clock not started            -> Trial (cap breach alone -> Grace: with
 *                                   no anchor there is no date to enforce
 *                                   from, so it can warn but never block)
 *   within 30 days and <= 25     -> Trial
 *   over, within 30 more days    -> Grace
 *   beyond that                  -> Enforced
 */
final class LicenceStatus
{
    /** The product slug every payload must carry (§4.3). */
    public const PRODUCT = 'opes-school';

    /** §4.4: "Expired — grace (30 days)". Fixed product behaviour: the
     * stored grace_days column is recheck-scheduling metadata only (§4.2). */
    public const GRACE_DAYS = 30;

    /** §4.4: "Expiring (≤ 30 days)". */
    public const EXPIRING_DAYS = 30;

    public function __construct(private readonly LicenceVerifier $verifier = new LicenceVerifier)
    {
    }

    public function evaluate(): LicenceEvaluation
    {
        $licence = Licence::query()->orderByDesc('id')->first();

        if ($licence === null) {
            return $this->unlicensed(null, null);
        }

        $failureKey = $this->verifyCached($licence);

        if ($failureKey !== null) {
            return $this->unlicensed($licence, $failureKey);
        }

        if ($licence->revoked_at !== null) {
            return new LicenceEvaluation(
                state: LicenceState::Revoked,
                licence: $licence,
                trusted: true,
                failureKey: null,
                expiresOn: $this->expiryFrom($licence),
                trialEndsOn: null,
                studentCount: $this->studentCount(),
            );
        }

        $expires = $this->expiryFrom($licence);
        assert($expires !== null); // verifyCached() proved it parses.

        $now = Carbon::now();

        if ($now->greaterThan($expires->copy()->addDays(self::GRACE_DAYS)->endOfDay())) {
            $state = LicenceState::Enforced;
        } elseif ($now->greaterThan($expires->copy()->endOfDay())) {
            $state = LicenceState::Grace;
        } elseif ($now->copy()->addDays(self::EXPIRING_DAYS)->greaterThanOrEqualTo($expires->copy()->startOfDay())) {
            $state = LicenceState::Expiring;
        } else {
            $state = LicenceState::Valid;
        }

        return new LicenceEvaluation(
            state: $state,
            licence: $licence,
            trusted: true,
            failureKey: null,
            expiresOn: $expires,
            trialEndsOn: null,
            studentCount: $this->studentCount(),
        );
    }

    /**
     * §4.3, in order, offline. Returns the lang key of the FIRST failure so
     * every failure mode surfaces as its own distinct localized sentence,
     * or null when the cached row is trustworthy.
     */
    private function verifyCached(Licence $licence): ?string
    {
        /** @var mixed $payload */
        $payload = $licence->payload;

        if (! is_array($payload) || $payload === []) {
            return 'licence.failure.payload_unreadable';
        }

        /** @var array<string, mixed> $payload */
        $keyType = $licence->source === Licence::SOURCE_ACTIVATION
            ? LicenceKeyType::Activation
            : LicenceKeyType::File;

        if (! $this->verifier->verifyPayload($payload, $licence->signature, $keyType)) {
            return $keyType === LicenceKeyType::File
                ? 'licence.failure.file_signature_invalid'
                : 'licence.failure.activation_signature_invalid';
        }

        if (($payload['product'] ?? null) !== self::PRODUCT) {
            return 'licence.failure.wrong_product';
        }

        if ($keyType === LicenceKeyType::Activation) {
            $bound = $payload['fingerprint'] ?? null;
            $ours = MachineFingerprint::compute();

            // hash_equals: constant time (§4.3). An empty local fingerprint
            // can never match - a machine that lost its identity source
            // cannot prove it is the bound machine.
            if (! is_string($bound) || $ours === '' || ! hash_equals($bound, $ours)) {
                return 'licence.failure.fingerprint_mismatch';
            }
        }

        if ($this->expiryFrom($licence) === null) {
            return 'licence.failure.expiry_missing';
        }

        return null;
    }

    /** Expiry from the SIGNED payload - the cache column is a convenience copy. */
    private function expiryFrom(Licence $licence): ?Carbon
    {
        /** @var mixed $payload */
        $payload = $licence->payload;

        if (! is_array($payload)) {
            return null;
        }

        $raw = $payload['expires_at'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function unlicensed(?Licence $untrusted, ?string $failureKey): LicenceEvaluation
    {
        $students = $this->studentCount();
        $overCap = $students > TrialClock::TRIAL_STUDENT_CAP;
        $started = TrialClock::startedAt();

        if ($started === null) {
            // Clock not started: permissive by construction (plan §5 risk 1).
            // A cap breach warns (Grace shows the persistent banner) but has
            // no anchor date to escalate from, so it never reaches Enforced.
            $state = $overCap ? LicenceState::Grace : LicenceState::Trial;
        } elseif (TrialClock::graceExhausted()) {
            $state = LicenceState::Enforced;
        } elseif (TrialClock::timeExhausted() || $overCap) {
            $state = LicenceState::Grace;
        } else {
            $state = LicenceState::Trial;
        }

        return new LicenceEvaluation(
            state: $state,
            licence: $untrusted,
            trusted: false,
            failureKey: $failureKey,
            expiresOn: null,
            trialEndsOn: TrialClock::trialEndsOn(),
            studentCount: $students,
        );
    }

    /**
     * Cross-module READ via DB::table per the module rules - Students owns
     * the model. Counts every student on the books: the §4.4 trial cap is
     * about the size of the school being run unlicensed, not about how many
     * are enrolled this term.
     */
    private function studentCount(): int
    {
        return (int) DB::table('students')->count();
    }
}
