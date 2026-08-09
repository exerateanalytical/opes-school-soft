<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md 4.2 - live documents are working views:
 * re-rendering after a data change is expected and CORRECT, every render
 * prints "Generated on ... by ...", nothing is issued, and no series number
 * exists to consume. A live document mistaken for evidence is a control
 * failure; the footer is what prevents it.
 */
beforeEach(function (): void {
    p13coreViews();
    p13coreDocumentProfile();
});

it('prints the Generated-on footer with the operator name, in the document language', function () {
    $user = p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()->create(['blade_view' => 'p13core-live']);

    $en = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice', 'FOTSO Marie']],
    );

    expect($en->html)->toContain('Generated on:');
    expect($en->html)->toContain($user->name);

    $fr = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Liste de classe 6eA',
        language: 'fr',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    // 4.6: the document language is a render parameter, not the UI locale.
    expect($fr->html)->toContain('Généré le :');
});

it('re-renders with current data, leaves one print log per render and never an issued document', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()->create(['blade_view' => 'p13core-live']);

    $first = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 9,
        subjectLabel: 'Class list',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    // The data changed - a new student enrolled - and the reprint reflects
    // it, without any DUPLICATA: that is what LIVE means (4.2).
    $second = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 9,
        subjectLabel: 'Class list',
        data: ['rows' => ['AZEMKEU Brice', 'NEWLY Enrolled']],
    );

    expect($first->html)->not->toContain('NEWLY Enrolled');
    expect($second->html)->toContain('NEWLY Enrolled');
    expect($first->isDuplicate)->toBeFalse();
    expect($second->isDuplicate)->toBeFalse();
    expect($second->html)->not->toContain('DUPLICATA');

    expect(IssuedDocument::query()->count())->toBe(0);

    $logs = DB::table('document_print_logs')->orderBy('id')->get()->values()->all();
    expect($logs)->toHaveCount(2);
    [$firstLog, $secondLog] = $logs;
    expect($firstLog->issued_document_id)->toBeNull();
    expect((int) $firstLog->copy_no)->toBe(1);
    expect((int) $secondLog->copy_no)->toBe(1);
    expect((bool) $firstLog->is_duplicate)->toBeFalse();

    // The print log names the actor and the subject as they were (00-core 14).
    expect($firstLog->subject_label_at_time)->toBe('Class list');
    expect($firstLog->actor_name_at_time)->not->toBe('');
});

it('resolves the language SchoolSection -> SchoolProfile when no explicit request is made', function () {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile(['default_document_language' => 'fr']);

    $template = DocumentTemplate::factory()->create(['blade_view' => 'p13core-live']);

    // No explicit language, no section: the SchoolProfile default wins.
    $profileDefault = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 3,
        subjectLabel: 'Liste',
        data: ['rows' => []],
    );
    expect($profileDefault->language->value)->toBe('fr');

    // A section carrying its own document_language overrides the profile
    // (4.6: an Anglophone secondary and a Francophone nursery print in
    // different languages from the same operator session).
    $sectionId = DB::table('school_sections')->insertGetId([
        'education_level' => 'secondary_1',
        'track' => 'general',
        'sub_system' => 'anglophone',
        'name' => 'Anglophone Secondary',
        'name_fr' => 'Secondaire anglophone',
        'matricule_format' => '{year}-{serial:5}',
        'display_order' => 1,
        'is_active' => true,
        'document_language' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sectionOverride = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 3,
        subjectLabel: 'Class list',
        schoolSectionId: $sectionId,
        data: ['rows' => []],
    );
    expect($sectionOverride->language->value)->toBe('en');
});
