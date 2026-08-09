<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Actions\SavePolicy;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Modules\Welfare\Models\InsurancePolicy;
use App\Support\Audit\Actor;
use Database\Factories\EnrollmentFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 10 W5 insurance suite. Prefix p10Ins,
 * every helper function_exists-guarded (00-core test discipline; names
 * must never collide with another agent's).
 */
if (! function_exists('p10InsUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function p10InsUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p10InsManager')) {
    /** The usual operator: holds both insurance abilities. */
    function p10InsManager(): User
    {
        return p10InsUser(InsurancePermission::VIEW, InsurancePermission::MANAGE);
    }
}

if (! function_exists('p10InsActor')) {
    function p10InsActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('p10InsYearId')) {
    /**
     * The academic year every fixture shares - the same row
     * EnrollmentFactory reuses, so policies and enrollments line up.
     */
    function p10InsYearId(): int
    {
        $existing = DB::table('academic_years')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return DB::table('academic_years')->insertGetId([
            'code' => '2026-2027-'.Str::lower(Str::random(6)),
            'name' => 'Academic Year 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'is_current' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p10InsOtherYearId')) {
    /** A SECOND academic year, for wrong-year enrolment fixtures. */
    function p10InsOtherYearId(): int
    {
        return DB::table('academic_years')->insertGetId([
            'code' => '2027-2028-'.Str::lower(Str::random(6)),
            'name' => 'Academic Year 2027/2028',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-07-31',
            'is_current' => false,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p10InsPolicy')) {
    /**
     * A student policy created through the REAL gate, covering the shared
     * academic year.
     *
     * @param  array<string, mixed>  $overrides
     */
    function p10InsPolicy(User $user, array $overrides = []): InsurancePolicy
    {
        return app(SavePolicy::class)->handle(null, [
            'provider' => 'Activa Assurances',
            'policy_no' => 'POL-'.fake()->unique()->numberBetween(1, 999_999),
            'cover_type' => 'student',
            'premium_per_student' => 5_000,
            'coverage_start' => '2026-09-01',
            'coverage_end' => '2027-07-31',
            'academic_year_id' => p10InsYearId(),
            ...$overrides,
        ], p10InsActor($user));
    }
}

if (! function_exists('p10InsEnrollmentId')) {
    /** An ACTIVE enrollment in the shared academic year. */
    function p10InsEnrollmentId(): int
    {
        p10InsYearId();

        return (int) EnrollmentFactory::new()->createOne()->getKey();
    }
}

if (! function_exists('p10InsFeeItemId')) {
    /**
     * A minimal own-revenue fee item (the premium's billing vehicle,
     * design §14) - Fees module rows inserted via DB::table so this suite
     * never imports another module's Models.
     */
    function p10InsFeeItemId(): int
    {
        // CoA-1/CoA-2 triggers: depth (=code length) > 1 needs a parent
        // whose code is a strict prefix one character shorter, so build the
        // class-7 root first and hang the revenue account under it.
        $rootId = DB::table('chart_of_accounts')->where('code', '7')->value('id');

        if (! is_numeric($rootId)) {
            $rootId = DB::table('chart_of_accounts')->insertGetId([
                'code' => '7',
                'name' => 'Produits',
                'name_fr' => 'Produits',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'is_postable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $accountId = DB::table('chart_of_accounts')->insertGetId([
            'code' => '7'.fake()->unique()->numberBetween(0, 9),
            'parent_id' => (int) $rootId,
            'name' => 'Produits assurance scolaire',
            'name_fr' => 'Produits assurance scolaire',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'is_postable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('fee_categories')->insertGetId([
            'code' => 'INS'.fake()->unique()->numberBetween(100, 999),
            'name' => 'Insurance',
            'name_fr' => 'Assurance',
            'display_order' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('fee_items')->insertGetId([
            'code' => 'INSFEE'.fake()->unique()->numberBetween(100, 999),
            'name' => 'Student Insurance Premium',
            'name_fr' => 'Prime assurance eleve',
            'fee_category_id' => $categoryId,
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => $accountId,
            'recognition_method' => 'on_issue',
            'default_recurrence' => 'per_year',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
