<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\MonthlyCollectionTrend;
use App\Modules\Fees\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(ledgerUser());
});

it('returns twelve months ending with the given date, zero-filled', function (): void {
    $action = app(MonthlyCollectionTrend::class);

    $series = $action->handle('2026-10-15');

    expect($series)->toHaveCount(12)
        ->and($series[11]['label'])->toBe('Oct 26')
        ->and($series[0]['label'])->toBe('Nov 25');
});

it('sums cleared, non-voided payments per calendar month', function (): void {
    Payment::factory()->create([
        'value_date' => '2026-09-10',
        'posting_date' => '2026-09-10',
        'amount' => 15_000,
        'clearing_state' => 'cleared',
        'unallocated_amount' => 15_000,
    ]);

    $series = app(MonthlyCollectionTrend::class)->handle('2026-10-01');

    $september = collect($series)->firstWhere('label', 'Sep 26');

    expect($september['amount'])->toBe(15_000);
});

it('pre-computes the same SVG path geometry the chart consumes', function (): void {
    $geometry = app(MonthlyCollectionTrend::class)->handle('2026-10-01');
    $chart = app(MonthlyCollectionTrend::class)->chartGeometry($geometry);

    expect($chart)->toHaveKeys(['path', 'area', 'points']);
});
