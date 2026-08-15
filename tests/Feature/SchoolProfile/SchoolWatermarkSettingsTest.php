<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\DocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('saves a text watermark with its opacity', function (): void {
    // Administrator, not Principal: the settings screens are gated on
    // setting.edit, which Principal does not hold.
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkText', 'HERITAGE BILINGUAL COLLEGE')
        ->set('watermarkOpacity', 12)
        ->call('save')
        ->assertHasNoErrors();

    $row = DB::table('school_document_profiles')->where('id', 1)->first();

    expect((bool) $row->watermark_enabled)->toBeTrue()
        ->and($row->watermark_text)->toBe('HERITAGE BILINGUAL COLLEGE')
        ->and((int) $row->watermark_opacity)->toBe(12);
});

it('refuses an opacity outside 1-30', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkText', 'HERITAGE')
        ->set('watermarkOpacity', 90)
        ->call('save')
        ->assertHasErrors('watermark_opacity');
});

it('refuses an enabled watermark with neither text nor image', function (): void {
    // Switching a watermark on and supplying nothing to draw is the state
    // that produces an empty, mysterious block on every document.
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkText', '')
        ->call('save')
        ->assertHasErrors('watermark_text');
});

it('stores an uploaded watermark image under a content-hashed path', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('watermarkEnabled', true)
        ->set('watermarkUpload', UploadedFile::fake()->image('mark.png', 600, 600))
        ->call('save')
        ->assertHasNoErrors();

    expect((string) DB::table('school_document_profiles')->where('id', 1)->value('watermark_image_path'))
        ->toStartWith('branding/watermark-');
});
