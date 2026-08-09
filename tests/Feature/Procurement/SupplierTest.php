<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ArchiveSupplier;
use App\Modules\Procurement\Actions\SaveSupplier;
use App\Modules\Procurement\Actions\SaveSupplierCategory;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\Supplier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

// ── Creation & identity ─────────────────────────────────────────────────

it('allocates FRN codes from the row-locked sequence and stores the record', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);

    $first = f2ProcSupplier([], $manager);
    $second = f2ProcSupplier([], $manager);

    expect($first->code)->toStartWith('FRN/')
        ->and($second->code)->not->toBe($first->code)
        ->and($first->supplier_type->value)->toBe('company')
        ->and($first->niu_status->value)->toBe('unknown');
});

it('refuses a save without the manage permission', function () {
    f2ProcUser(ProcurementPermission::VIEW);

    app(SaveSupplier::class)->handle([
        'name' => 'Unauthorised Vendor',
        'supplier_type' => 'company',
        'payable_account_id' => f2ProcPayableAccountId(),
    ], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);

it('refuses a payable account outside the collective 401/481 families', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);

    // 6-family expense account: postable but neither collective nor payable.
    $expenseId = f2ProcExpenseAccountId();

    app(SaveSupplier::class)->handle([
        'name' => 'Wrong Account Trader',
        'supplier_type' => 'individual',
        'payable_account_id' => $expenseId,
    ], f2ProcActor($manager));
})->throws(ValidationException::class);

it('encrypts the bank RIB at rest and derives its blind index server-side', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);

    $supplier = f2ProcSupplier([
        'bank_account_rib' => '10023-00123-45678901234-56',
        // A forged bidx must be ignored - it is derived, never accepted.
        'bank_account_rib_bidx' => str_repeat('f', 64),
    ], $manager);

    /** @var object{bank_account_rib: string, bank_account_rib_bidx: string} $raw */
    $raw = DB::table('suppliers')->where('id', $supplier->id)->first(['bank_account_rib', 'bank_account_rib_bidx']);

    // Ciphertext, not plaintext, in the column (00-core 9.5)...
    expect($raw->bank_account_rib)->not->toContain('10023')
        // ...and the stored bidx is the canonical derivation, not the forgery.
        ->and($raw->bank_account_rib_bidx)->toBe(Supplier::blindIndexFor('10023-00123-45678901234-56'))
        ->and($supplier->refresh()->bank_account_rib)->toBe('10023-00123-45678901234-56');
});

// ── §3.2 duplicate prevention ───────────────────────────────────────────

it('hard-blocks an exact NIU duplicate at save', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    f2ProcSupplier(['niu' => 'M012345678901A'], $manager);

    f2ProcSupplier(['niu' => 'M012345678901A', 'name' => 'A Very Different Name'], $manager);
})->throws(ValidationException::class);

it('hard-blocks a duplicate bank account even under a different name and punctuation', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    f2ProcSupplier(['bank_account_rib' => '10023 00123 45678901234 56'], $manager);

    // Same account, different transcription, different vendor name - the
    // §3.2 fraud shape. The blind index canonicalises and catches it.
    f2ProcSupplier([
        'name' => 'Etablissements Nouveaux',
        'bank_account_rib' => '10023-00123-45678901234-56',
    ], $manager);
})->throws(ValidationException::class);

it('refuses the duplicate override without the dedicated permission', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    f2ProcSupplier(['niu' => 'M012345678901B'], $manager);

    app(SaveSupplier::class)->handle([
        'name' => 'Second Record',
        'supplier_type' => 'company',
        'payable_account_id' => f2ProcPayableAccountId(),
        'niu' => 'M012345678901B',
    ], f2ProcActor($manager), overrideDuplicate: true, overrideReason: 'two branches share a NIU');
})->throws(AuthorizationException::class);

