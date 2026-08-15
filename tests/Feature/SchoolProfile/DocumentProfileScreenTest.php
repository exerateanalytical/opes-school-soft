<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Livewire\DocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('dispatches settings-saved so the shared form can clear its dirty flag', function (): void {
    p13coreUserAs(Role::Administrator);

    Livewire::test(DocumentProfile::class)
        ->set('city', 'Yaoundé')
        ->call('save')
        ->assertDispatched('settings-saved');

    expect(DB::table('school_document_profiles')->where('id', 1)->value('city'))->toBe('Yaoundé');
});

it('restores the persisted values when the operator cancels', function (): void {
    p13coreUserAs(Role::Administrator);
    p13coreDocumentProfile(['city' => 'Douala']);

    Livewire::test(DocumentProfile::class)
        ->set('city', 'Typed but not saved')
        ->call('cancel')
        ->assertSet('city', 'Douala');
});
