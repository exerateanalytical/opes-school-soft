<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\PrintReportCard;
use App\Modules\Assessment\Actions\PublishPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

require_once __DIR__.'/../Assessment/AssessmentTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('repairEnvelopeArtisan')) {
    /**
     * The repo's own idiom for driving a console command from Pest
     * (tests/Feature/Accounting/OpeningBalancesTest.php): artisan() is typed
     * PendingCommand|int, and the instanceof check is what narrows it.
     */
    function repairEnvelopeArtisan(string $command): Illuminate\Testing\PendingCommand
    {
        $pending = artisan($command);

        if (! $pending instanceof Illuminate\Testing\PendingCommand) {
            throw new RuntimeException('artisan() ran the command immediately instead of returning a PendingCommand.');
        }

        return $pending;
    }
}

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

/*
 * The forward fix (render_envelope frozen at issue, backfilled on a reprint
 * that reproduces) cannot reach a document that is ALREADY stranded: its
 * reprint fails the hash check, so the backfill never runs. The recovery key
 * is the audit trail - document_print_logs.subject_label_at_time recorded
 * the label as at issue - and the command tries EXACTLY that one candidate,
 * freezing the envelope only when the re-rendered bytes reproduce the
 * recorded content_hash. A document that does not reproduce is reported and
 * left untouched.
 */

it('recovers a document stranded by a rename, using the label recorded at issue', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);
    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];

    // The production shape: a payload with no student/period keys, so the
    // live label is rendered into the hashed bytes via the blade fallback.
    reportCardMinimalSnapshotId($fx, $enrollmentId);
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // Recreate a pre-fix stranded document exactly: no envelope, and the
    // source row renamed underneath it.
    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);
    DB::table('assessment_periods')->where('id', $fx['period_id'])->update(['name' => 'Renamed Sequence']);

    expect(fn () => app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']))
        ->toThrow(App\Modules\Reporting\Domain\DocumentReproducibilityViolation::class);

    repairEnvelopeArtisan('opes:documents:repair-envelope')
        ->expectsOutputToContain('repaired: 1')
        ->assertExitCode(0);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->html)->toContain('Sequence 1');
    expect($reprint->html)->not->toContain('Renamed Sequence');
});

it('changes nothing for a document whose recorded label does not reproduce', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);
    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];

    reportCardMinimalSnapshotId($fx, $enrollmentId);
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);

    // A label that was never the issued one. The command must not force it.
    DB::table('document_print_logs')->where('issued_document_id', $issuedId)
        ->update(['subject_label_at_time' => 'Not the label that was issued']);

    repairEnvelopeArtisan('opes:documents:repair-envelope')
        ->expectsOutputToContain('unrecoverable: 1')
        ->assertExitCode(0);

    expect(DB::table('issued_documents')->where('id', $issuedId)->value('render_envelope'))->toBeNull();
});

it('writes nothing under --dry-run even when the document would repair', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);
    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];

    reportCardMinimalSnapshotId($fx, $enrollmentId);
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);

    repairEnvelopeArtisan('opes:documents:repair-envelope --dry-run')
        ->expectsOutputToContain('repaired: 1')
        ->assertExitCode(0);

    expect(DB::table('issued_documents')->where('id', $issuedId)->value('render_envelope'))->toBeNull();
});
