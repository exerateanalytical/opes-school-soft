<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

/**
 * The fixture both tests share: a confirmed fiscal identity, a snapshot, a
 * snapshot-backed template and the argument list that renders it.
 *
 * @return array<string, mixed>
 */
function watermarkReproducibilityArgs(): array
{
    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
    ]);

    return [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];
}

it('reprints a document issued BEFORE the school watermark existed', function (): void {
    // The migration that added these columns ran against a live database
    // full of issued documents. If the watermark were part of the hashed
    // artefact, switching it on would break every one of them, permanently.
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile(['watermark_enabled' => false]);

    $args = watermarkReproducibilityArgs();

    $original = app(RenderDocument::class)->handle(...$args);

    // The school now switches its watermark on.
    DB::table('school_document_profiles')->where('id', 1)->update([
        'watermark_enabled' => true,
        'watermark_text' => 'HERITAGE BILINGUAL COLLEGE',
        'watermark_opacity' => 10,
    ]);

    $reprint = app(RenderDocument::class)->handle(...$args);

    // The CLEAN artefact still hashes the same - the watermark is an output
    // overlay, never part of the hashed bytes.
    expect($reprint->contentHash)->toBe($original->contentHash)
        ->and($reprint->isDuplicate)->toBeTrue();
});

it('does NOT put the newly-configured watermark on a reprint of an older document', function (): void {
    // The chrome was frozen at issue with watermark = null, so a reprint
    // carries the letterhead AS AT ISSUE - which is the difference between a
    // reprint and a forgery.
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile(['watermark_enabled' => false]);

    $args = watermarkReproducibilityArgs();

    app(RenderDocument::class)->handle(...$args);

    DB::table('school_document_profiles')->where('id', 1)->update([
        'watermark_enabled' => true, 'watermark_text' => 'ADDED LATER',
    ]);

    expect(app(RenderDocument::class)->handle(...$args)->html)->not->toContain('ADDED LATER');
});
