<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Models\User;
use App\Modules\Tax\Actions\ConfigureWithholdingProfile;
use App\Modules\Tax\Actions\ConfigureWithholdingRule;
use App\Modules\Tax\Actions\ResolveWithholding;
use App\Modules\Tax\Domain\WithholdingResolution;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('f1TaxWhUserAs')) {
    function f1TaxWhUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('ledger.configure', 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo('ledger.configure');
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('f1TaxWhActor')) {
    function f1TaxWhActor(User $user): Actor
    {
        return new Actor((int) $user->getKey(), $user->name);
    }
}

if (! function_exists('f1TaxWhRuleAttributes')) {
    /**
     * AIR at 5.5% on services, HT base - the §6.4 worked-example rule.
     * rate_bp in App\Support\Rate scale: 5.5% = 5 500.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function f1TaxWhRuleAttributes(array $overrides = []): array
    {
        return [
            'code' => 'AIR-SVC',
            'name' => 'AIR on services',
            'name_fr' => 'Acompte IR sur prestations',
            'withholding_type' => 'air',
            'rate_bp' => 5_500,
            'base' => 'amount_ht',
            'applies_to' => 'services',
            'priority' => 10,
            'legal_ref' => 'CGI art. 92 (à vérifier)',
            'effective_from' => '2020-01-01',
            ...$overrides,
        ];
    }
}

if (! function_exists('f1TaxWhConfirmedRule')) {
    /**
     * Create AND confirm a rule (wired to a real liability account).
     *
     * @param  array<string, mixed>  $overrides
     */
    function f1TaxWhConfirmedRule(User $user, array $overrides = []): WithholdingRule
    {
        $account = ChartOfAccount::factory()->create();
        $action = app(ConfigureWithholdingRule::class);

        $rule = $action->handle(null, f1TaxWhRuleAttributes([
            'liability_account_id' => $account->id,
            ...$overrides,
        ]), f1TaxWhActor($user));

        return $action->confirm((int) $rule->getKey(), f1TaxWhActor($user));
    }
}

// ── Empty-seed discipline ───────────────────────────────────────────────

it('seeds no withholding rule at all', function () {
    // §6.1: the full rate table NEEDS VERIFICATION; a wrong seeded rate is
    // more dangerous than an empty table that refuses to compute.
    expect(DB::table('withholding_rules')->count())->toBe(0);
});

it('refuses to resolve withholding while no confirmed rule exists', function () {
    // §11.16: a configuration error, never a silent zero withheld - the
    // school is personally liable for tax not withheld.
    app(ResolveWithholding::class)->handle(
        [],
        [['amount_ht' => 1_000_000, 'amount_ttc' => 1_192_500, 'nature' => 'services']],
        '2026-03-01',
    );
})->throws(DomainException::class, 'configure withholding rules');

it('never applies an unconfirmed rule - it does not count as configuration', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);

    // Created but NOT confirmed.
    app(ConfigureWithholdingRule::class)->handle(null, f1TaxWhRuleAttributes(), f1TaxWhActor($user));

    expect(fn () => app(ResolveWithholding::class)->handle(
        [],
        [['amount_ht' => 1_000_000, 'amount_ttc' => 1_192_500, 'nature' => 'services']],
        '2026-03-01',
    ))->toThrow(DomainException::class, 'configure withholding rules');
});

// ── Authorization, activation gates ─────────────────────────────────────

it('refuses to configure a rule without ledger.configure', function () {
    $user = f1TaxWhUserAs(withPermission: false);
    actingAs($user);

    app(ConfigureWithholdingRule::class)->handle(null, f1TaxWhRuleAttributes(), f1TaxWhActor($user));
})->throws(AuthorizationException::class);

it('blocks confirmation while the base is unset - HT vs TTC is unverified', function () {
    // §6.2: base ships NULL (differs by 19.25% of the base per type);
    // a rule with an unset base cannot be activated.
    $user = f1TaxWhUserAs();
    actingAs($user);

    $account = ChartOfAccount::factory()->create();
    $action = app(ConfigureWithholdingRule::class);
    $rule = $action->handle(null, f1TaxWhRuleAttributes([
        'base' => null,
        'liability_account_id' => $account->id,
    ]), f1TaxWhActor($user));

    $action->confirm((int) $rule->getKey(), f1TaxWhActor($user));
})->throws(DomainException::class, 'base');

