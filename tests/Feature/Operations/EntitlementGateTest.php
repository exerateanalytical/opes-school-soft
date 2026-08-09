<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Operations\Actions\AssertEntitlement;
use App\Modules\Operations\Actions\Licensing\ActivateOnline;
use App\Modules\Operations\Actions\Licensing\ImportLicenceFile;
use App\Modules\Operations\Actions\Rollover\StartRolloverRun;
use App\Modules\Operations\Domain\Licensing\LicenceState;
use App\Modules\Operations\Licensing\EntitlementBlocked;
use App\Modules\Operations\Models\Licence;
use App\Modules\Students\Models\Student;
use App\Support\Clock\TrialClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/P7F4LicensingTestHelpers.php';

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// The permissive default (phase-07 plan §5 risk 1): no licence row + trial
// clock not started = fully allowed. Every existing test fixture in the
// suite lives in this state; if this breaks, a thousand tests break with it.
// ---------------------------------------------------------------------------

it('treats no-licence-with-no-trial-anchor as the permissive Trial default', function () {
    $evaluation = app(AssertEntitlement::class)->evaluate();

    expect($evaluation->state)->toBe(LicenceState::Trial);
    expect($evaluation->decision()->allows())->toBeTrue();

    // The gate itself must be a no-op in this state.
    app(AssertEntitlement::class)->handle('academics.create_year');
    expect(true)->toBeTrue();
});

it('lets CreateAcademicYear run unlicensed within the trial window', function () {
    $user = p7f4Manager(['academics.manage']);

    TrialClock::seed(Carbon::parse('2026-08-01'));
    Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));

    $year = app(CreateAcademicYear::class)->handle(
        code: '2026-2027',
        name: 'Academic Year 2026/2027',
        startsOn: '2026-09-01',
        endsOn: '2027-08-31',
        actor: $user->toAuditActor(),
    );

    expect($year->exists)->toBeTrue();
});

// ---------------------------------------------------------------------------
// The unlicensed trial ladder: 30 days or 25 students, whichever first
// ---------------------------------------------------------------------------

it('walks the trial ladder: trial -> grace -> enforced on the seeded clock', function () {
    TrialClock::seed(Carbon::parse('2026-01-01'));
    $gate = app(AssertEntitlement::class);

    Carbon::setTestNow(Carbon::parse('2026-01-20'));
    expect($gate->evaluate()->state)->toBe(LicenceState::Trial);

    // Past 30 days: grace - persistent banner, nothing blocked.
    Carbon::setTestNow(Carbon::parse('2026-02-15'));
    expect($gate->evaluate()->state)->toBe(LicenceState::Grace);
    $gate->handle('academics.create_year'); // must not throw

    // Past 30 + 30 days: enforced.
    Carbon::setTestNow(Carbon::parse('2026-03-15'));
    expect($gate->evaluate()->state)->toBe(LicenceState::Enforced);
    expect(fn () => $gate->handle('academics.create_year'))
        ->toThrow(EntitlementBlocked::class);
});

it('drops to Grace when the 25-student cap is passed inside the 30 days - whichever FIRST', function () {
    TrialClock::seed(Carbon::parse('2026-08-01'));
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    Student::factory()->count(26)->create();

    $evaluation = app(AssertEntitlement::class)->evaluate();

    expect($evaluation->studentCount)->toBe(26);
    expect($evaluation->state)->toBe(LicenceState::Grace);
    // Grace warns; it never blocks.
    expect($evaluation->decision()->allows())->toBeTrue();
});

it('never escalates past Grace on a cap breach alone while the clock has not started', function () {
    Student::factory()->count(26)->create();

    $evaluation = app(AssertEntitlement::class)->evaluate();

    // With no anchor date there is nothing to enforce from: warn, never block.
    expect($evaluation->state)->toBe(LicenceState::Grace);
    expect($evaluation->decision()->allows())->toBeTrue();
});

// ---------------------------------------------------------------------------
// §4.4 blocked table: enforced/revoked block EXACTLY the annual operations
// ---------------------------------------------------------------------------

