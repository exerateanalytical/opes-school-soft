<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Actions\SignDocumentQrToken;
use App\Modules\Reporting\Domain\AdmissionNumber;
use App\Modules\Reporting\Domain\Code39Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P13CoreHelpers.php';
require_once __DIR__.'/P13QrHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §12.1 - ID-STU, Student ID Card. CR80,
 * double-sided, series `CARD` scoped per academic year, receipt-pattern
 * snapshot backing (like GATE-PASS). Deviation D3: only the school crest
 * prints, never a national emblem - the view has no field for one.
 */
beforeEach(function (): void {
    p13coreDocumentProfile();
});

function studentIdCardPayload(): array
{
    $admission = AdmissionNumber::fromCanonical('HA/2026/000123');

    return ['card' => [
        'name' => 'AZEMKEU Brice',
        'class_group' => 'Form 1A',
        'admission_no_canonical' => $admission->canonical(),
        'date_of_birth' => '12/03/2014',
        'academic_session' => '2026/2027',
        'section' => 'Anglophone',
        'valid_until' => '31/08/2027',
        'photo_path' => null,
        'barcode_data_uri' => Code39Image::dataUri($admission->barcodePayload()),
    ], 'school' => [
        'name' => 'HOPE ACADEMY',
        'name_fr' => "COLLÈGE DE L'ESPOIR",
        'short_code' => 'HA',
        'state_header' => null,
        'branding' => [],
        'fiscal' => null,
        'bilingual' => false,
    ]];
}

it('issues a student ID card with a CARD serial and the canonical admission number', function (): void {
    p13coreUserAs(Role::Registrar);

    $rendered = app(RenderDocument::class)->handle(
        templateCode: 'ID-STU',
        subjectType: 'Student',
        subjectId: 21,
        subjectLabel: 'AZEMKEU Brice',
        snapshotId: 601,
        data: studentIdCardPayload(),
    );

    expect($rendered->bytes)->toStartWith('%PDF-');
    expect($rendered->html)->toContain('AZEMKEU Brice');
    expect($rendered->html)->toContain('HA/2026/000123');
    expect($rendered->serial)->not->toBeNull();
    expect($rendered->serial)->toContain('/CARD/');
});

it('never shows a national coat of arms or ministry seal field - only the school crest block renders', function (): void {
    p13coreUserAs(Role::Registrar);

    $rendered = app(RenderDocument::class)->handle(
        templateCode: 'ID-STU',
        subjectType: 'Student',
        subjectId: 22,
        subjectLabel: 'AZEMKEU Brice',
        snapshotId: 602,
        data: studentIdCardPayload(),
    );

    // §2.2.1 / D3: no ministry/coat-of-arms strings anywhere on the card.
    expect($rendered->html)->not->toContain('MINISTRY OF');
    expect($rendered->html)->not->toContain('MINISTÈRE');
    expect($rendered->html)->not->toContain('REPUBLIC OF CAMEROON');
    expect($rendered->html)->not->toContain('RÉPUBLIQUE DU CAMEROUN');
});

it('the §17 signed QR payload never carries the student name, DOB or any other PII', function (): void {
    $card = studentIdCardPayload()['card'];

    $issued = p13qrIssuedDocument([
        'document_template_id' => DB::table('document_templates')->where('code', 'ID-STU')->value('id')
            ?? throw new RuntimeException('ID-STU template must be seeded'),
        'subject_type' => 'Student',
        'subject_id' => 21,
    ]);

    $token = app(SignDocumentQrToken::class)->handle(
        templateCode: 'ID-STU',
        serial: $issued['serial'],
        contentHash: $issued['content_hash'],
        issueDate: $issued['issued_on'],
    );

    // The signed payload is the base64url segment between the two dots.
    [, $payloadSegment] = explode('.', $token);
    $padded = strtr($payloadSegment, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $payloadJson = base64_decode($padded, true);

    expect($token)->toStartWith('OPES1.');
    expect($payloadJson)->not->toBeFalse();
    expect($payloadJson)->not->toContain($card['name']);
    expect($payloadJson)->not->toContain('AZEMKEU');
    expect($payloadJson)->not->toContain($card['date_of_birth']);
    expect($payloadJson)->not->toContain('12/03/2014');

    // Belt and braces: the whole token string, not just the decoded payload.
    expect($token)->not->toContain('AZEMKEU');
    expect($token)->not->toContain('12/03/2014');
});
