<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tax\Actions\AssertDocumentIdentityComplete;
use App\Modules\Tax\Actions\ConfirmFiscalIdentity;
use App\Modules\Tax\Actions\CorrectFiscalIdentity;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('f1TaxUserAs')) {
    /**
     * @param  list<string>  $permissions
     */
    function f1TaxUserAs(array $permissions = ['ledger.configure']): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['ledger.configure', 'fiscal_identity.correct'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('f1TaxActor')) {
    function f1TaxActor(User $user): Actor
    {
        return new Actor((int) $user->getKey(), $user->name);
    }
}

if (! function_exists('f1TaxIdentityAttributes')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function f1TaxIdentityAttributes(array $overrides = []): array
    {
        return [
            'legal_name' => 'Collège Bilingue OPES',
            'legal_form' => 'etablissement_prive_laic',
            'niu' => 'M012345678901A',
            'tax_centre_code' => 'CIME-YDE1',
            'tax_centre_name' => 'CIME Yaoundé 1er',
            'tax_centre_type' => 'CIME',
            'tax_regime' => 'reel',
            'tax_regime_effective_from' => '2020-01-01',
            'is_tva_registered' => true,
            'tva_registered_from' => '2020-01-01',
            'ministry_accreditation_number' => 'ARR-2015-0042',
            'ministry_accreditation_authority' => 'MINESEC',
            'ministry_accreditation_date' => '2015-09-01',
            ...$overrides,
        ];
    }
}

// ── Empty-and-blocking birth state ──────────────────────────────────────

it('ships with no fiscal identity row at all', function () {
    // 00-core §16: the wizard row is created by the first confirmation,
    // audited - never seeded by a migration.
    expect(DB::table('fiscal_identities')->count())->toBe(0);
});

it('is a database-enforced singleton - a second row is a constraint violation', function () {
    $user = f1TaxUserAs();
    actingAs($user);
    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    DB::table('fiscal_identities')->insert([
        'id' => 2,
        'legal_name' => 'A second school',
        'is_tva_registered' => false,
        'fiscal_year_end_month' => 12,
        'fiscal_year_end_day' => 31,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

// ── Confirmation ────────────────────────────────────────────────────────

it('refuses to confirm without ledger.configure', function () {
    $user = f1TaxUserAs(permissions: []);
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));
})->throws(AuthorizationException::class);

it('confirms a complete identity, stamping who and when, audited', function () {
    $user = f1TaxUserAs();
    actingAs($user);

    $identity = app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    expect($identity->getKey())->toBe(FiscalIdentity::SINGLETON_ID)
        ->and($identity->niu)->toBe('M012345678901A')
        ->and($identity->isConfirmed())->toBeTrue()
        ->and($identity->fiscal_identity_confirmed_by)->toBe($user->getKey())
        ->and($identity->fiscal_year_end_month)->toBe(12)
        ->and($identity->fiscal_year_end_day)->toBe(31);

    $audit = DB::table('audit_logs')
        ->where('auditable_type', FiscalIdentity::class)
        ->where('auditable_id', $identity->getKey())
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->module)->toBe('Tax')
        ->and($audit?->actor_id)->toBe($user->getKey());
});

it('refuses to confirm an incomplete identity, naming the missing fields', function () {
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['niu' => null, 'tax_centre_name' => null]),
        f1TaxActor($user),
    );
})->throws(DomainException::class, 'niu');

it('refuses TVA registration outside the régime réel', function () {
    // §2.2 invariant 2 - whether simplifié may register is unverified, so
    // only réel is accepted, with the rule text shown.
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['tax_regime' => 'simplifie']),
        f1TaxActor($user),
    );
})->throws(DomainException::class, 'régime réel');

it('refuses a commercial legal form without an RCCM number', function () {
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['legal_form' => 'sarl', 'rccm_number' => null]),
        f1TaxActor($user),
    );
})->throws(DomainException::class, 'RCCM');

it('refuses any fiscal year end other than 31 December', function () {
    // §2.3: OHADA pins the exercice; the field is render-only.
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['fiscal_year_end_month' => 6, 'fiscal_year_end_day' => 30]),
        f1TaxActor($user),
    );
})->throws(DomainException::class, 'OHADA');

it('refuses an over-long or non-alphanumeric NIU but accepts any 14-char shape', function () {
    // NIU format NEEDS VERIFICATION: length + alphanumeric only, so a real
    // NIU of unexpected shape is never rejected.
    $user = f1TaxUserAs();
    actingAs($user);

    expect(fn () => app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['niu' => 'THIS-IS-WAY-TOO-LONG-FOR-A-NIU']),
        f1TaxActor($user),
    ))->toThrow(DomainException::class, 'NIU');

    $identity = app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['niu' => 'X9']),
        f1TaxActor($user),
    );

    expect($identity->niu)->toBe('X9');
});

