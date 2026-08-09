<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\CloseMaintenanceRequest;
use App\Modules\Assets\Actions\CreateMaintenanceRequest;
use App\Modules\Assets\Actions\RecordCustodyMovement;
use App\Modules\Assets\Domain\MaintenanceResolution;
use App\Modules\Assets\Domain\MaintenanceStatus;
use App\Modules\Assets\Models\Asset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AssetTestHelpers.php';

uses(RefreshDatabase::class);

// ── Custody (§2.3) ──────────────────────────────────────────────────────

it('appends a movement, fills the from-side from the register, and rolls the asset forward', function () {
    $baseline = phase9AssetBaseline();
    $keeperA = phase9AssetStaffId();
    $keeperB = phase9AssetStaffId();

    $asset = phase9AssetRegister($baseline, ['custodian_staff_id' => $keeperA, 'location_id' => 7]);

    $movement = app(RecordCustodyMovement::class)->handle((int) $asset->getKey(), [
        'to_staff_id' => $keeperB,
        'to_location_id' => 9,
        'moved_on' => $baseline['date'],
        'reason' => 'Lab reallocation',
    ], $baseline['actor']);

    expect($movement->from_staff_id)->toBe($keeperA)
        ->and($movement->from_location_id)->toBe(7)
        ->and($movement->to_staff_id)->toBe($keeperB);

    $asset->refresh();

    expect($asset->custodian_staff_id)->toBe($keeperB)
        ->and($asset->location_id)->toBe(9);
});

it('requires a destination and a reason', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    $action = app(RecordCustodyMovement::class);

    expect(fn () => $action->handle((int) $asset->getKey(), [
        'moved_on' => $baseline['date'],
        'reason' => 'No destination',
    ], $baseline['actor']))->toThrow(ValidationException::class);

    expect(fn () => $action->handle((int) $asset->getKey(), [
        'to_staff_id' => phase9AssetStaffId(),
        'moved_on' => $baseline['date'],
        'reason' => '  ',
    ], $baseline['actor']))->toThrow(ValidationException::class);
});

it('keeps the trail append-only: the triggers reject edits and deletes but allow one acknowledgement', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    $movement = app(RecordCustodyMovement::class)->handle((int) $asset->getKey(), [
        'to_staff_id' => phase9AssetStaffId(),
        'moved_on' => $baseline['date'],
        'reason' => 'Initial issue',
    ], $baseline['actor']);

    // Substantive edits refused at the database.
    expect(fn () => DB::table('asset_custody_movements')
        ->where('id', $movement->getKey())
        ->update(['reason' => 'Rewritten history']))->toThrow(QueryException::class);

    expect(fn () => DB::table('asset_custody_movements')
        ->where('id', $movement->getKey())
        ->delete())->toThrow(QueryException::class);

    // The one legal transition.
    $acknowledged = app(RecordCustodyMovement::class)->acknowledge((int) $movement->getKey(), $baseline['actor']);
    expect($acknowledged->acknowledged_at)->not->toBeNull();

    // Idempotent re-acknowledgement returns unchanged.
    $again = app(RecordCustodyMovement::class)->acknowledge((int) $movement->getKey(), $baseline['actor']);
    expect($again->acknowledged_by)->toBe($acknowledged->acknowledged_by);

    // And once acknowledged, even the acknowledgement is immutable.
    expect(fn () => DB::table('asset_custody_movements')
        ->where('id', $movement->getKey())
        ->update(['acknowledged_by' => null]))->toThrow(QueryException::class);
});

it('A12: a written-off asset refuses custody movements', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    $asset->forceFill(['status' => 'written_off'])->save();

    app(RecordCustodyMovement::class)->handle((int) $asset->getKey(), [
        'to_staff_id' => phase9AssetStaffId(),
        'moved_on' => $baseline['date'],
        'reason' => 'Should refuse',
    ], $baseline['actor']);
})->throws(DomainException::class, 'A12');

it('is idempotent on the movement key', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    $keeper = phase9AssetStaffId();

    $data = [
        'to_staff_id' => $keeper,
        'moved_on' => $baseline['date'],
        'reason' => 'Issue to lab',
        'idempotency_key' => 'p9f1-cm-1',
    ];

    $first = app(RecordCustodyMovement::class)->handle((int) $asset->getKey(), $data, $baseline['actor']);
    $second = app(RecordCustodyMovement::class)->handle((int) $asset->getKey(), $data, $baseline['actor']);

    expect($second->getKey())->toBe($first->getKey())
        ->and(DB::table('asset_custody_movements')->count())->toBe(1);
});

// ── Maintenance (§2.4) ──────────────────────────────────────────────────