it('cannot override past the database NIU unique index - the DB is the backstop', function () {
    // The override permission opens the APPLICATION gate, but UNIQUE(niu)
    // is absolute: an identical NIU physically cannot be stored twice
    // (test obligation 5's fails-at-the-database discipline). Overrides
    // exist for the bidx and name tiers, where distinct records can be
    // legitimate.
    $manager = f2ProcUser(
        ProcurementPermission::SUPPLIER_MANAGE,
        ProcurementPermission::SUPPLIER_OVERRIDE_DUPLICATE,
    );
    f2ProcSupplier(['niu' => 'M012345678901C'], $manager);

    app(SaveSupplier::class)->handle([
        'name' => 'Genuinely Distinct Branch',
        'supplier_type' => 'company',
        'payable_account_id' => f2ProcPayableAccountId(),
        'niu' => 'M012345678901C',
    ], f2ProcActor($manager), overrideDuplicate: true, overrideReason: 'verified distinct branch, shared NIU');
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

it('permits an overridden duplicate bank account with permission and reason', function () {
    $manager = f2ProcUser(
        ProcurementPermission::SUPPLIER_MANAGE,
        ProcurementPermission::SUPPLIER_OVERRIDE_DUPLICATE,
    );
    f2ProcSupplier(['bank_account_rib' => '99887-00123-11111111111-22'], $manager);

    $second = app(SaveSupplier::class)->handle([
        'name' => 'Distinct Vendor Same Bank Account',
        'supplier_type' => 'company',
        'payable_account_id' => f2ProcPayableAccountId(),
        'bank_account_rib' => '99887-00123-11111111111-22',
    ], f2ProcActor($manager), overrideDuplicate: true, overrideReason: 'factoring arrangement - same collection account');

    expect($second->exists)->toBeTrue()
        ->and(DB::table('audit_logs')->where('after', 'like', '%factoring arrangement%')->exists())->toBeTrue();
});

it('blocks a same-name-after-normalisation save until confirmed', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    f2ProcSupplier(['name' => 'Société Générale de Fournitures'], $manager);

    $attempt = fn () => app(SaveSupplier::class)->handle([
        'name' => 'SOCIETE GENERALE DE FOURNITURES', // accents/case stripped -> identical
        'supplier_type' => 'company',
        'payable_account_id' => f2ProcPayableAccountId(),
    ], f2ProcActor($manager));

    expect($attempt)->toThrow(ValidationException::class);

    // The same save WITH the explicit confirmation goes through - similarity
    // is a question, not a wall (§3.2).
    $confirmed = app(SaveSupplier::class)->handle([
        'name' => 'SOCIETE GENERALE DE FOURNITURES',
        'supplier_type' => 'company',
        'payable_account_id' => f2ProcPayableAccountId(),
    ], f2ProcActor($manager), confirmSimilar: true);

    expect($confirmed->exists)->toBeTrue();
});

// ── Deletion & archive (§9) ─────────────────────────────────────────────

it('never deletes a supplier - the model observer throws', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $manager);

    $supplier->delete();
})->throws(RuntimeException::class);

it('archives instead, keeping the code and flagging the row', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    $supplier = f2ProcSupplier([], $manager);

    $archived = app(ArchiveSupplier::class)->handle($supplier->id, f2ProcActor($manager), 'ceased trading');

    expect($archived->is_archived)->toBeTrue()
        ->and($archived->is_active)->toBeFalse()
        ->and($archived->blocked_reason)->toBe('ceased trading')
        ->and($archived->code)->toBe($supplier->code);
});

it('requires the exemption certificate reference when marking a supplier withholding-exempt', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);

    f2ProcSupplier(['is_withholding_exempt' => true], $manager);
})->throws(ValidationException::class);

// ── Categories ──────────────────────────────────────────────────────────

it('creates a supplier category and refuses a duplicate code at the database', function () {
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);

    app(SaveSupplierCategory::class)->handle(['code' => 'STATIONERY', 'name' => 'Stationery'], f2ProcActor($manager));

    expect(fn () => app(SaveSupplierCategory::class)->handle(
        ['code' => 'STATIONERY', 'name' => 'Stationery again'],
        f2ProcActor($manager),
    ))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