// ── NIU immutability once confirmed (§2.2 invariant 1) ─────────────────

it('freezes the NIU after confirmation - re-confirming with a new NIU refuses', function () {
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['niu' => 'M999999999999Z']),
        f1TaxActor($user),
    );
})->throws(DomainException::class, 'CorrectFiscalIdentity');

it('blocks a raw model update of a confirmed NIU through the observer', function () {
    // Defence in depth: even code that bypasses the Action cannot slip a
    // NIU change past the model.
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    $identity = FiscalIdentity::current();
    expect($identity)->not->toBeNull();

    $identity?->fill(['niu' => 'HACKED0000000X'])->save();
})->throws(DomainException::class, 'immutable');

it('still allows non-NIU fields to be re-confirmed after confirmation', function () {
    // The freeze is surgical: a tax-centre move is routine configuration.
    $user = f1TaxUserAs();
    actingAs($user);

    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    $identity = app(ConfirmFiscalIdentity::class)->handle(
        f1TaxIdentityAttributes(['tax_centre_name' => 'CIME Yaoundé 2e']),
        f1TaxActor($user),
    );

    expect($identity->tax_centre_name)->toBe('CIME Yaoundé 2e');
});

// ── CorrectFiscalIdentity - the only NIU-change path ────────────────────

it('refuses a correction without the dedicated permission', function () {
    $user = f1TaxUserAs();
    actingAs($user);
    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    app(CorrectFiscalIdentity::class)->handle(
        ['niu' => 'M999999999999Z'],
        'Typo on registration',
        'Attestation NIU ref 2026/123',
        f1TaxActor($user),
    );
})->throws(AuthorizationException::class);

it('refuses a correction without a reason or without a supporting document', function () {
    $user = f1TaxUserAs(['ledger.configure', 'fiscal_identity.correct']);
    actingAs($user);
    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    $correct = app(CorrectFiscalIdentity::class);

    expect(fn () => $correct->handle(['niu' => 'M999999999999Z'], '   ', 'doc-ref', f1TaxActor($user)))
        ->toThrow(DomainException::class, 'reason')
        ->and(fn () => $correct->handle(['niu' => 'M999999999999Z'], 'Typo', '', f1TaxActor($user)))
        ->toThrow(DomainException::class, 'supporting document');
});

it('corrects a confirmed NIU with reason and document, audited, and emits the header-change event', function () {
    $user = f1TaxUserAs(['ledger.configure', 'fiscal_identity.correct']);
    actingAs($user);
    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    // Scoped fake: faking ALL events would also swallow the Eloquent model
    // events the NIU-freeze observer rides on.
    Event::fake(['school.fiscal_identity.changed']);

    $identity = app(CorrectFiscalIdentity::class)->handle(
        ['niu' => 'M999999999999Z'],
        'Typo discovered against the attestation d\'immatriculation',
        'Attestation NIU ref 2026/123',
        f1TaxActor($user),
    );

    expect($identity->niu)->toBe('M999999999999Z');

    Event::assertDispatched('school.fiscal_identity.changed');

    $audit = DB::table('audit_logs')
        ->where('auditable_type', FiscalIdentity::class)
        ->where('action', 'updated')
        ->orderByDesc('id')
        ->first();

    expect($audit)->not->toBeNull();
    $after = json_decode((string) $audit?->after, true);
    expect($after['correction_reason'] ?? null)->toContain('Typo')
        ->and($after['supporting_document_reference'] ?? null)->toBe('Attestation NIU ref 2026/123');
});

// ── AssertDocumentIdentityComplete - §2.2 invariant 5, the print gate ───

it('blocks printing entirely while no fiscal identity exists', function () {
    app(AssertDocumentIdentityComplete::class)->handle();
})->throws(DomainException::class, 'not configured');

it('blocks printing while identity fields are missing, naming them', function () {
    // Bypass the Action deliberately to build the partial row a
    // half-finished wizard would leave: legal_name only.
    DB::table('fiscal_identities')->insert([
        'id' => 1,
        'legal_name' => 'Collège Bilingue OPES',
        'is_tva_registered' => false,
        'fiscal_year_end_month' => 12,
        'fiscal_year_end_day' => 31,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(AssertDocumentIdentityComplete::class)->handle())
        ->toThrow(DomainException::class, 'niu');
});

it('returns the identity for header rendering once complete', function () {
    $user = f1TaxUserAs();
    actingAs($user);
    app(ConfirmFiscalIdentity::class)->handle(f1TaxIdentityAttributes(), f1TaxActor($user));

    $identity = app(AssertDocumentIdentityComplete::class)->handle();

    expect($identity->niu)->toBe('M012345678901A')
        ->and($identity->missingDocumentIdentityFields())->toBe([]);
});