it('blocks confirmation without legal_ref and without a liability account', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);

    $account = ChartOfAccount::factory()->create();
    $action = app(ConfigureWithholdingRule::class);

    $noLegal = $action->handle(null, f1TaxWhRuleAttributes([
        'code' => 'AIR-A',
        'legal_ref' => null,
        'liability_account_id' => $account->id,
    ]), f1TaxWhActor($user));

    expect(fn () => $action->confirm((int) $noLegal->getKey(), f1TaxWhActor($user)))
        ->toThrow(DomainException::class, 'legal_ref');

    // Distinct priority: this test is about the activation gates, and two
    // same-priority rules over the same lines are (correctly) rejected at
    // save by the tie check.
    $noAccount = $action->handle(null, f1TaxWhRuleAttributes([
        'code' => 'AIR-B',
        'priority' => 11,
    ]), f1TaxWhActor($user));

    expect(fn () => $action->confirm((int) $noAccount->getKey(), f1TaxWhActor($user)))
        ->toThrow(DomainException::class, '447');
});

// ── Append-only versioning (§6.3, same discipline as TaxCode) ───────────

it('forbids editing rate_bp, code, type or effective_from in place', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);

    $action = app(ConfigureWithholdingRule::class);
    $rule = $action->handle(null, f1TaxWhRuleAttributes(), f1TaxWhActor($user));

    foreach ([
        ['rate_bp' => 6_000],
        ['code' => 'AIR-NEW'],
        ['withholding_type' => 'other'],
        ['effective_from' => '2019-01-01'],
    ] as $frozen) {
        expect(fn () => $action->handle((int) $rule->getKey(), $frozen, f1TaxWhActor($user)))
            ->toThrow(DomainException::class, 'immutable');
    }
});

it('rejects an overlapping second version and accepts close-and-successor', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);

    $action = app(ConfigureWithholdingRule::class);
    $current = $action->handle(null, f1TaxWhRuleAttributes(), f1TaxWhActor($user));

    expect(fn () => $action->handle(null, f1TaxWhRuleAttributes([
        'rate_bp' => 6_000,
        'effective_from' => '2027-01-01',
    ]), f1TaxWhActor($user)))->toThrow(DomainException::class, 'already has a version in force');

    $action->handle((int) $current->getKey(), ['effective_to' => '2027-01-01'], f1TaxWhActor($user));

    $successor = $action->handle(null, f1TaxWhRuleAttributes([
        'rate_bp' => 6_000,
        'effective_from' => '2027-01-01',
    ]), f1TaxWhActor($user));

    expect($successor->getKey())->not->toBe($current->getKey());
});

it('keeps exactly one version of a code effective on every day of a 10-year sweep', function () {
    // §11 test obligation 3, the StatutoryRate discipline: three chained
    // versions, then a daily walk over the decade asserting the boundary
    // from both directions (effective_to is EXCLUSIVE).
    $user = f1TaxWhUserAs();
    actingAs($user);

    $action = app(ConfigureWithholdingRule::class);
    $v1 = $action->handle(null, f1TaxWhRuleAttributes(['effective_to' => '2023-07-01']), f1TaxWhActor($user));
    $action->handle(null, f1TaxWhRuleAttributes([
        'rate_bp' => 6_000,
        'effective_from' => '2023-07-01',
        'effective_to' => '2026-01-01',
    ]), f1TaxWhActor($user));
    $action->handle(null, f1TaxWhRuleAttributes([
        'rate_bp' => 5_000,
        'effective_from' => '2026-01-01',
    ]), f1TaxWhActor($user));

    /** @var list<array{from: string, to: string|null}> $windows */
    $windows = WithholdingRule::query()
        ->where('code', 'AIR-SVC')
        ->get()
        ->map(fn (WithholdingRule $rule): array => [
            'from' => $rule->effective_from->toDateString(),
            'to' => $rule->effective_to?->toDateString(),
        ])
        ->all();

    $day = Carbon::parse('2020-01-01');
    $end = Carbon::parse('2029-12-31');

    while ($day->lessThanOrEqualTo($end)) {
        $date = $day->toDateString();

        $matching = array_filter(
            $windows,
            static fn (array $window): bool => $window['from'] <= $date
                && ($window['to'] === null || $date < $window['to']),
        );

        expect(count($matching))->toBe(1, "Expected exactly one version in force on {$date}");

        $day = $day->addDay();
    }

    // Boundary from both directions: 2023-06-30 is v1, 2023-07-01 is v2.
    $before = WithholdingRule::query()->where('code', 'AIR-SVC')->effectiveOn('2023-06-30')->get();
    $after = WithholdingRule::query()->where('code', 'AIR-SVC')->effectiveOn('2023-07-01')->get();

    expect($before)->toHaveCount(1)
        ->and($before->first()?->getKey())->toBe($v1->getKey())
        ->and($after)->toHaveCount(1)
        ->and($after->first()?->rate_bp)->toBe(6_000);
});

