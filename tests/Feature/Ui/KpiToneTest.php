<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * 39 of the 42 x-kpi-card callers still pass the legacy `icon-bg`, which
 * recolours only the badge - so every card SURFACE in the product stayed the
 * default mint and /finance/invoices shipped four identical cards carrying
 * four differently-coloured badges. Mapping the legacy prop onto the right
 * tone here repaints all 42 screens from one change; these tests are what
 * stop the map silently losing an arm.
 */
it('maps every legacy icon-bg value onto the matching surface tone', function (string $iconBg, string $surface): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="'.$iconBg.'" />');

    expect($html)->toContain($surface);
})->with([
    ['bg-primary', 'bg-kpi-green'],
    ['bg-chrome', 'bg-kpi-green'],
    ['bg-badge-teal', 'bg-kpi-green'],
    ['bg-charcoal', 'bg-kpi-green'],
    ['bg-badge-blue', 'bg-kpi-blue'],
    ['bg-badge-orange', 'bg-kpi-amber'],
    ['bg-heritage-yellow', 'bg-kpi-amber'],
    ['bg-heritage-red', 'bg-kpi-pink'],
    ['bg-badge-purple', 'bg-kpi-purple'],
]);

it('lets an explicit tone beat the legacy prop', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" tone="purple" icon-bg="bg-badge-blue" />');

    expect($html)->toContain('bg-kpi-purple');
    expect($html)->not->toContain('bg-kpi-blue border');
});

it('keeps the badge colour the caller chose', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="bg-badge-orange" icon="<i></i>" />');

    expect($html)->toContain('bg-badge-orange');
});

it('falls back to green for an icon-bg nobody mapped', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="bg-something-new" />');

    expect($html)->toContain('bg-kpi-green');
});
