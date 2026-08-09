<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tax\Actions\ConfigureTaxCode;
use App\Modules\Tax\Domain\TaxType;
use App\Modules\Tax\Models\TaxCode;
use App\Support\Audit\Actor;
use App\Support\Rate\RateException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('taxCodeUserAs')) {
    function taxCodeUserAs(bool $withPermission = true): User
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

if (! function_exists('taxCodeActor')) {
    function taxCodeActor(User $user): Actor
    {
        return new Actor((int) $user->getKey(), $user->name);
    }
}

if (! function_exists('taxCodeAttributes')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function taxCodeAttributes(array $overrides = []): array
    {
        return [
            'code' => 'TVA-STD',
            'name' => 'Standard TVA',
            'name_fr' => 'TVA au taux normal',
            'tax_type' => TaxType::Tva->value,
            'rate_bp' => 19_250,
            'direction' => 'output',
            'effective_from' => '2026-01-01',
            ...$overrides,
        ];
    }
}

// ── Blocking-gate discipline ────────────────────────────────────────────

it('seeds no tax codes at all - the accountant configures every row', function () {
    // 03-tax-procurement §7 / 00-core §16: nothing marked NEEDS
    // VERIFICATION is seeded, and the CGI article for the education
    // exemption is unverified. An empty table that refuses to compute is
    // correct; an authoritative-looking wrong rate is not.
    expect(DB::table('tax_codes')->count())->toBe(0);
});

// ── Authorization and audit ─────────────────────────────────────────────

it('refuses to configure a tax code without ledger.configure', function () {
    $user = taxCodeUserAs(withPermission: false);
    actingAs($user);

    app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes(), taxCodeActor($user));
})->throws(AuthorizationException::class);

it('creates a tax code and writes an audit entry naming the actor', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    $taxCode = app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes(), taxCodeActor($user));

    expect($taxCode->code)->toBe('TVA-STD')
        ->and($taxCode->rate_bp)->toBe(19_250)
        ->and($taxCode->rate()->toPercentString())->toBe('19.25')
        ->and($taxCode->tax_type)->toBe(TaxType::Tva);

    $audit = DB::table('audit_logs')
        ->where('auditable_type', TaxCode::class)
        ->where('auditable_id', $taxCode->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->module)->toBe('Tax')
        ->and($audit?->actor_id)->toBe($user->getKey());
});

// ── Validation ──────────────────────────────────────────────────────────

it('rejects an exempt code without its legal reference', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes([
        'code' => 'TVA-EXO',
        'rate_bp' => 0,
        'is_exempt' => true,
    ]), taxCodeActor($user));
})->throws(DomainException::class, 'exemption_legal_ref');

it('rejects an exempt code carrying a non-zero rate', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes([
        'code' => 'TVA-EXO',
        'rate_bp' => 19_250,
        'is_exempt' => true,
        'exemption_legal_ref' => 'CGI art. 128',
    ]), taxCodeActor($user));
})->throws(DomainException::class, 'rate_bp = 0');

it('rejects a code that is both exempt and zero-rated', function () {
    // Distinct states: zero-rated grants input deduction, exempt does not -
    // conflating them corrupts the prorata numerator (03 §5.3).
    $user = taxCodeUserAs();
    actingAs($user);

    app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes([
        'code' => 'TVA-XZ',
        'rate_bp' => 0,
        'is_exempt' => true,
        'is_zero_rated' => true,
        'exemption_legal_ref' => 'CGI art. 128',
    ]), taxCodeActor($user));
})->throws(DomainException::class, 'both exempt and zero-rated');

it('rejects a negative rate through the Rate value object', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes([
        'rate_bp' => -100,
    ]), taxCodeActor($user));
})->throws(RateException::class);

it('rejects an unknown tax type', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    app(ConfigureTaxCode::class)->handle(null, taxCodeAttributes([
        'tax_type' => 'poll_tax',
    ]), taxCodeActor($user));
})->throws(DomainException::class, 'Unknown tax type');

// ── Effective-dating: one version in force per code, per date ───────────

it('rejects a second version whose window overlaps the one in force', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    $action = app(ConfigureTaxCode::class);
    $action->handle(null, taxCodeAttributes(), taxCodeActor($user));

    $action->handle(null, taxCodeAttributes([
        'rate_bp' => 20_000,
        'effective_from' => '2027-01-01',
    ]), taxCodeActor($user));
})->throws(DomainException::class, 'already has a version in force');

it('accepts a successor version once the current one is closed', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    $action = app(ConfigureTaxCode::class);
    $current = $action->handle(null, taxCodeAttributes(), taxCodeActor($user));

    // Close-and-successor, never in-place edit (03 §5.3 immutability).
    $action->handle($current->id, ['effective_to' => '2027-01-01'], taxCodeActor($user));

    $successor = $action->handle(null, taxCodeAttributes([
        'rate_bp' => 20_000,
        'effective_from' => '2027-01-01',
    ]), taxCodeActor($user));

    expect($successor->id)->not->toBe($current->id);

    // Exactly one version in force on each side of the boundary, selected
    // by DOCUMENT date. effective_to is exclusive.
    $before = TaxCode::query()->where('code', 'TVA-STD')->effectiveOn('2026-12-31')->get();
    $after = TaxCode::query()->where('code', 'TVA-STD')->effectiveOn('2027-01-01')->get();

    expect($before)->toHaveCount(1)
        ->and($before->first()?->rate_bp)->toBe(19_250)
        ->and($after)->toHaveCount(1)
        ->and($after->first()?->rate_bp)->toBe(20_000);
});

it('forbids editing rate_bp in place on an existing version', function () {
    // Editing the rate silently rewrites the tax of every historical
    // invoice that snapshotted this version.
    $user = taxCodeUserAs();
    actingAs($user);

    $action = app(ConfigureTaxCode::class);
    $taxCode = $action->handle(null, taxCodeAttributes(), taxCodeActor($user));

    $action->handle($taxCode->id, ['rate_bp' => 20_000], taxCodeActor($user));
})->throws(DomainException::class, 'immutable');

it('forbids editing effective_from and code in place', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    $action = app(ConfigureTaxCode::class);
    $taxCode = $action->handle(null, taxCodeAttributes(), taxCodeActor($user));

    expect(fn () => $action->handle($taxCode->id, ['effective_from' => '2025-01-01'], taxCodeActor($user)))
        ->toThrow(DomainException::class, 'immutable')
        ->and(fn () => $action->handle($taxCode->id, ['code' => 'TVA-NEW'], taxCodeActor($user)))
        ->toThrow(DomainException::class, 'immutable');
});

it('allows renaming and account wiring in place, audited with before and after', function () {
    $user = taxCodeUserAs();
    actingAs($user);

    $action = app(ConfigureTaxCode::class);
    $taxCode = $action->handle(null, taxCodeAttributes(), taxCodeActor($user));

    $updated = $action->handle($taxCode->id, ['name' => 'Standard TVA (renamed)'], taxCodeActor($user));

    expect($updated->name)->toBe('Standard TVA (renamed)');

    $audit = DB::table('audit_logs')
        ->where('auditable_type', TaxCode::class)
        ->where('auditable_id', $taxCode->id)
        ->where('action', 'updated')
        ->first();

    expect($audit)->not->toBeNull();
    $before = json_decode((string) $audit?->before, true);
    expect($before)->toBe(['name' => 'Standard TVA']);
});
