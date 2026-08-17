<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Reporting\Actions\ProcessBulkPrint;
use App\Modules\Reporting\Models\BulkPrintJob;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

// AssessmentTestHelpers.php declares assessmentFixture()/assessmentPublisher()
// as global functions guarded by function_exists() - safe to require from
// here even though it normally loads as part of the Assessment suite.
require_once __DIR__.'/../Assessment/AssessmentTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * The merge half of §18.2: ProcessBulkPrint::handle() must produce ONE
 * combined PDF at output_path, not just the per-subject manifest.
 *
 * Real report-card snapshots are required - RPT-CARD's payload IS the
 * published snapshot (ProcessBulkPrint's own docblock), and there is no
 * factory for ReportCardSnapshot on purpose. Two students, so the merge
 * genuinely has more than one document's pages to combine.
 */
it('merges every subject\'s rendered PDF into one combined output file', function (): void {
    $user = reportCardPublisher();
    actingAs($user);
    $fx = assessmentFixture(['groups' => 1, 'students' => 2]);

    app(PublishPeriod::class)->handle(
        $fx['period_id'],
        $fx['class_group_ids'],
        $fx['config_id'],
    );

    $template = DocumentTemplate::query()->where('code', 'RPT-CARD')->firstOrFail();

    $job = BulkPrintJob::query()->create([
        'document_template_id' => $template->getKey(),
        'template_version' => $template->version,
        'academic_year_id' => $fx['academic_year_id'] ?? DB::table('academic_years')->value('id'),
        'class_group_id' => $fx['class_group_ids'][0],
        'assessment_period_id' => $fx['period_id'],
        'mode' => 'all',
        'language' => 'en',
        'paper_size' => 'A4',
        'copies' => 1,
        'collate' => true,
        'duplex' => 'none',
        'status' => 'queued',
        'total' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'requested_by' => $user->getKey(),
        'requested_at' => now(),
    ]);

    $result = app(ProcessBulkPrint::class)->handle($job);

    if ($result->status !== 'completed' && $result->manifest_path !== null) {
        throw new \RuntimeException('Diagnostic: '.file_get_contents(storage_path($result->manifest_path)));
    }

    expect($result->status)->toBe('completed');
    expect($result->succeeded)->toBe(2);
    expect($result->output_path)->not->toBeNull();
    expect($result->manifest_path)->not->toBeNull();

    $mergedPath = storage_path((string) $result->output_path);
    expect(is_file($mergedPath))->toBeTrue();

    $bytes = (string) file_get_contents($mergedPath);
    expect(str_starts_with($bytes, '%PDF-'))->toBeTrue();

    // Two students, one page each in this fixture - the merged file must
    // report 2 pages, not just have copied the first subject's file.
    $pdf = new \setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($mergedPath);
    expect($pageCount)->toBeGreaterThanOrEqual(2);

    // The manifest must be a DIFFERENT file from the merged PDF - the two
    // concepts (one combined document, one JSON index) must not collapse
    // back into a single path.
    expect($result->manifest_path)->not->toBe($result->output_path);
});

it('leaves output_path null when nothing renders successfully', function (): void {
    $user = assessmentPublisher();
    actingAs($user);
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    // No PublishPeriod call - no snapshots exist, so subjectsFor() finds
    // nothing and the job completes with zero subjects.
    $template = DocumentTemplate::query()->where('code', 'RPT-CARD')->firstOrFail();

    $job = BulkPrintJob::query()->create([
        'document_template_id' => $template->getKey(),
        'template_version' => $template->version,
        'academic_year_id' => DB::table('academic_years')->value('id'),
        'class_group_id' => $fx['class_group_ids'][0],
        'assessment_period_id' => $fx['period_id'],
        'mode' => 'all',
        'language' => 'en',
        'paper_size' => 'A4',
        'copies' => 1,
        'collate' => true,
        'duplex' => 'none',
        'status' => 'queued',
        'total' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'requested_by' => $user->getKey(),
        'requested_at' => now(),
    ]);

    $result = app(ProcessBulkPrint::class)->handle($job);

    expect($result->status)->toBe('completed');
    expect($result->succeeded)->toBe(0);
    expect($result->output_path)->toBeNull();
});
