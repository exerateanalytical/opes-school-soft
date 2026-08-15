<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    p13coreDocumentProfile();
});

/**
 * The preview lives on the SAME route as the print, behind `?preview=1`, and
 * runs through the SAME Action - so what an operator previews is assembled by
 * the code that would issue it. Deliberately not a route of its own: a second
 * endpoint would have to re-assemble the payload, and a preview that shows
 * something other than what gets issued is worse than no preview.
 */
it('streams a specimen PDF inline and issues nothing', function (): void {
    p13coreUserAs(Role::Registrar, Role::Principal);

    $enrollment = Enrollment::factory()->create();

    $response = get(route('students.documents.print', [
        $enrollment->student_id, 'bonafide', 'preview' => 1,
    ]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    // Inline: a preview opens in the viewer. A download called
    // "bonafide.pdf" sitting in Downloads is indistinguishable from the
    // issued certificate a week later.
    expect((string) $response->headers->get('content-disposition'))->toStartWith('inline')
        ->and((string) $response->headers->get('cache-control'))->toContain('no-store')
        // The whole point: nothing was allocated and nothing was recorded.
        ->and(DB::table('issued_documents')->count())->toBe(0)
        ->and(DB::table('document_print_logs')->count())->toBe(0);
});

it('issues for real when the preview flag is absent', function (): void {
    // The guard on the test above: if `preview` silently defaulted to true the
    // platform would stop issuing documents altogether and every assertion
    // about "nothing was recorded" would still pass.
    p13coreUserAs(Role::Registrar, Role::Principal);

    $enrollment = Enrollment::factory()->create();

    get(route('students.documents.print', [$enrollment->student_id, 'bonafide']))->assertOk();

    expect(DB::table('issued_documents')->count())->toBe(1)
        ->and(DB::table('document_print_logs')->count())->toBe(1);
});

it('refuses a caller without the print permission', function (): void {
    p13coreUserAs(Role::Teacher);

    get(route('students.documents.print', [
        Enrollment::factory()->create()->student_id, 'bonafide', 'preview' => 1,
    ]))->assertForbidden();
});

it('refuses an unknown document rather than 500ing', function (): void {
    p13coreUserAs(Role::Registrar);

    get(route('students.documents.print', [
        Enrollment::factory()->create()->student_id, 'not-a-document', 'preview' => 1,
    ]))->assertNotFound();
});