it('blocks year creation, publication and the rollover wizard under an enforced-expired licence', function () {
    $user = p7f4Manager(['academics.manage', 'reports.publish', 'rollover.run']);
    $keys = p7f4Keys();

    app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(['expires_at' => '2027-08-31']), $keys['file']),
        $user->toAuditActor(),
    );

    // 30-day grace long gone.
    Carbon::setTestNow(Carbon::parse('2027-11-01 09:00:00'));
    expect(app(AssertEntitlement::class)->evaluate()->state)->toBe(LicenceState::Enforced);

    expect(fn () => app(CreateAcademicYear::class)->handle(
        '2027-2028', 'AY 2027/2028', '2027-09-01', '2028-08-31', $user->toAuditActor(),
    ))->toThrow(EntitlementBlocked::class);

    // The gate sits before any fixture lookup, so bogus ids prove the order:
    // the refusal is the licence sentence, not a not-found error.
    expect(fn () => app(PublishPeriod::class)->handle(999_999, [999_999], 999_999))
        ->toThrow(EntitlementBlocked::class);

    expect(fn () => app(StartRolloverRun::class)->handle(999_999, 999_999, $user->toAuditActor()))
        ->toThrow(EntitlementBlocked::class);
});

it('keeps allowing everything through Expiring and Grace licence states', function () {
    $user = p7f4Manager(['academics.manage']);
    $keys = p7f4Keys();

    app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(['expires_at' => '2027-08-31']), $keys['file']),
        $user->toAuditActor(),
    );

    $gate = app(AssertEntitlement::class);

    // <= 30 days to expiry: dismissible banner, nothing blocked.
    Carbon::setTestNow(Carbon::parse('2027-08-15'));
    expect($gate->evaluate()->state)->toBe(LicenceState::Expiring);
    $gate->handle('academics.create_year');

    // Expired 10 days: grace - persistent banner, nothing blocked.
    Carbon::setTestNow(Carbon::parse('2027-09-10'));
    expect($gate->evaluate()->state)->toBe(LicenceState::Grace);
    $gate->handle('assessment.publish_period');

    expect(true)->toBeTrue();
});

it('names the blocked operation and distinguishes enforced from revoked in the refusal', function () {
    TrialClock::seed(Carbon::parse('2025-01-01'));
    Carbon::setTestNow(Carbon::parse('2026-01-01'));

    $gate = app(AssertEntitlement::class);

    $messages = [];

    foreach (['academics.create_year', 'assessment.publish_period', 'operations.rollover'] as $operation) {
        try {
            $gate->handle($operation);
            PHPUnit\Framework\Assert::fail('the gate should have refused '.$operation);
        } catch (EntitlementBlocked $blocked) {
            expect($blocked->state)->toBe(LicenceState::Enforced);
            expect($blocked->operation)->toBe($operation);
            $messages[] = $blocked->getMessage();
        }
    }

    // Three operations, three different sentences - the operation name is
    // interpolated, not swallowed.
    expect(array_unique($messages))->toHaveCount(3);

    // And the revoked wording differs from the enforced wording (§4.3:
    // distinct sentences per failure mode).
    expect((string) __('licence.blocked.revoked', ['operation' => 'x']))
        ->not->toBe((string) __('licence.blocked.enforced', ['operation' => 'x']));
});

// ---------------------------------------------------------------------------
// O18 (spec §14): 36 simulated offline months = EXACTLY ONE network call
// ---------------------------------------------------------------------------

