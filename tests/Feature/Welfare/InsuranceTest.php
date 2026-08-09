<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\EnrollStudentsInPolicy;
use App\Modules\Welfare\Actions\RecordClaim;
use App\Modules\Welfare\Actions\SavePolicy;
use App\Modules\Welfare\Actions\SettleClaim;
use App\Modules\Welfare\Actions\UninsuredStudentsReport;
use App\Modules\Welfare\Domain\ClaimStatus;
use App\Modules\Welfare\Domain\InsuranceCoverType;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Modules\Welfare\Domain\InsurancePolicyStatus;
use App\Modules\Welfare\Domain\InsuranceStatus;
use App\Modules\Welfare\Livewire\Insurance\Index as InsuranceIndex;
use App\Modules\Welfare\Models\StudentInsurance;
use Database\Factories\EnrollmentFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

require_once __DIR__.'/InsuranceTestHelpers.php';

uses(RefreshDatabase::class);

// ── Policy CRUD ─────────────────────────────────────────────────────────

it('creates a student policy through SavePolicy', function () {
    $user = p10InsManager();

    $policy = p10InsPolicy($user, ['policy_no' => 'POL-CREATE-1']);

    expect($policy->policy_no)->toBe('POL-CREATE-1')
        ->and($policy->cover_type)->toBe(InsuranceCoverType::Student)
        ->and($policy->premium_per_student)->toBe(5_000)
        ->and($policy->status)->toBe(InsurancePolicyStatus::Active);

    assertDatabaseHas('insurance_policies', ['policy_no' => 'POL-CREATE-1']);
});

it('updates a policy in place', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    $updated = app(SavePolicy::class)->handle((int) $policy->getKey(), [
        'provider' => 'Saham Assurance',
        'status' => 'cancelled',
    ], p10InsActor($user));

    expect($updated->provider)->toBe('Saham Assurance')
        ->and($updated->status)->toBe(InsurancePolicyStatus::Cancelled)
        ->and($updated->policy_no)->toBe($policy->policy_no);
});

it('rejects a duplicate policy number', function () {
    $user = p10InsManager();
    p10InsPolicy($user, ['policy_no' => 'POL-DUP']);

    p10InsPolicy($user, ['policy_no' => 'POL-DUP']);
})->throws(ValidationException::class);

it('rejects a coverage period that ends before it starts', function () {
    $user = p10InsManager();

    p10InsPolicy($user, [
        'coverage_start' => '2027-07-31',
        'coverage_end' => '2026-09-01',
    ]);
})->throws(ValidationException::class);

it('rejects a student policy without a premium', function () {
    $user = p10InsManager();

    p10InsPolicy($user, ['premium_per_student' => null]);
})->throws(ValidationException::class);

it('rejects an asset policy carrying a per-student premium or fee item', function () {
    $user = p10InsManager();

    p10InsPolicy($user, [
        'cover_type' => 'asset',
        'asset_id' => 42,
        // premium stays from the default fixture - illegal for asset cover
    ]);
})->throws(ValidationException::class);

it('accepts an asset policy with a bare asset id and no premium', function () {
    $user = p10InsManager();

    $policy = p10InsPolicy($user, [
        'cover_type' => 'asset',
        'premium_per_student' => null,
        'asset_id' => 42,
    ]);

    expect($policy->cover_type)->toBe(InsuranceCoverType::Asset)
        ->and($policy->asset_id)->toBe(42)
        ->and($policy->premium_per_student)->toBeNull();
});

it('links a student policy to its billing fee item', function () {
    $user = p10InsManager();
    $feeItemId = p10InsFeeItemId();

    $policy = p10InsPolicy($user, ['fee_item_id' => $feeItemId]);

    expect($policy->fee_item_id)->toBe($feeItemId);
    assertDatabaseHas('insurance_policies', [
        'id' => $policy->getKey(),
        'fee_item_id' => $feeItemId,
    ]);
});

it('rejects a fee item reference that does not exist', function () {
    $user = p10InsManager();

    p10InsPolicy($user, ['fee_item_id' => 999_999]);
})->throws(ValidationException::class);

it('refuses SavePolicy without insurance.manage', function () {
    p10InsUser(InsurancePermission::VIEW);

    app(SavePolicy::class)->handle(null, [], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);

// ── Bulk enrolment (idempotent) ─────────────────────────────────────────

it('bulk-enrols active enrollments and is idempotent on rerun', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);
    $ids = [p10InsEnrollmentId(), p10InsEnrollmentId(), p10InsEnrollmentId()];

    $first = app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), $ids, Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    expect($first)->toBe(['enrolled' => 3, 'already_covered' => 0, 'skipped' => 0]);

    $rerun = app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), $ids, Carbon::parse('2026-09-11'), p10InsActor($user),
    );

    expect($rerun)->toBe(['enrolled' => 0, 'already_covered' => 3, 'skipped' => 0])
        ->and(StudentInsurance::query()->where('policy_id', $policy->getKey())->count())->toBe(3);
});

