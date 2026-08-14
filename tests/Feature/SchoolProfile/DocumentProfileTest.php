<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\SchoolProfile\Actions\SaveDocumentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';
require_once __DIR__.'/../Assessment/AssessmentTestHelpers.php';

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

it('loads the school identity screen and saves it', function (): void {
    // Pest's actingAs (inside p13moneyUserAs) + Livewire::test, never
    // Livewire::actingAs()->test(): the manager's test() is a template over a
    // union PHPStan cannot resolve at level 8 (see DashboardTest).
    p13moneyUserAs(Role::Administrator);

    Livewire::test(App\Modules\SchoolProfile\Livewire\DocumentProfile::class)
        ->set('city', 'Bafoussam')
        ->set('phone', '+237 233 111 111')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('school_document_profiles')->where('id', 1)->value('city'))->toBe('Bafoussam');
});

it('answers 200 at /settings/school-identity', function (): void {
    p13moneyUserAs(Role::Administrator);

    get('/settings/school-identity')->assertOk();
});

it('warns about an unconfirmed fiscal identity and links the screen that confirms it', function (): void {
    p13moneyUserAs(Role::Administrator);
    p13moneyConfirmedFiscalIdentity([
        'niu' => 'SPECIMEN0000A',
        'fiscal_identity_confirmed_by' => null,
        'fiscal_identity_confirmed_at' => null,
    ]);

    Livewire::test(App\Modules\SchoolProfile\Livewire\DocumentProfile::class)
        ->assertSee('/settings/fiscal-identity', escape: false);
});

it('shows no fiscal warning once the identity is confirmed', function (): void {
    p13moneyUserAs(Role::Administrator);
    p13moneyConfirmedFiscalIdentity();

    Livewire::test(App\Modules\SchoolProfile\Livewire\DocumentProfile::class)
        ->assertDontSee('/settings/fiscal-identity', escape: false);
});

it('reprints an already-issued report card unchanged after the profile is saved through this screen', function (): void {
    // The second stranding vector, exercised through the REAL writer: the
    // render envelope frozen at issue (Phase 2) is what makes shipping this
    // screen safe, and this test is that claim under the exact code path an
    // administrator now has a button for.
    $user = reportCardPublisher();
    $user->givePermissionTo(App\Modules\Identity\Domain\Permission::SettingEdit->value);
    $user = $user->fresh() ?? $user;
    actingAs($user);
    p13moneyConfirmedFiscalIdentity();

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(App\Modules\Assessment\Actions\PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];
    $original = app(App\Modules\Assessment\Actions\PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    app(SaveDocumentProfile::class)->handle([
        'address_line1' => 'BP 9999, Nouvelle Adresse',
        'city' => 'Garoua',
        'phone' => '+237 699 999 999',
    ], $user->toAuditActor());

    $reprint = app(App\Modules\Assessment\Actions\PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // Not toBe($original->html): a reprint legitimately adds the DUPLICATA
    // watermark and copy line. The frozen-envelope claim is that the
    // LETTERHEAD is the one issued with - today's profile must not leak in.
    expect($reprint->isDuplicate)->toBeTrue();
    expect($reprint->html)->not->toContain('Nouvelle Adresse');
    expect($original->html)->not->toContain('Nouvelle Adresse');
});
