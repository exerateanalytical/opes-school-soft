<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\DocumentProfile;
use App\Support\Storage\StoredImage;
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

// Administrator, not Principal: Principal holds setting.view but NOT
// setting.edit, and this screen's mount() authorises setting.edit.

it('stores an uploaded crest and persists its content-hashed path', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('crest.png', 400, 400))
        ->call('save')
        ->assertHasNoErrors();

    $stored = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    expect($stored)->toStartWith('branding/crest-')
        ->and(Storage::disk('public')->exists($stored))->toBeTrue();
});

it('refuses a file that is not an allowed image type', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->create('crest.svg', 10, 'image/svg+xml'))
        ->call('save')
        ->assertHasErrors('crestUpload');

    expect(DB::table('school_document_profiles')->where('id', 1)->value('crest_path'))->toBeNull();
});

it('refuses an image larger than the size cap', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('huge.png', 400, 400)->size(StoredImage::MAX_KILOBYTES + 1))
        ->call('save')
        ->assertHasErrors('crestUpload');
});

it('refuses an image whose longest edge exceeds the dimension cap', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('wide.png', StoredImage::MAX_DIMENSION + 100, 200))
        ->call('save')
        ->assertHasErrors('crestUpload');
});

it('deletes the image a slot used to hold when it is replaced', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('one.png', 100, 100))
        ->call('save');

    $first = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('two.png', 220, 180))
        ->call('save');

    $second = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    expect($second)->not->toBe($first)
        ->and(Storage::disk('public')->exists($first))->toBeFalse()
        ->and(Storage::disk('public')->exists($second))->toBeTrue();
});

it('clears a slot when the operator removes the image', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('one.png', 100, 100))
        ->call('save');

    $path = (string) DB::table('school_document_profiles')->where('id', 1)->value('crest_path');

    Livewire::test(DocumentProfile::class)
        ->call('removeImage', 'crest')
        ->call('save');

    expect(DB::table('school_document_profiles')->where('id', 1)->value('crest_path'))->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('handles all five slots', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('crestUpload', UploadedFile::fake()->image('a.png', 100, 100))
        ->set('logoUpload', UploadedFile::fake()->image('b.png', 110, 100))
        ->set('principalSignatureUpload', UploadedFile::fake()->image('c.png', 300, 100))
        ->set('registrarSignatureUpload', UploadedFile::fake()->image('d.png', 300, 110))
        ->set('schoolStampUpload', UploadedFile::fake()->image('e.png', 200, 200))
        ->call('save')
        ->assertHasNoErrors();

    $row = (array) DB::table('school_document_profiles')->where('id', 1)->first();

    foreach ([
        'crest_path', 'logo_path', 'principal_signature_path',
        'registrar_signature_path', 'school_stamp_path',
    ] as $column) {
        expect((string) ($row[$column] ?? ''))->toStartWith('branding/');
    }
});
