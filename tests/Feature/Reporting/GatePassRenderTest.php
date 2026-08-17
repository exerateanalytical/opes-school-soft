<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §12.4 - GATE-PASS. Snapshot-backed under the
 * receipt pattern (phase-12-13 D3): no SnapshotSourceMap entry, so the
 * caller's own payload - carrying its own `school` chrome block, as
 * ReceiptRenderTest's fixture does - becomes the frozen snapshot on first
 * issue, and a reprint of the same subject/snapshot pair is a DUPLICATA.
 */
beforeEach(function (): void {
    p13coreDocumentProfile();
});

it('issues a gate pass with an allocated day-scoped GP serial', function (): void {
    p13coreUserAs(Role::Registrar);

    $rendered = app(RenderDocument::class)->handle(
        templateCode: 'GATE-PASS',
        subjectType: 'Student',
        subjectId: 7,
        subjectLabel: 'FOTSO Marie',
        snapshotId: 501,
        data: ['pass' => [
            'student_name' => 'FOTSO Marie',
            'class_group' => 'Form 3B',
            'reason' => 'Medical appointment.',
            'date' => '17/08/2026',
            'time_out' => '14:30',
        ], 'school' => [
            'name' => 'HOPE ACADEMY',
            'name_fr' => "COLLÈGE DE L'ESPOIR",
            'short_code' => 'HA',
            'state_header' => null,
            'branding' => [],
            'fiscal' => null,
            'bilingual' => false,
        ]],
    );

    expect($rendered->bytes)->toStartWith('%PDF-');
    expect($rendered->html)->toContain('FOTSO Marie');
    expect($rendered->html)->toContain('Medical appointment.');
    expect($rendered->serial)->not->toBeNull();
    expect($rendered->serial)->toContain('/GP/');
    expect($rendered->issuedDocumentId)->not->toBeNull();
});

it('reprinting the same subject and snapshot is a DUPLICATA, never a fresh original', function (): void {
    $data = ['pass' => [
        'student_name' => 'NGONO Paul',
        'class_group' => 'Upper Sixth',
        'reason' => 'Family emergency.',
        'date' => '17/08/2026',
        'time_out' => '10:00',
    ], 'school' => [
        'name' => 'HOPE ACADEMY',
        'name_fr' => "COLLÈGE DE L'ESPOIR",
        'short_code' => 'HA',
        'state_header' => null,
        'branding' => [],
        'fiscal' => null,
        'bilingual' => false,
    ]];

    p13coreUserAs(Role::Registrar);

    $first = app(RenderDocument::class)->handle(
        templateCode: 'GATE-PASS',
        subjectType: 'Student',
        subjectId: 8,
        subjectLabel: 'NGONO Paul',
        snapshotId: 502,
        data: $data,
    );
    expect($first->isDuplicate)->toBeFalse();

    $second = app(RenderDocument::class)->handle(
        templateCode: 'GATE-PASS',
        subjectType: 'Student',
        subjectId: 8,
        subjectLabel: 'NGONO Paul',
        snapshotId: 502,
        data: $data,
    );
    expect($second->isDuplicate)->toBeTrue();
    expect($second->html)->toContain('DUPLICATA');
    expect($second->serial)->toBe($first->serial);
});