// ── Priority ties ───────────────────────────────────────────────────────

it('rejects two rules tying at equal priority for the same lines at save time', function () {
    // §6.4 step 3: ties are a configuration error raised at rule-save.
    $user = f1TaxWhUserAs();
    actingAs($user);

    $action = app(ConfigureWithholdingRule::class);
    $action->handle(null, f1TaxWhRuleAttributes(), f1TaxWhActor($user));

    $action->handle(null, f1TaxWhRuleAttributes([
        'code' => 'AIR-SVC2',
    ]), f1TaxWhActor($user));
})->throws(DomainException::class, 'equal top priority');

it('allows equal priority when supplier conditions differ, but defends against a runtime tie', function () {
    // Different conditions legitimately select different suppliers - but if
    // BOTH match one supplier, resolution must refuse rather than pick.
    $user = f1TaxWhUserAs();
    actingAs($user);

    f1TaxWhConfirmedRule($user, [
        'code' => 'AIR-IND',
        'supplier_condition' => ['supplier_type' => 'individual'],
    ]);
    f1TaxWhConfirmedRule($user, [
        'code' => 'AIR-CARD',
        'supplier_condition' => ['has_contributor_card' => true],
    ]);

    expect(fn () => app(ResolveWithholding::class)->handle(
        ['supplier_type' => 'individual', 'has_contributor_card' => true],
        [['amount_ht' => 1_000_000, 'amount_ttc' => 1_192_500, 'nature' => 'services']],
        '2026-03-01',
    ))->toThrow(DomainException::class, 'tie at top priority');
});

// ── Resolution algorithm (§6.4) ─────────────────────────────────────────

it('reproduces the §6.4 worked example to the franc', function () {
    // IT consultant, service invoice HT 1 200 000, TVA 19.25% = 231 000,
    // TTC 1 431 000. AIR at 5.5% on HT → 66 000 withheld.
    $user = f1TaxWhUserAs();
    actingAs($user);
    $rule = f1TaxWhConfirmedRule($user);

    [$resolution] = app(ResolveWithholding::class)->handle(
        ['supplier_type' => 'individual', 'has_contributor_card' => true, 'niu_status' => 'active'],
        [['amount_ht' => 1_200_000, 'amount_ttc' => 1_431_000, 'nature' => 'services']],
        '2026-03-01',
    );

    expect($resolution->ruleId)->toBe($rule->getKey())
        ->and($resolution->baseAmount)->toBe(1_200_000)
        ->and($resolution->rateBpApplied)->toBe(5_500)
        ->and($resolution->withheldAmount)->toBe(66_000)
        ->and($resolution->reason)->toBeNull()
        ->and(1_431_000 - $resolution->withheldAmount)->toBe(1_365_000);
});

it('applies a TTC base when the rule says amount_ttc', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);
    f1TaxWhConfirmedRule($user, ['base' => 'amount_ttc']);

    [$resolution] = app(ResolveWithholding::class)->handle(
        [],
        [['amount_ht' => 1_200_000, 'amount_ttc' => 1_431_000, 'nature' => 'services']],
        '2026-03-01',
    );

    expect($resolution->baseAmount)->toBe(1_431_000)
        ->and($resolution->withheldAmount)->toBe(78_705); // 1 431 000 × 5.5%
});

it('withholds nothing below the minimum base, recording the reason', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);
    f1TaxWhConfirmedRule($user, ['minimum_base' => 500_000]);

    [$resolution] = app(ResolveWithholding::class)->handle(
        [],
        [['amount_ht' => 499_999, 'amount_ttc' => 596_249, 'nature' => 'services']],
        '2026-03-01',
    );

    expect($resolution->withheldAmount)->toBe(0)
        ->and($resolution->reason)->toBe(WithholdingResolution::REASON_BELOW_THRESHOLD);
});

it('honours an unexpired supplier exemption, recording the reference', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);
    f1TaxWhConfirmedRule($user);

    [$resolution] = app(ResolveWithholding::class)->handle(
        [
            'is_withholding_exempt' => true,
            'withholding_exemption_ref' => 'EXO/2026/077',
            'withholding_exemption_expires_on' => '2026-12-31',
        ],
        [['amount_ht' => 1_200_000, 'amount_ttc' => 1_431_000, 'nature' => 'services']],
        '2026-03-01',
    );

    expect($resolution->ruleId)->toBeNull()
        ->and($resolution->withheldAmount)->toBe(0)
        ->and($resolution->reason)->toBe(WithholdingResolution::REASON_EXEMPT_SUPPLIER)
        ->and($resolution->exemptionRef)->toBe('EXO/2026/077');
});

