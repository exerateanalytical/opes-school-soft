<?php

declare(strict_types=1);

use App\Modules\Assets\Actions\PrintAssetLabel;
use App\Modules\Assets\Models\Asset;
use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Domain\AssetTagBarcode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('renders a single label carrying the tag and its barcode', function (): void {
    p13coreUserAs(Role::StoreKeeper);
    p13coreDocumentProfile();

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145', 'name' => 'Epson Projector']);

    $doc = app(PrintAssetLabel::class)->handle((int) $asset->getKey());

    expect($doc->html)
        ->toContain('HBC/AST/2026/000145')
        ->toContain('Epson Projector')
        ->toContain('data:image/png;base64,');

    // Live document: no serial burned, no IssuedDocument written.
    expect($doc->serial)->toBeNull()
        ->and($doc->issuedDocumentId)->toBeNull();
});

it('prints a legacy tag with no barcode rather than an invented one', function (): void {
    p13coreUserAs(Role::StoreKeeper);
    p13coreDocumentProfile();

    $asset = Asset::factory()->create(['tag_number' => 'OLD LAB MICROSCOPE 4', 'name' => 'Microscope']);

    expect(AssetTagBarcode::tryFromCanonical('OLD LAB MICROSCOPE 4'))->toBeNull();

    $doc = app(PrintAssetLabel::class)->handle((int) $asset->getKey());

    expect($doc->html)->toContain('OLD LAB MICROSCOPE 4');
    expect($doc->html)->not->toContain('data:image/png;base64,');
});

it('burns no series number even across repeated prints', function (): void {
    p13coreUserAs(Role::StoreKeeper);
    p13coreDocumentProfile();

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145']);

    app(PrintAssetLabel::class)->handle((int) $asset->getKey());
    app(PrintAssetLabel::class)->handle((int) $asset->getKey());

    // Two print-log rows (every render is logged), zero issued documents.
    expect(DB::table('issued_documents')->count())->toBe(0)
        ->and(DB::table('document_print_logs')->count())->toBe(2);
});

it('refuses a caller without asset.view', function (): void {
    p13coreUserAs(Role::Teacher);
    p13coreDocumentProfile();

    $asset = Asset::factory()->create(['tag_number' => 'HBC/AST/2026/000145']);

    app(PrintAssetLabel::class)->handle((int) $asset->getKey());
})->throws(AuthorizationException::class);

it('renders a sheet of labels for a set of assets', function (): void {
    p13coreUserAs(Role::StoreKeeper);
    p13coreDocumentProfile();

    $ids = [];

    foreach (['HBC/AST/2026/000001', 'HBC/AST/2026/000002', 'HBC/AST/2026/000003'] as $tag) {
        $ids[] = (int) Asset::factory()->create(['tag_number' => $tag])->getKey();
    }

    $doc = app(PrintAssetLabel::class)->sheet($ids);

    expect($doc->html)
        ->toContain('HBC/AST/2026/000001')
        ->toContain('HBC/AST/2026/000002')
        ->toContain('HBC/AST/2026/000003')
        ->toContain('3 labels');
});

it('refuses an empty sheet rather than printing a blank page', function (): void {
    p13coreUserAs(Role::StoreKeeper);
    p13coreDocumentProfile();

    app(PrintAssetLabel::class)->sheet([]);
})->throws(DomainException::class);

it('caps a sheet so one click cannot render ten thousand labels', function (): void {
    p13coreUserAs(Role::StoreKeeper);
    p13coreDocumentProfile();

    app(PrintAssetLabel::class)->sheet(range(1, PrintAssetLabel::SHEET_LIMIT + 1));
})->throws(DomainException::class, 'at a time');