it('skips enrollments that are not active or belong to another year', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    $active = p10InsEnrollmentId();
    $withdrawn = (int) EnrollmentFactory::new()->withdrawn()->createOne()->getKey();
    $otherYear = (int) EnrollmentFactory::new()
        ->createOne(['academic_year_id' => p10InsOtherYearId()])
        ->getKey();

    $summary = app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(),
        [$active, $withdrawn, $otherYear],
        Carbon::parse('2026-09-10'),
        p10InsActor($user),
    );

    expect($summary)->toBe(['enrolled' => 1, 'already_covered' => 0, 'skipped' => 2]);
});

it('refuses enrolment under an asset policy', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user, [
        'cover_type' => 'asset',
        'premium_per_student' => null,
        'asset_id' => 7,
    ]);

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [p10InsEnrollmentId()], Carbon::parse('2026-09-10'), p10InsActor($user),
    );
})->throws(DomainException::class, 'covers assets');

it('refuses enrolment under a cancelled policy', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user, ['status' => 'cancelled']);

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [p10InsEnrollmentId()], Carbon::parse('2026-09-10'), p10InsActor($user),
    );
})->throws(DomainException::class, 'only an active policy');

it('enforces the enrollment × policy unique key at the database layer', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);
    $enrollmentId = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    // Bypass the Action: the schema itself must refuse double cover.
    DB::table('student_insurances')->insert([
        'enrollment_id' => $enrollmentId,
        'policy_id' => (int) $policy->getKey(),
        'enrolled_on' => '2026-09-12',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('refuses bulk enrolment without insurance.manage', function () {
    $manager = p10InsManager();
    $policy = p10InsPolicy($manager);
    $enrollmentId = p10InsEnrollmentId();

    p10InsUser(InsurancePermission::VIEW); // now signed in without manage

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

// ── Claims lifecycle ────────────────────────────────────────────────────

it('records a claim born submitted and settles it', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);
    $enrollmentId = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    /** @var StudentInsurance $cover */
    $cover = StudentInsurance::query()->where('enrollment_id', $enrollmentId)->firstOrFail();

    $claim = app(RecordClaim::class)->handle(
        (int) $policy->getKey(),
        Carbon::parse('2026-11-03'),
        'Playground fracture, right arm',
        80_000,
        p10InsActor($user),
        studentInsuranceId: (int) $cover->getKey(),
    );

    expect($claim->status)->toBe(ClaimStatus::Submitted)
        ->and($claim->amount_claimed)->toBe(80_000)
        ->and($claim->student_insurance_id)->toBe((int) $cover->getKey());

    $settled = app(SettleClaim::class)->handle(
        (int) $claim->getKey(), ClaimStatus::Settled, 65_000, Carbon::parse('2027-01-15'), p10InsActor($user),
    );

    expect($settled->status)->toBe(ClaimStatus::Settled)
        ->and($settled->amount_settled)->toBe(65_000)
        ->and($settled->settled_on?->toDateString())->toBe('2027-01-15');
});

it('rejects a claim whose incident falls outside the coverage period', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    app(RecordClaim::class)->handle(
        (int) $policy->getKey(), Carbon::parse('2027-08-15'), 'Holiday incident', 10_000, p10InsActor($user),
    );
})->throws(DomainException::class, 'outside');

it('rejects a certificate belonging to a different policy', function () {
    $user = p10InsManager();
    $policyA = p10InsPolicy($user);
    $policyB = p10InsPolicy($user);
    $enrollmentId = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policyA->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    /** @var StudentInsurance $cover */
    $cover = StudentInsurance::query()->where('enrollment_id', $enrollmentId)->firstOrFail();

    app(RecordClaim::class)->handle(
        (int) $policyB->getKey(), Carbon::parse('2026-11-03'), 'Wrong certificate', 10_000,
        p10InsActor($user), studentInsuranceId: (int) $cover->getKey(),
    );
})->throws(DomainException::class, 'different policy');

it('caps settlement at the claimed amount', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    $claim = app(RecordClaim::class)->handle(
        (int) $policy->getKey(), Carbon::parse('2026-11-03'), 'Overpay attempt', 50_000, p10InsActor($user),
    );

    app(SettleClaim::class)->handle(
        (int) $claim->getKey(), ClaimStatus::Settled, 60_000, Carbon::parse('2027-01-15'), p10InsActor($user),
    );
})->throws(ValidationException::class);

