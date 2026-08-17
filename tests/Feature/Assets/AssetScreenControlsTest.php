<?php

declare(strict_types=1);

use App\Modules\Assets\Livewire\Index as AssetsIndex;
use App\Modules\Assets\Livewire\Reports\Index as AssetsReportsIndex;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/*
 * The register's tab strip and the report cluster's tab strip both run a
 * different query per tab, and NONE of them was covered: the Depreciation
 * Runs tab selected `fiscal_years.name`, a column that has never existed,
 * so the tab was a SQL error for every user who pressed it. These tests
 * press every tab, which is enough to catch that class of break because
 * the query runs whether or not it returns rows.
 */

it('renders every tab of the asset register', function (string $tab): void {
    p13coreUserAs(Role::StoreKeeper);

    Livewire::test(AssetsIndex::class)
        ->call('selectTab', $tab)
        ->assertOk();
})->with(['assets', 'maintenance', 'depreciation']);

it('renders every tab of the assets & inventory report', function (string $tab): void {
    p13coreUserAs(Role::Accountant);

    Livewire::test(AssetsReportsIndex::class)
        ->call('selectTab', $tab)
        ->assertOk();
})->with(['register', 'depreciation', 'valuation', 'movements']);
