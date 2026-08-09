<?php

declare(strict_types=1);

namespace App\Support\Clock;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When did this school's unlicensed TRIAL start?
 * (docs/specs/08-operations.md §4.4: trial = 30 days or 25 students,
 * whichever first; docs/plans/phase-07.md §5 risk 1: the trial clock must be
 * seedable, and "no licence + within trial" MUST be the permissive default.)
 *
 * The anchor is a `settings` row written ONCE, by the installer / first-run
 * wizard (or by a test that wants to age the trial). While no anchor exists
 * the clock has not started: the entitlement gate stays permissive rather
 * than guessing an anchor from data that moves under it - every existing
 * test fixture, and every real school mid-import, would otherwise be aged
 * into an enforced state by a timestamp nobody chose.
 *
 * Deliberately framework-AWARE like BusinessDate (Carbon + the DB facade),
 * so it is intentionally not listed in tests/Architecture/DomainPurityTest.
 */
final class TrialClock
{
    /** Trial length (08-operations §4.4). */
    public const TRIAL_DAYS = 30;

    /** Trial student cap (08-operations §4.4). */
    public const TRIAL_STUDENT_CAP = 25;

    /**
     * How long an over-run trial keeps the permissive grace behaviour before
     * enforcement - mirrors the licence expiry ladder's 30-day grace.
     */
    public const GRACE_DAYS = 30;

    public const SETTING_KEY = 'licensing.trial_started_at';

    /**
     * The trial anchor, or null while the clock has not been started.
     */
    public static function startedAt(): ?Carbon
    {
        $row = DB::table('settings')
            ->where('key', self::SETTING_KEY)
            ->where('scope', 'global')
            ->first();

        if ($row === null || ! is_string($row->value)) {
            return null;
        }

        $decoded = json_decode($row->value, true);

        if (! is_string($decoded) || trim($decoded) === '') {
            return null;
        }

        return Carbon::parse($decoded)->startOfDay();
    }

    /**
     * Start (or move) the trial clock. Idempotent upsert; the installer and
     * the test suite are the only intended callers, which is why this writes
     * the settings row directly rather than through SchoolProfile's
     * WriteSetting door - there is no operator, no audit actor, and no UI
     * involved in starting a clock.
     */
    public static function seed(DateTimeInterface $startedAt): void
    {
        $value = json_encode(Carbon::instance($startedAt)->toDateString(), JSON_THROW_ON_ERROR);

        DB::table('settings')->updateOrInsert(
            ['key' => self::SETTING_KEY, 'scope' => 'global', 'scope_id' => null],
            [
                'value' => $value,
                'default_value' => null,
                'value_type' => 'string',
                'setting_class' => 'operational',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );
    }

    /** True once the anchor exists and 30 days have fully elapsed. */
    public static function timeExhausted(): bool
    {
        $started = self::startedAt();

        return $started !== null
            && Carbon::now()->greaterThan($started->copy()->addDays(self::TRIAL_DAYS)->endOfDay());
    }

    /** True once the anchor exists and 30 + 30 days have fully elapsed. */
    public static function graceExhausted(): bool
    {
        $started = self::startedAt();

        return $started !== null
            && Carbon::now()->greaterThan(
                $started->copy()->addDays(self::TRIAL_DAYS + self::GRACE_DAYS)->endOfDay()
            );
    }

    /** The last day of the trial window, when the clock has been started. */
    public static function trialEndsOn(): ?Carbon
    {
        $started = self::startedAt();

        return $started?->copy()->addDays(self::TRIAL_DAYS);
    }
}
