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

it('caps each KPI card so a single one cannot span the page', function (): void {
    // NOT minmax(12rem,22rem): auto-fit counts its repetitions by the MAX
    // sizing function, so a 22rem track ceiling would collapse a 1130px row
    // to two columns and wrap five KPIs 2-2-1 beside an empty right half.
    // The track stays 1fr (rows keep filling the width); the ceiling lives
    // on the child, which is what stops one lone card spanning the page.
    $listScreen = (string) file_get_contents(resource_path('views/components/list-screen.blade.php'));
    $dashboard = (string) file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($listScreen)->toContain('minmax(12rem,1fr)');
    expect($listScreen)->toContain('max-w-[22rem]');
    expect($dashboard)->toContain('max-w-[22rem]');
});

it('reserves a two-line label box so a short label does not raise its numeral', function (): void {
    $short = Blade::render('<x-kpi-card label="TOTAL CASES" value="0" />');
    $long = Blade::render('<x-kpi-card label="AWAITING GUARDIAN SIGNATURE" value="0" />');

    expect($short)->toContain('min-h-[2.4em]');
    expect($long)->toContain('min-h-[2.4em]');
});
