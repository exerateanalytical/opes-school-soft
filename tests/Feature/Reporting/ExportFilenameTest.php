<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The unsanitised-filename family (bugs audit A.1). Every house serial
 * contains '/' by design, and Symfony's HeaderUtils refuses to put one in a
 * Content-Disposition header. Nine call sites interpolate an identifier
 * straight into $filename; three of them crash for every record today and
 * six are latent. Sanitising HERE closes all nine, and this test is what
 * stops the tenth caller reintroducing it.
 */
it('builds a downloadable response from a filename containing house slashes', function (): void {
    $response = PdfExport::download(
        'Asset card',
        ['Tag', 'Description'],
        [['AST/000001', 'Projector']],
        'asset-card-AST/000001.pdf',
    );

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('content-disposition'))
        ->toContain('asset-card-AST-000001.pdf');
});

it('keeps a filename that needed no sanitising byte-for-byte', function (): void {
    $response = PdfExport::download('Report', ['A'], [['1']], 'students-2026.pdf');

    expect($response->headers->get('content-disposition'))->toContain('students-2026.pdf');
});

it('keeps the extension when every other character is stripped', function (): void {
    $response = PdfExport::download('Report', ['A'], [['1']], '///.pdf');

    // DocumentFileName::sanitize() folds the '///' run to '-' and then trims
    // the leading '-.' away, so this lands on 'pdf'. Asserted explicitly
    // because it is the boundary between "sanitised" and "empty".
    expect($response->headers->get('content-disposition'))->toContain('pdf');
});

it('never produces an empty filename', function (): void {
    // Nothing survives sanitising here, which is the case the 'document'
    // fallback exists for - an empty filename would make Symfony build
    // `filename=""` and the browser save an unnamed file.
    $response = PdfExport::download('Report', ['A'], [['1']], '///');

    expect($response->headers->get('content-disposition'))->toContain('document');
});
