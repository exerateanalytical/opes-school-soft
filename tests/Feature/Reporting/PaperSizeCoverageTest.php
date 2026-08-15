<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\PaperSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers the class broadsheet at A3 landscape', function (): void {
    $row = DB::table('document_templates')->where('code', 'BROADSHEET')->first();

    expect($row)->not->toBeNull();
    expect($row->paper_size)->toBe('A3')
        ->and($row->orientation)->toBe('landscape')
        ->and((bool) $row->bulk_printable)->toBeTrue()
        ->and($row->blade_view)->toBe('documents.assessment.broadsheet');
});

it('leaves no paper size defined-but-unused except LETTER', function (): void {
    // A size in the enum that no template uses is either a gap or dead code.
    // This test forces the question to be answered rather than accumulated.
    $used = DB::table('document_templates')->distinct()->pluck('paper_size')->all();

    $unused = array_values(array_diff(
        array_map(static fn (PaperSize $size): string => $size->value, PaperSize::cases()),
        array_map(static fn (mixed $size): string => (string) $size, $used),
    ));

    // LETTER is deliberately retained and unused - see
    // docs/superpowers/audits/2026-08-15-paper-sizes.md. Anything ELSE
    // showing up here is an unanswered question.
    expect($unused)->toBe(['LETTER']);
});

it('gives A3 and LETTER a real box through dompdf', function (): void {
    expect(PaperSize::A3->dompdfSize())->toBe('a3')
        ->and(PaperSize::Letter->dompdfSize())->toBe('letter');
});