it('makes exactly one network call across 36 offline months - the one at activation', function () {
    p7f4Manager();
    $keys = p7f4Keys();
    $fingerprint = p7f4Fingerprint();
    config(['opes.licensing.activation_url' => P7F4_SERVER]);

    $responsePayload = p7f4Payload([
        'fingerprint' => $fingerprint,
        'expires_at' => '2027-08-31',
        // The server ASKS for a re-check long before the 36 months are up;
        // status checks must ignore that entirely (§4.2).
        'next_check_after' => '2026-10-01T00:00:00Z',
        'grace_days' => 30,
    ]);

    Http::fake([
        'licence.opes.test/*' => Http::response([
            'payload' => $responsePayload,
            'signature' => p7f4Sign($responsePayload, $keys['activation']),
        ]),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

    $user = auth()->user();
    assert($user instanceof App\Modules\Identity\Models\User);
    app(ActivateOnline::class)->handle('OPES-KEY-36-MONTHS', $user->toAuditActor());

    $gate = app(AssertEntitlement::class);
    $statesSeen = [];

    foreach (range(1, 36) as $monthsLater) {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00')->addMonths($monthsLater));

        $evaluation = $gate->evaluate();
        $statesSeen[$evaluation->state->value] = true;

        try {
            $gate->handle('academics.create_year');
        } catch (EntitlementBlocked) {
            // Expected once expiry passes grace; the point is that the
            // refusal comes from the CACHED signed payload, not a call home.
        }
    }

    // The whole ladder was walked offline...
    expect(array_keys($statesSeen))->toContain(LicenceState::Valid->value);
    expect(array_keys($statesSeen))->toContain(LicenceState::Enforced->value);

    // ...and the activation POST stayed the ONLY network call ever made,
    // even with a server configured and next_check_after long past.
    Http::assertSentCount(1);
});

// ---------------------------------------------------------------------------
// The never-gated negative space (§4.4): the call-site list is CLOSED
// ---------------------------------------------------------------------------

it('keeps the entitlement gate call-site list closed to the four annual operations', function () {
    $moduleRoot = base_path('app/Modules');
    $callSites = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false || ! str_contains($contents, 'AssertEntitlement::class')) {
            continue;
        }

        $callSites[] = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleRoot) + 1));
    }

    sort($callSites);

    // Exactly the §4.4 list, no more: CreateAcademicYear, PublishPeriod and
    // the rollover wizard door (BulkGenerateDocuments joins when the
    // Documents phase builds it). ANY new name appearing here means someone
    // gated a daily operation - fee collection, attendance, marks, payroll,
    // an export - and that is a spec violation, not a merge conflict.
    expect($callSites)->toBe([
        'Academics/Actions/CreateAcademicYear.php',
        'Assessment/Actions/PublishPeriod.php',
        'Operations/Actions/Rollover/StartRolloverRun.php',
    ]);
});

it('asserts the daily-work Actions never reference the entitlement gate at all', function () {
    // §4.4: "Pest asserts ... that RecordPayment, TakeAttendance, EnterMarks,
    // RunPayroll and every export Action do NOT call the gate." Enumerated
    // by path so a rename is caught by the missing-file guard rather than
    // silently passing.
    $neverGated = [
        'app/Modules/Fees/Actions/RecordPayment.php',
        'app/Modules/Assessment/Actions/SaveMark.php',
        'app/Modules/Assessment/Actions/SaveMarkBatch.php',
    ];

    foreach ($neverGated as $relative) {
        $path = base_path($relative);

        expect(is_file($path))->toBeTrue("{$relative} is expected to exist");

        $contents = file_get_contents($path);
        assert($contents !== false);

        expect(str_contains($contents, 'AssertEntitlement'))
            ->toBeFalse("{$relative} must never call the entitlement gate (08-operations §4.4)");
        expect(str_contains($contents, 'EntitlementBlocked'))
            ->toBeFalse("{$relative} must never even know the gate exists");
    }
});

it('evaluates the gate under a revoked licence as Blocked while the licence row survives', function () {
    $user = p7f4Manager();
    $keys = p7f4Keys();

    $licence = app(ImportLicenceFile::class)->handle(
        p7f4FileEnvelope(p7f4Payload(['expires_at' => '2099-08-31']), $keys['file']),
        $user->toAuditActor(),
    );

    $licence->forceFill(['revoked_at' => Carbon::parse('2027-01-15 09:00:00')])->save();

    $evaluation = app(AssertEntitlement::class)->evaluate();

    expect($evaluation->state)->toBe(LicenceState::Revoked);
    expect($evaluation->decision()->allows())->toBeFalse();
    expect(fn () => app(AssertEntitlement::class)->handle('operations.rollover'))
        ->toThrow(EntitlementBlocked::class);

    // Revocation blocks the four operations; it does not delete data.
    expect(Licence::query()->count())->toBe(1);
});
