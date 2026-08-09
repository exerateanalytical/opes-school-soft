<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Domain\AssetStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AssetTestHelpers.php';

uses(RefreshDatabase::class);

// ── Registration ────────────────────────────────────────────────────────

it('registers a draft asset with an allocator-issued tag and no ledger consequence', function () {
    $baseline = phase9AssetBaseline();

    $asset = phase9AssetRegister($baseline);

    expect($asset->status)->toBe(AssetStatus::Draft)
        ->and($asset->tag_number)->toStartWith('AST/')
        ->and($asset->journal_entry_id)->toBeNull()
        ->and((int) DB::table('journal_entries')->count())->toBe(0);
});

it('is idempotent on the registration key: same key, same asset, no duplicate', function () {
    $baseline = phase9AssetBaseline();

    $first = phase9AssetRegister($baseline, ['idempotency_key' => 'p9f1-reg-1']);
    $second = phase9AssetRegister($baseline, ['idempotency_key' => 'p9f1-reg-1']);

    expect($second->getKey())->toBe($first->getKey())
        ->and(DB::table('assets')->count())->toBe(1);
});

it('enforces tag uniqueness at the database', function () {
    $baseline = phase9AssetBaseline();
    phase9AssetRegister($baseline, ['tag_number' => 'TAG-DUP']);

    expect(fn () => phase9AssetRegister($baseline, ['tag_number' => 'TAG-DUP']))
        ->toThrow(QueryException::class);
});

it('permits many NULL serials but no duplicate serial', function () {
    $baseline = phase9AssetBaseline();
    phase9AssetRegister($baseline);
    phase9AssetRegister($baseline); // second NULL serial - fine

    phase9AssetRegister($baseline, ['serial_number' => 'SN-1']);
    expect(fn () => phase9AssetRegister($baseline, ['serial_number' => 'SN-1']))
        ->toThrow(QueryException::class);
});

it('requires a serial number when the category demands one', function () {
    $baseline = phase9AssetBaseline(categoryOverrides: ['requires_serial_number' => true]);

    phase9AssetRegister($baseline);
})->throws(ValidationException::class);

it('requires fair value for a donated asset and enters it at that value', function () {
    $baseline = phase9AssetBaseline();

    expect(fn () => phase9AssetRegister($baseline, [
        'acquisition_type' => 'donation',
        'acquisition_cost' => null,
    ]))->toThrow(ValidationException::class);

    $donated = phase9AssetRegister($baseline, [
        'acquisition_type' => 'donation',
        'acquisition_cost' => null,
        'fair_value_at_donation' => 30_000_000,
        'supplier_id' => null,
        'donor_id' => $baseline['supplier_id'],
    ]);

    expect($donated->acquisition_cost)->toBe(30_000_000);
});

it('refuses registration under an archived category', function () {
    $baseline = phase9AssetBaseline(categoryOverrides: ['is_archived' => true]);

    phase9AssetRegister($baseline);
})->throws(DomainException::class, 'archived');

// ── A6 / A8 at the database ─────────────────────────────────────────────

it('A6: the CHECK refuses service before acquisition', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    expect(fn () => DB::table('assets')->where('id', $asset->getKey())->update([
        'in_service_date' => '2431-03-01',
        'acquisition_date' => '2431-03-10',
    ]))->toThrow(QueryException::class);
});

it('A8: the CHECK refuses residual_value at or above cost', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    expect(fn () => DB::table('assets')->where('id', $asset->getKey())->update([
        'residual_value' => $asset->acquisition_cost,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('assets')->where('id', $asset->getKey())->update([
        'residual_value' => -1,
    ]))->toThrow(QueryException::class);
});

// ── Capitalisation: A7 snapshot + §4.4 entry ────────────────────────────

it('A7: snapshots the residual as an amount from the category default rate', function () {
    $baseline = phase9AssetBaseline(categoryOverrides: ['default_residual_rate_bp' => 10_000]); // 10%
    $asset = phase9AssetRegister($baseline);

    $capitalised = app(CapitaliseAsset::class)->handle(
        (int) $asset->getKey(), $baseline['actor'], $baseline['date'],
    );

    // floor(35 775 000 x 10%) - stored as an AMOUNT.
    expect($capitalised->residual_value)->toBe(3_577_500)
        // §5.3: policy columns are copies taken now.
        ->and($capitalised->useful_life_months)->toBe(60)
        ->and($capitalised->depreciation_method?->value)->toBe('straight_line')
        ->and($capitalised->prorata_convention?->value)->toBe('monthly');

    // A later category edit must NOT rewrite the snapshot.
    $baseline['category']->forceFill(['useful_life_months' => 120])->save();
    expect($capitalised->refresh()->useful_life_months)->toBe(60);
});

it('posts the §4.4 capitalisation entry: Dr class-2 gross / Cr 4812 with the supplier partner', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    $capitalised = app(CapitaliseAsset::class)->handle(
        (int) $asset->getKey(), $baseline['actor'], $baseline['date'],
    );

    expect($capitalised->journal_entry_id)->not->toBeNull()
        ->and($capitalised->status)->toBe(AssetStatus::InService)
        ->and($capitalised->in_service_date)->toBe($baseline['date'])
        // §5.1: depreciation_start_date = in_service_date.
        ->and($capitalised->depreciation_start_date)->toBe($baseline['date']);

    $lines = phase9AssetEntryLines((int) $capitalised->journal_entry_id);

    expect($lines)->toHaveCount(2);

    [$debit, $credit] = $lines;

    expect($debit->code)->toBe('2442')
        ->and($debit->debit)->toBe(35_775_000)
        ->and($debit->credit)->toBe(0)
        ->and($credit->code)->toBe('4812')
        ->and($credit->credit)->toBe(35_775_000)
        // 481 is collective: the line carries the supplier partner.
        ->and($credit->partner_type)->toBe('supplier')
        ->and($credit->partner_id)->toBe($baseline['supplier_id']);
});

it('capitalises exactly once: a second call returns the asset unchanged with no second entry', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    $action = app(CapitaliseAsset::class);

    $first = $action->handle((int) $asset->getKey(), $baseline['actor'], $baseline['date']);
    $entryCount = (int) DB::table('journal_entries')->count();

    $second = $action->handle((int) $asset->getKey(), $baseline['actor'], $baseline['date']);

    expect($second->journal_entry_id)->toBe($first->journal_entry_id)
        ->and((int) DB::table('journal_entries')->count())->toBe($entryCount);
});

it('capitalises without an in-service date as idle - depreciation cannot start (A9 precondition)', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    $capitalised = app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor']);

    expect($capitalised->status)->toBe(AssetStatus::Idle)
        ->and($capitalised->in_service_date)->toBeNull()
        ->and($capitalised->depreciation_start_date)->toBeNull();
});

it('refuses an in-service date before acquisition with a friendly message', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor'], '2431-03-01');
})->throws(ValidationException::class);

it('refuses to capitalise a zero-cost purchase', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline, ['acquisition_cost' => 0]);

    app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor'], $baseline['date']);
})->throws(ValidationException::class);
