<?php

declare(strict_types=1);

use App\Support\Ledger\SourceReference;
use Illuminate\Support\Facades\Blade;

/**
 * The drill-down component, docs/specs/2026-08-12-accounting-finance-architecture.md §6.
 *
 * No database: a SourceReference is a plain value object, so these assertions
 * are about rendering alone.
 */
it('renders a resolvable reference as an anchor', function () {
    $html = Blade::render(
        '<x-accounting.source-link :reference="$reference" />',
        ['reference' => SourceReference::linked('Expense #7', 'https://example.test/accounting/expenses/7')],
    );

    expect($html)->toContain('href="https://example.test/accounting/expenses/7"');
    expect($html)->toContain('Expense #7');
});

it('renders an inert reference without an anchor', function () {
    $html = Blade::render(
        '<x-accounting.source-link :reference="$reference" />',
        ['reference' => SourceReference::inert('Manual entry — no source document')],
    );

    expect($html)->not->toContain('<a ');
    expect($html)->not->toContain('href');
    expect($html)->toContain('Manual entry');
});

it('escapes a label so a document reference cannot inject markup', function () {
    $html = Blade::render(
        '<x-accounting.source-link :reference="$reference" />',
        ['reference' => SourceReference::inert('<script>alert(1)</script>')],
    );

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
});