it('withholds from a supplier whose exemption has expired', function () {
    // §11 test obligation 7: an expired exemption is no exemption.
    $user = f1TaxWhUserAs();
    actingAs($user);
    f1TaxWhConfirmedRule($user);

    [$resolution] = app(ResolveWithholding::class)->handle(
        [
            'is_withholding_exempt' => true,
            'withholding_exemption_ref' => 'EXO/2020/001',
            'withholding_exemption_expires_on' => '2025-12-31',
        ],
        [['amount_ht' => 1_200_000, 'amount_ttc' => 1_431_000, 'nature' => 'services']],
        '2026-03-01',
    );

    expect($resolution->withheldAmount)->toBe(66_000)
        ->and($resolution->reason)->toBeNull();
});

it('flags an unmatched line as unresolved instead of silently withholding nothing', function () {
    // §6.4 step 7 - the flag is what blocks approval without the waive
    // permission downstream (F3's scope enforces the block).
    $user = f1TaxWhUserAs();
    actingAs($user);
    f1TaxWhConfirmedRule($user); // services only

    [$resolution] = app(ResolveWithholding::class)->handle(
        [],
        [['amount_ht' => 800_000, 'amount_ttc' => 954_000, 'nature' => 'goods']],
        '2026-03-01',
    );

    expect($resolution->ruleId)->toBeNull()
        ->and($resolution->isUnresolved())->toBeTrue()
        ->and($resolution->reason)->toBe(WithholdingResolution::REASON_UNRESOLVED);
});

it('filters rules by supplier condition and resolves per line in batch', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);

    // 5% no-card rule outranks the general AIR rule for cardless suppliers.
    f1TaxWhConfirmedRule($user);
    f1TaxWhConfirmedRule($user, [
        'code' => 'NO-CARD',
        'withholding_type' => 'no_contributor_card',
        'rate_bp' => 5_000,
        'applies_to' => 'both',
        'priority' => 20,
        'supplier_condition' => ['has_contributor_card' => false],
    ]);

    $resolutions = app(ResolveWithholding::class)->handle(
        ['has_contributor_card' => false],
        [
            ['amount_ht' => 1_000_000, 'amount_ttc' => 1_192_500, 'nature' => 'services'],
            ['amount_ht' => 400_000, 'amount_ttc' => 477_000, 'nature' => 'goods'],
        ],
        '2026-03-01',
    );

    expect($resolutions)->toHaveCount(2)
        // Services line: NO-CARD (priority 20) beats AIR-SVC (priority 10).
        ->and($resolutions[0]->rateBpApplied)->toBe(5_000)
        ->and($resolutions[0]->withheldAmount)->toBe(50_000)
        // Goods line: only NO-CARD applies.
        ->and($resolutions[1]->withheldAmount)->toBe(20_000);
});

// ── Profiles ────────────────────────────────────────────────────────────

it('groups rules into an ordered profile and rejects duplicate sequences', function () {
    $user = f1TaxWhUserAs();
    actingAs($user);

    $ruleA = f1TaxWhConfirmedRule($user);
    $ruleB = f1TaxWhConfirmedRule($user, ['code' => 'PRECOMPTE', 'withholding_type' => 'precompte_achats', 'rate_bp' => 1_000, 'applies_to' => 'goods', 'priority' => 5]);

    $profile = app(ConfigureWithholdingProfile::class)->handle(null, [
        'code' => 'STD',
        'name' => 'Standard supplier',
        'name_fr' => 'Fournisseur standard',
    ], [
        ['withholding_rule_id' => (int) $ruleA->getKey(), 'sequence' => 1],
        ['withholding_rule_id' => (int) $ruleB->getKey(), 'sequence' => 2],
    ], f1TaxWhActor($user));

    expect($profile->profileRules)->toHaveCount(2);

    expect(fn () => app(ConfigureWithholdingProfile::class)->handle(null, [
        'code' => 'DUP',
        'name' => 'Broken',
        'name_fr' => 'Cassé',
    ], [
        ['withholding_rule_id' => (int) $ruleA->getKey(), 'sequence' => 1],
        ['withholding_rule_id' => (int) $ruleB->getKey(), 'sequence' => 1],
    ], f1TaxWhActor($user)))->toThrow(DomainException::class, 'sequence');
});
