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
    p13coreDocumentProfile([
        'address_line1' => 'Rue 1.234, Quartier Bastos',
        'city' => 'Yaoundé',
        'phone' => '+237 222 22 22 22',
    ]);
});

/**
 * Strip the parts that are ALLOWED to differ between a preview and the issued
 * document, so what remains is the part that must be identical: the payload,
 * the letterhead, the labels, the layout.
 *
 * Exactly two things are removed, and both are chrome rather than body:
 *
 *  1. The two watermark layers. A preview is SPECIMEN unconditionally; the
 *     issued artefact's clean render carries no watermark at all.
 *  2. The identity footer (`.doc-footer`). It carries the series number and
 *     the issue date, which a preview genuinely does not have, and the
 *     "Generated on" line, which is the honest thing for a preview to say and
 *     the wrong thing for a certificate. A preview that printed an issue date
 *     it did not have would be worse than one whose footer differs.
 *
 * Nothing else is normalised. In particular no date regex is applied to the
 * body: if a date inside the document body moved between preview and issue,
 * that is divergence and this test must see it.
 */
function previewComparableHtml(string $html): string
{
    $html = (string) preg_replace('#<div class="doc-watermark">.*?</div>#s', '', $html);
    $html = (string) preg_replace('#<div class="doc-school-watermark">.*?</div>#s', '', $html);
    $html = (string) preg_replace('#<div class="doc-footer[^"]*">.*?</div>\s*</div>#s', '', $html);

    return trim((string) preg_replace('/\s+/', ' ', $html));
}

it('renders the same document body in preview as at issue', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(p13coreSnapshotPayload());
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'ReportCardSnapshot',
        'signature_roles' => ['principal'],
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    $preview = app(RenderDocument::class)->preview(...$args);
    $issued = app(RenderDocument::class)->handle(...$args);

    $comparable = previewComparableHtml($preview->html);

    // Guard against the comparison going vacuous: a regex that ate the whole
    // document would make every assertion below pass forever.
    expect($comparable)->toContain('AZEMKEU Brice')
        ->and($comparable)->toContain('13.50 / 20')
        ->and($comparable)->toContain('HOPE ACADEMY')
        ->and(mb_strlen($comparable))->toBeGreaterThan(1000);

    // A preview that shows something different from what gets issued is
    // worse than no preview: the operator stops checking, and the first
    // document they DON'T check is the one that is wrong.
    expect($comparable)->toBe(previewComparableHtml($issued->html));
});

it('renders the same body for a live template too', function (): void {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()->create(['blade_view' => 'p13core-live']);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'ClassGroup', 'subjectId' => 5,
        'subjectLabel' => 'Class list Form 1A', 'language' => 'en',
        'data' => ['rows' => ['AZEMKEU Brice', 'NKENG Sandra']],
    ];

    $comparable = previewComparableHtml(app(RenderDocument::class)->preview(...$args)->html);

    expect($comparable)->toContain('NKENG Sandra')
        ->and($comparable)->toBe(previewComparableHtml(app(RenderDocument::class)->handle(...$args)->html));
});

it('previewing first does not change what the subsequent issue produces', function (): void {
    // A preview must be side-effect free. If previewing warmed a cache, took
    // a lock or advanced a counter, the issued document would differ from
    // one issued without a preview - and only sometimes.
    p13coreUserAs(Role::Bursar, Role::Principal);

    // ONE template, two snapshots: a second template would differ by its
    // random code and the comparison would fail for a reason that has nothing
    // to do with previewing.
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'ReportCardSnapshot',
    ]);

    $makeArgs = function () use ($template): array {
        $snapshot = p13coreSnapshotRow(p13coreSnapshotPayload());

        return [
            'templateCode' => $template->code,
            'subjectType' => 'Enrollment', 'subjectId' => 42,
            'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
        ];
    };

    $withoutPreview = $makeArgs();
    $issuedPlain = app(RenderDocument::class)->handle(...$withoutPreview);

    $withPreview = $makeArgs();
    app(RenderDocument::class)->preview(...$withPreview);
    $issuedAfterPreview = app(RenderDocument::class)->handle(...$withPreview);

    expect(previewComparableHtml($issuedAfterPreview->html))
        ->toBe(previewComparableHtml($issuedPlain->html));
});

it('leaves the issued document register untouched when only previews are run', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $snapshot = p13coreSnapshotRow(p13coreSnapshotPayload());
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'ReportCardSnapshot',
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    app(RenderDocument::class)->preview(...$args);
    app(RenderDocument::class)->preview(...$args);

    // Three previews then an issue: the issue must still be the ORIGINAL, not
    // a DUPLICATA. A preview that left a print-log row would make the first
    // real certificate a school ever prints come out stamped DUPLICATA.
    app(RenderDocument::class)->preview(...$args);
    $issued = app(RenderDocument::class)->handle(...$args);

    expect($issued->isDuplicate)->toBeFalse()
        ->and($issued->copyNo)->toBe(1)
        ->and(DB::table('issued_documents')->count())->toBe(1)
        ->and(DB::table('document_print_logs')->count())->toBe(1);
});
