<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §4.7 school_header - the letterhead.
 *
 * The block's docblock always promised "crest, school name (EN/FR),
 * contacts and the FISCAL IDENTITY line", but there was nowhere in the
 * schema to hold the contacts, so it silently rendered the first and last
 * third of that promise. A letterhead with no address or telephone does not
 * read as an institutional document.
 *
 * The property under test is that the block prints exactly what it is given
 * and NOTHING it is not - an unset field must leave no empty label and no
 * stray separator behind, because a letterhead reading "Tel: ·" is worse
 * than one with no telephone line at all.
 */
beforeEach(function (): void {
    p13coreViews();
});

it('prints the address, contact and authorisation lines when the school supplies them', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'address_line1' => 'Rue 1.234, Quartier Bastos',
        'city' => 'Yaoundé',
        'region' => 'Centre',
        'po_box' => '4587',
        'phone' => '+237 222 22 22 22',
        'email' => 'contact@heritage.cm',
        'website' => 'www.heritage.cm',
        'authorisation_line' => 'Arrêté N° 123/MINESEC/SG',
    ]);

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->toContain('Rue 1.234, Quartier Bastos')
        // City and region are joined into one place-name, not listed twice.
        ->toContain('Yaoundé Centre')
        ->toContain('P.O. Box 4587')
        ->toContain('Tel: +237 222 22 22 22')
        ->toContain('contact@heritage.cm')
        ->toContain('www.heritage.cm')
        ->toContain('Arrêté N° 123/MINESEC/SG');
});

it('leaves no empty label or dangling separator when contacts are not set', function (): void {
    p13coreUserAs(Role::Bursar);
    // The default profile supplies no contacts at all - the state every
    // school starts in, and the one the demo database is in today.
    p13coreDocumentProfile();

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->not->toContain('Tel:')
        ->not->toContain('P.O. Box')
        // The separator only ever appears BETWEEN two present values.
        ->not->toContain('· ·');
});

it('prints only the fields supplied, with no separator around the gaps', function (): void {
    p13coreUserAs(Role::Bursar);
    // A school with a phone and nothing else - the partial case that a
    // chain of @if blocks with hard-coded separators gets wrong.
    p13coreDocumentProfile(['phone' => '+237 233 42 00 00']);

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->toContain('Tel: +237 233 42 00 00')
        ->not->toContain('· ·');
});

it('translates the letterhead labels into the document language, not the UI locale', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile(['po_box' => '4587', 'phone' => '+237 222 22 22 22']);

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Liste de classe 6e A',
        language: 'fr',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)
        ->toContain('B.P. 4587')
        ->toContain('Tél. : +237 222 22 22 22')
        ->not->toContain('P.O. Box');
});

it('gives the document a sheet wrapper so a browser preview renders a page', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile();

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    // The wrapper is what the screen stylesheet draws the A4 sheet on, and
    // what the print stylesheet collapses so pagination is unchanged.
    expect($doc->html)
        ->toContain('doc-sheet')
        ->toContain('@media print')
        ->toContain('@media screen');
});
