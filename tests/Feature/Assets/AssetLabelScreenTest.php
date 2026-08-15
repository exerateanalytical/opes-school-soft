<?php

declare(strict_types=1);

use App\Modules\Assets\Livewire\Index as AssetsIndex;
use App\Modules\Assets\Livewire\Show as AssetsShow;
use App\Modules\Assets\Models\Asset;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    p13coreDocumentProfile();
});

it('streams a label PDF from the asset detail screen', function (): void {
    // The store keeper is the only role holding asset.view, and this work
    // gave that role documents.print so the label can actually be produced.
    p13coreUserAs(Role::StoreKeeper);

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145']);

    Livewire::test(AssetsShow::class, ['asset' => $asset])
        ->call('printLabel')
        // The tag number's slashes must never reach a Content-Disposition
        // header; DocumentFileName::sanitize is what keeps the download from
        // 500-ing on every asset in the register.
        ->assertFileDownloaded('asset-label-HBC-AST-2026-000145.pdf');
});

it('streams a label sheet for the assets selected on the index', function (): void {
    p13coreUserAs(Role::StoreKeeper);

    $ids = [
        (int) Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000001'])->getKey(),
        (int) Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000002'])->getKey(),
    ];

    Livewire::test(AssetsIndex::class)
        ->set('selectedAssetIds', $ids)
        ->call('printLabelSheet')
        ->assertFileDownloaded();
});

it('reports an error rather than streaming an empty sheet', function (): void {
    p13coreUserAs(Role::StoreKeeper);

    Livewire::test(AssetsIndex::class)
        ->set('selectedAssetIds', [])
        ->call('printLabelSheet')
        ->assertHasErrors('selectedAssetIds')
        ->assertNoFileDownloaded();
});
