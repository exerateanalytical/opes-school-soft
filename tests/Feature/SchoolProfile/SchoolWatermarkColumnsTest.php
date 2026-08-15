<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the four school-watermark columns', function (): void {
    foreach ([
        'watermark_enabled', 'watermark_text', 'watermark_image_path', 'watermark_opacity',
    ] as $column) {
        expect(Schema::hasColumn('school_document_profiles', $column))
            ->toBeTrue("column [{$column}] is missing");
    }
});

it('defaults to disabled, so no existing install changes what it prints', function (): void {
    // Enabling this by default would silently restyle every document a live
    // school prints tomorrow morning.
    DB::table('school_document_profiles')->insert([
        'id' => 1, 'state_header_enabled' => false, 'bilingual_documents' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = DB::table('school_document_profiles')->where('id', 1)->first();

    expect((bool) $row->watermark_enabled)->toBeFalse()
        ->and($row->watermark_text)->toBeNull()
        ->and((int) $row->watermark_opacity)->toBe(8);
});
