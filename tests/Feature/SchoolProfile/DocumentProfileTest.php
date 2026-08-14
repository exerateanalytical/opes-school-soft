<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\SaveDocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

it('creates the singleton profile row on first save', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    app(SaveDocumentProfile::class)->handle([
        'address_line1' => 'BP 4000, Rue Manga Bell',
        'city' => 'Douala',
        'region' => 'Littoral',
        'phone' => '+237 233 000 000',
        'email' => 'contact@hopeacademy.cm',
        'state_header_enabled' => true,
        'ministry_en' => 'Ministry of Secondary Education',
        'ministry_fr' => 'Ministère des Enseignements Secondaires',
        'bilingual_documents' => true,
        'default_document_language' => 'en',
    ], $user->toAuditActor());

    $row = DB::table('school_document_profiles')->where('id', 1)->first();

    expect($row)->not->toBeNull();
    expect($row?->city)->toBe('Douala');
    expect((bool) $row?->state_header_enabled)->toBeTrue();
});

it('updates the same singleton row on a second save, never a second row', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    app(SaveDocumentProfile::class)->handle(['city' => 'Douala'], $user->toAuditActor());
    app(SaveDocumentProfile::class)->handle(['city' => 'Yaoundé'], $user->toAuditActor());

    expect(DB::table('school_document_profiles')->count())->toBe(1);
    expect(DB::table('school_document_profiles')->where('id', 1)->value('city'))->toBe('Yaoundé');
});

it('refuses an email that is not one', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    expect(fn () => app(SaveDocumentProfile::class)->handle(['email' => 'not-an-email'], $user->toAuditActor()))
        ->toThrow(ValidationException::class);
});

it('refuses a state header switched on with no ministry named', function (): void {
    $user = p13moneyUserAs(Role::Administrator);

    expect(fn () => app(SaveDocumentProfile::class)->handle([
        'state_header_enabled' => true,
        'ministry_en' => '',
        'ministry_fr' => '',
    ], $user->toAuditActor()))->toThrow(ValidationException::class);
});
