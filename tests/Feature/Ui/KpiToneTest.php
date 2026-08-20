<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * 39 of the 42 x-kpi-card callers still pass the legacy `icon-bg`. Mapping it
 * onto a tone here is what repaints all 42 screens from one change; these
 * tests are what stop the map silently losing an arm.
 *
 * UPDATED 2026-08-20 with the card itself. The tone used to paint the card's
 * WASH; it now paints the 50px solid DISC, because that is what the reference
 * draws - every card is white on the ivory ground and the disc carries the
 * only colour. The assertions moved from `bg-kpi-green` to
 * `bg-kpi-green-solid` for that reason and for no other: the mapping from
 * legacy prop to hue is unchanged and still fully covered.
 */
it('maps every legacy icon-bg value onto the matching disc tone', function (string $iconBg, string $disc): void {
    // Rendered WITH an icon, because the tone now paints the disc and the
    // disc only exists when there is one. All 42 callers pass an icon; a
    // card without one is a card with nothing for the hue to colour.
    $html = Blade::render('<x-kpi-card label="X" value="1" icon="<i></i>" icon-bg="'.$iconBg.'" />');

    expect($html)->toContain($disc);
})->with([
    ['bg-primary', 'bg-kpi-green-solid'],
    ['bg-chrome', 'bg-kpi-green-solid'],
    ['bg-badge-teal', 'bg-kpi-green-solid'],
    ['bg-charcoal', 'bg-kpi-green-solid'],
    ['bg-badge-blue', 'bg-kpi-blue-solid'],
    ['bg-badge-orange', 'bg-kpi-amber-solid'],
    ['bg-heritage-yellow', 'bg-kpi-amber-solid'],
    ['bg-heritage-red', 'bg-kpi-pink-solid'],
    ['bg-badge-purple', 'bg-kpi-purple-solid'],
]);

it('lets an explicit tone beat the legacy prop', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon="<i></i>" tone="purple" icon-bg="bg-badge-blue" />');

    expect($html)->toContain('bg-kpi-purple-solid');
    expect($html)->not->toContain('bg-kpi-blue-solid');
});

it('keeps the HUE the caller chose, mapped onto the canonical palette', function (): void {
    // Was: the literal class the caller passed. The legacy prop is a hue
    // HINT now, not a paint instruction - see the block in kpi-card.blade.php.
    // What the caller chose (amber) is still honoured; what is no longer
    // honoured is the exact off-palette class, which would have left 39
    // screens' discs in ad-hoc colours beside a canonical dashboard.
    $html = Blade::render('<x-kpi-card label="X" value="1" icon-bg="bg-badge-orange" icon="<i></i>" />');

    expect($html)->toContain('bg-kpi-amber-solid');
    expect($html)->not->toContain('bg-badge-orange');
});

it('falls back to green for an icon-bg nobody mapped', function (): void {
    $html = Blade::render('<x-kpi-card label="X" value="1" icon="<i></i>" icon-bg="bg-something-new" />');

    expect($html)->toContain('bg-kpi-green-solid');
});

it('caps each KPI card so a single one cannot span the page', function (): void {
    // NOT minmax(12rem,22rem): auto-fit counts its repetitions by the MAX
    // sizing function, so a 22rem track ceiling would collapse a 1130px row
    // to two columns and wrap five KPIs 2-2-1 beside an empty right half.
    // The track stays 1fr (rows keep filling the width); the ceiling lives
    // on the child, which is what stops one lone card spanning the page.
    $listScreen = (string) file_get_contents(resource_path('views/components/list-screen.blade.php'));

    // 185px, not 12rem. 12rem is 204px at this app's 17px root, which fitted
    // five cards and wrapped a sixth onto a row of its own - /library shipped
    // a 5 + 1 strip. The dashboard reference measures six cards at ~190px
    // across the same content width, so 185 is the floor that reproduces it,
    // and it is the same number the dashboard's own strip uses.
    expect($listScreen)->toContain('minmax(185px,1fr)');
    expect($listScreen)->toContain('max-w-[22rem]');

    // The dashboard's own strip no longer uses this scaffold: it was rebuilt
    // to the reference's auto-fit track with a 185px floor, which solves the
    // same lone-card problem by a different route. The list screens - which
    // is where the 22rem ceiling was actually earning its keep - still do.
    $dashboard = (string) file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($dashboard)->toContain('repeat(auto-fit,minmax(185px,1fr))');
});

it('reserves a two-line label box so a short label does not raise its numeral', function (): void {
    $short = Blade::render('<x-kpi-card label="TOTAL CASES" value="0" />');
    $long = Blade::render('<x-kpi-card label="AWAITING GUARDIAN SIGNATURE" value="0" />');

    // 2.1em, not 2.4: the label went from 12.75px uppercase to the measured
    // 13px sentence case, and the reserved box tracks the type it reserves
    // for. Two lines is still two lines.
    expect($short)->toContain('min-h-[2.1em]');
    expect($long)->toContain('min-h-[2.1em]');
});
