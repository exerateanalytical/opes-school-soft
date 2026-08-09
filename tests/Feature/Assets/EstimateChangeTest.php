<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\ChangeDepreciationEstimate;
use App\Modules\Assets\Actions\DisposeAsset;
use App\Modules\Assets\Models\DepreciationSchedule;

require_once __DIR__.'/DepreciationTestHelpers.php';

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * 06-assets-stores.md §5.5 - change in estimate, applied prospectively by
 * the entitlement arithmetic. The headline case: an extension of the
 * useful life below what history already charged produces a NEGATIVE
 * charge - a credit to 681x - in the next run.
 */
it('posts a negative catch-up charge after a life extension (§5.5)', function (): void {
    // 35 775 000 over 120 months from 1 September; two periods posted.
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    phase9DeprRunPosted($baseline, 9);
    phase9DeprRunPosted($baseline, 10); // Σ posted = 596 250

    app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        480, // 10 → 40 years
        null,
        'Engineering review: chassis certified for 40 years',
        $baseline['actor'],
    );

    $november = phase9DeprRunPosted($baseline, 11);

    // New entitlement at month 3 of 480 = round(35 775 000 × 3/480)
    // = 223 594 → charge = 223 594 − 596 250 = −372 656.
    /** @var DepreciationSchedule $row */
    $row = DepreciationSchedule::query()
        ->where('depreciation_run_id', (int) $november->getKey())
        ->firstOrFail();

    expect($row->charge)->toBe(223_594 - 596_250)
        ->and($row->charge)->toBeLessThan(0)
        ->and($row->is_catch_up)->toBeTrue()
        ->and($november->total_charge)->toBe(223_594 - 596_250);

    // The journal pair FLIPS: credit 6811, debit 28 (signed lines).
    $lines = phase9AssetEntryLines((int) $november->journal_entry_id);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('6811')
        ->and($lines[0]->credit)->toBe(372_656)
        ->and($lines[0]->debit)->toBe(0)
        ->and($lines[1]->code)->toBe('28')
        ->and($lines[1]->debit)->toBe(372_656);
});

it('absorbs a shortened life prospectively over the remaining months', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    phase9DeprRunPosted($baseline, 9); // 298 125 posted

    app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        12, // drastic shortening
        null,
        'Corrosion found: one year of service left',
        $baseline['actor'],
    );

    $october = phase9DeprRunPosted($baseline, 10);

    // Entitlement at month 2 of 12 = round(35 775 000 × 2/12) = 5 962 500;
    // charge = 5 962 500 − 298 125.
    expect($october->total_charge)->toBe(5_962_500 - 298_125);
});

it('requires a reason and writes the audit trail (§5.5)', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    expect(fn () => app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        240,
        null,
        '   ',
        $baseline['actor'],
    ))->toThrow(Illuminate\Validation\ValidationException::class);

    app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        240,
        1_000_000,
        'Board-approved revision',
        $baseline['actor'],
    );

    $asset->refresh();

    expect($asset->useful_life_months)->toBe(240)
        ->and($asset->residual_value)->toBe(1_000_000);

    /** @var object{after: string} $audit */
    $audit = DB::table('audit_logs')
        ->where('auditable_type', App\Modules\Assets\Models\Asset::class)
        ->where('auditable_id', (int) $asset->getKey())
        ->orderByDesc('id')
        ->firstOrFail(['after']);

    expect((string) $audit->after)->toContain('estimate_changed')
        ->and((string) $audit->after)->toContain('Board-approved revision');
});

it('rejects invalid estimates and frozen assets', function (): void {
    $baseline = phase9DeprBaseline();
    $asset = phase9DeprAsset($baseline);

    // Residual must stay below cost (A8).
    expect(fn () => app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        null,
        35_775_000,
        'Impossible residual',
        $baseline['actor'],
    ))->toThrow(Illuminate\Validation\ValidationException::class);

    // Nothing-to-change is refused.
    expect(fn () => app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        null,
        null,
        'No change',
        $baseline['actor'],
    ))->toThrow(Illuminate\Validation\ValidationException::class);

    // A disposed asset's estimates are history (A12).
    app(DisposeAsset::class)->handle((int) $asset->getKey(), [
        'disposal_type' => 'scrap',
        'disposal_date' => $baseline['year'].'-11-30',
        'reason' => 'Scrapped',
    ], $baseline['actor']);

    expect(fn () => app(ChangeDepreciationEstimate::class)->handle(
        (int) $asset->getKey(),
        240,
        null,
        'Too late',
        $baseline['actor'],
    ))->toThrow(DomainException::class, 'A12');
});