it('opens a request and closes it as an EXPENSE with a recorded justification', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);

    $request = app(CreateMaintenanceRequest::class)->handle([
        'asset_id' => (int) $asset->getKey(),
        'title' => 'Projector lamp failure',
        'priority' => 'high',
    ], $baseline['actor']);

    expect($request->status)->toBe(MaintenanceStatus::Open);

    $closed = app(CloseMaintenanceRequest::class)->handle(
        (int) $request->getKey(),
        MaintenanceResolution::Expense,
        'Routine repair - no life extension.',
        $baseline['actor'],
        ['actual_cost' => 45_000],
    );

    expect($closed->status)->toBe(MaintenanceStatus::Done)
        ->and($closed->resolution)->toBe(MaintenanceResolution::Expense)
        ->and($closed->actual_cost)->toBe(45_000)
        ->and($closed->closed_at)->not->toBeNull();

    // The register's cost is untouched by an expensed repair.
    expect($asset->refresh()->acquisition_cost)->toBe(35_775_000);
});

it('demands the explicit choice: closing without justification refuses; the CHECK refuses a choiceless done', function () {
    $baseline = phase9AssetBaseline();
    $request = app(CreateMaintenanceRequest::class)->handle([
        'title' => 'Unattributed leak',
    ], $baseline['actor']);

    expect(fn () => app(CloseMaintenanceRequest::class)->handle(
        (int) $request->getKey(), MaintenanceResolution::Expense, '   ', $baseline['actor'],
    ))->toThrow(ValidationException::class);

    // Belt and braces: raw SQL cannot mark it done without a resolution.
    expect(fn () => DB::table('asset_maintenance_requests')
        ->where('id', $request->getKey())
        ->update(['status' => 'done']))->toThrow(QueryException::class);
});

it('capitalises as INCREASE: the asset cost rises by the actual cost', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor'], $baseline['date']);

    $request = app(CreateMaintenanceRequest::class)->handle([
        'asset_id' => (int) $asset->getKey(),
        'title' => 'Engine overhaul extending life',
    ], $baseline['actor']);

    app(CloseMaintenanceRequest::class)->handle(
        (int) $request->getKey(),
        MaintenanceResolution::Capitalise,
        'Full engine replacement extends useful life by 3 years.',
        $baseline['actor'],
        ['actual_cost' => 4_000_000, 'capitalise_as' => 'increase_cost'],
    );

    expect($asset->refresh()->acquisition_cost)->toBe(39_775_000);
});

it('capitalises as COMPONENT: a child asset is created under the parent', function () {
    $baseline = phase9AssetBaseline();
    $asset = phase9AssetRegister($baseline);
    app(CapitaliseAsset::class)->handle((int) $asset->getKey(), $baseline['actor'], $baseline['date']);

    $request = app(CreateMaintenanceRequest::class)->handle([
        'asset_id' => (int) $asset->getKey(),
        'title' => 'New cold room compressor',
    ], $baseline['actor']);

    app(CloseMaintenanceRequest::class)->handle(
        (int) $request->getKey(),
        MaintenanceResolution::Capitalise,
        'Distinct replaceable part with its own life.',
        $baseline['actor'],
        [
            'actual_cost' => 2_000_000,
            'capitalise_as' => 'component',
            'component_name' => 'Compressor',
            'useful_life_months' => 36,
        ],
    );

    /** @var Asset $component */
    $component = Asset::query()->where('parent_asset_id', $asset->getKey())->firstOrFail();

    expect($component->acquisition_cost)->toBe(2_000_000)
        ->and($component->useful_life_months)->toBe(36)
        ->and($component->name)->toBe('Compressor');

    // The parent's own cost is NOT increased in component mode (A11 shape).
    expect($asset->refresh()->acquisition_cost)->toBe(35_775_000);
});

it('refuses a capitalising close without an asset or without a positive actual cost', function () {
    $baseline = phase9AssetBaseline();
    $close = app(CloseMaintenanceRequest::class);

    $orphan = app(CreateMaintenanceRequest::class)->handle([
        'title' => 'No asset named',
    ], $baseline['actor']);

    expect(fn () => $close->handle(
        (int) $orphan->getKey(), MaintenanceResolution::Capitalise, 'Extends life.', $baseline['actor'],
        ['actual_cost' => 1_000],
    ))->toThrow(DomainException::class);

    $asset = phase9AssetRegister($baseline);
    $request = app(CreateMaintenanceRequest::class)->handle([
        'asset_id' => (int) $asset->getKey(),
        'title' => 'Costless capitalisation',
    ], $baseline['actor']);

    expect(fn () => $close->handle(
        (int) $request->getKey(), MaintenanceResolution::Capitalise, 'Extends life.', $baseline['actor'],
    ))->toThrow(ValidationException::class);
});

it('cancels an open request with a reason and no accounting choice', function () {
    $baseline = phase9AssetBaseline();
    $request = app(CreateMaintenanceRequest::class)->handle([
        'title' => 'Reported twice',
    ], $baseline['actor']);

    $cancelled = app(CloseMaintenanceRequest::class)->cancel(
        (int) $request->getKey(), 'Duplicate of another ticket.', $baseline['actor'],
    );

    expect($cancelled->status)->toBe(MaintenanceStatus::Cancelled)
        ->and($cancelled->resolution)->toBeNull();
});

it('refuses maintenance creation without asset.manage', function () {
    phase9AssetUser(); // no abilities

    app(CreateMaintenanceRequest::class)->handle([
        'title' => 'Unauthorized',
    ], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);