it('rejects a claim with no money attached and refuses re-deciding a settled claim', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    $claim = app(RecordClaim::class)->handle(
        (int) $policy->getKey(), Carbon::parse('2026-11-03'), 'Rejected then retried', 30_000, p10InsActor($user),
    );

    $rejected = app(SettleClaim::class)->handle(
        (int) $claim->getKey(), ClaimStatus::Rejected, null, Carbon::parse('2026-12-01'), p10InsActor($user),
    );

    expect($rejected->status)->toBe(ClaimStatus::Rejected)
        ->and($rejected->amount_settled)->toBeNull()
        ->and($rejected->settled_on?->toDateString())->toBe('2026-12-01');

    app(SettleClaim::class)->handle(
        (int) $claim->getKey(), ClaimStatus::Settled, 30_000, Carbon::parse('2027-01-15'), p10InsActor($user),
    );
})->throws(DomainException::class, 'final');

it('refuses claim recording without insurance.manage', function () {
    $manager = p10InsManager();
    $policy = p10InsPolicy($manager);

    p10InsUser(InsurancePermission::VIEW); // now signed in without manage

    app(RecordClaim::class)->handle(
        (int) $policy->getKey(), Carbon::parse('2026-11-03'), 'No permission', 10_000,
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

// ── Uninsured students report ───────────────────────────────────────────

it('lists active enrollments without active cover for the year', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    $insured = [p10InsEnrollmentId(), p10InsEnrollmentId()];
    $uninsured = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), $insured, Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    $report = app(UninsuredStudentsReport::class)->handle(p10InsYearId());

    expect(array_column($report, 'enrollment_id'))->toBe([$uninsured]);
});

it('treats a lapsed certificate as absence of cover', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);
    $enrollmentId = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    expect(app(UninsuredStudentsReport::class)->handle(p10InsYearId()))->toBe([]);

    StudentInsurance::query()
        ->where('enrollment_id', $enrollmentId)
        ->update(['status' => InsuranceStatus::Lapsed]);

    $report = app(UninsuredStudentsReport::class)->handle(p10InsYearId());

    expect(array_column($report, 'enrollment_id'))->toBe([$enrollmentId]);
});

it('treats cover under a cancelled policy as absence of cover', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);
    $enrollmentId = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    app(SavePolicy::class)->handle((int) $policy->getKey(), ['status' => 'cancelled'], p10InsActor($user));

    $report = app(UninsuredStudentsReport::class)->handle(p10InsYearId());

    expect(array_column($report, 'enrollment_id'))->toBe([$enrollmentId]);
});

it('scopes the report to one policy when asked', function () {
    $user = p10InsManager();
    $policyA = p10InsPolicy($user);
    $policyB = p10InsPolicy($user);

    $underA = p10InsEnrollmentId();
    $underB = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policyA->getKey(), [$underA], Carbon::parse('2026-09-10'), p10InsActor($user),
    );
    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policyB->getKey(), [$underB], Carbon::parse('2026-09-10'), p10InsActor($user),
    );

    // Globally, everyone is covered ...
    expect(app(UninsuredStudentsReport::class)->handle(p10InsYearId()))->toBe([]);

    // ... but policy A alone is missing B's student.
    $report = app(UninsuredStudentsReport::class)->handle(p10InsYearId(), (int) $policyA->getKey());

    expect(array_column($report, 'enrollment_id'))->toBe([$underB]);
});

it('refuses the report without insurance.view', function () {
    p10InsUser(); // no abilities at all

    app(UninsuredStudentsReport::class)->handle(1);
})->throws(AuthorizationException::class);

// ── Insurance Index screen ──────────────────────────────────────────────

it('renders the insurance screen for a viewer, on every tab', function () {
    $user = p10InsManager();
    $policy = p10InsPolicy($user, ['policy_no' => 'POL-SCREEN-1']);
    $enrollmentId = p10InsEnrollmentId();

    app(EnrollStudentsInPolicy::class)->handle(
        (int) $policy->getKey(), [$enrollmentId], Carbon::parse('2026-09-10'), p10InsActor($user),
    );
    app(RecordClaim::class)->handle(
        (int) $policy->getKey(), Carbon::parse('2026-11-03'), 'Screen fixture claim', 25_000, p10InsActor($user),
    );

    $component = Livewire::test(InsuranceIndex::class);
    $component->assertOk()
        ->assertSee('Student Insurance')
        ->assertSee('POL-SCREEN-1');
    $component->call('selectTab', 'insured')->assertOk();
    $component->call('selectTab', 'claims')
        ->assertOk()
        ->assertSee('Screen fixture claim');
    $component->call('selectTab', 'uninsured')->assertOk();
});

it('forbids the insurance screen without insurance.view', function () {
    p10InsUser(); // no abilities at all

    Livewire::test(InsuranceIndex::class)->assertForbidden();
});
