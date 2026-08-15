<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers the single label at CR80', function (): void {
    $row = DB::table('document_templates')->where('code', 'ASSET-LABEL')->first();

    expect($row)->not->toBeNull();

    // CR80 has been defined in PaperSize since the platform shipped and used
    // by nothing; a label is the size it exists for.
    expect($row->paper_size)->toBe('CR80')
        ->and($row->module)->toBe('Assets')
        // A label is a working artefact, not a certificate: no series, no
        // IssuedDocument, no serial burn on a reprint.
        ->and((bool) $row->is_snapshot_backed)->toBeFalse()
        ->and($row->series_code)->toBeNull()
        ->and((bool) $row->carries_barcode)->toBeTrue()
        ->and($row->state_header)->toBe('none')
        ->and($row->blade_view)->toBe('documents.assets.label');
});

it('registers the bulk sheet at A4 and marks it bulk-printable', function (): void {
    $row = DB::table('document_templates')->where('code', 'ASSET-LABEL-SHEET')->first();

    expect($row)->not->toBeNull();

    expect($row->paper_size)->toBe('A4')
        ->and((bool) $row->bulk_printable)->toBeTrue()
        ->and($row->blade_view)->toBe('documents.assets.label-sheet');
});

it('gives neither template a signature role', function (): void {
    // Nobody signs a sticker; a signature line on one is theatre.
    foreach (['ASSET-LABEL', 'ASSET-LABEL-SHEET'] as $code) {
        $roles = DB::table('document_templates')->where('code', $code)->value('signature_roles');

        expect(json_decode((string) $roles, true) ?: [])->toBe([]);
    }
});
