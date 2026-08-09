<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\AdmissionNumber;

/*
 * docs/specs/10-documents.md 3 (D3 resolution) - ONE admission number, two
 * spellings, one tested transform: canonical HA/2021/00045, Code 39 payload
 * HA202100045, round-trip property-tested over the shapes Students actually
 * issues (ADM/{year}/{seq} and section-prefixed {P}/ADM/{year}/{seq}).
 */

it('turns the canonical form into the unpunctuated Code 39 payload', function () {
    $number = AdmissionNumber::fromCanonical('HA/2021/00045');

    expect($number->barcodePayload())->toBe('HA202100045');
});

it('re-punctuates a payload back to the canonical form', function () {
    $number = AdmissionNumber::fromBarcodePayload('HA202100045');

    expect($number->canonical())->toBe('HA/2021/00045');
});

it('round-trips the CreateStudent ADM/{year}/{seq} shape', function () {
    $canonical = 'ADM/2026/000123';

    expect(AdmissionNumber::fromBarcodePayload(
        AdmissionNumber::fromCanonical($canonical)->barcodePayload()
    )->canonical())->toBe($canonical);
});

it('round-trips the section-prefixed HA/ADM/{year}/{seq} shape via the ADM marker rule', function () {
    $number = AdmissionNumber::fromCanonical('HA/ADM/2026/00007');

    expect($number->barcodePayload())->toBe('HAADM202600007')
        ->and(AdmissionNumber::fromBarcodePayload('HAADM202600007')->canonical())
        ->toBe('HA/ADM/2026/00007');
});

it('preserves leading zeros in the sequence through the round trip', function () {
    expect(AdmissionNumber::fromBarcodePayload('ADM2026000045')->canonical())
        ->toBe('ADM/2026/000045');
});

it('round-trips every issued shape (property test, seeded)', function () {
    mt_srand(20260809);

    $letters = static function (int $length): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, 25)];
        }

        return $out;
    };

    for ($i = 0; $i < 300; $i++) {
        $year = (string) mt_rand(1990, 2099);
        $seq = str_pad((string) mt_rand(0, 999999), mt_rand(3, 6), '0', STR_PAD_LEFT);

        $canonical = match (mt_rand(0, 2)) {
            // the exact CreateStudent format
            0 => "ADM/{$year}/{$seq}",
            // section-prefixed 07-students format, e.g. HA/ADM/2026/00045
            1 => $letters(mt_rand(1, 4))."/ADM/{$year}/{$seq}",
            // bare single prefix, e.g. HA/2021/00045 - any prefix that does
            // not itself end in the ADM marker
            default => (function () use ($letters, $year, $seq): string {
                do {
                    $prefix = $letters(mt_rand(1, 5));
                } while ($prefix !== 'ADM' && str_ends_with($prefix, 'ADM'));

                return "{$prefix}/{$year}/{$seq}";
            })(),
        };

        $number = AdmissionNumber::fromCanonical($canonical);
        $payload = $number->barcodePayload();

        expect($payload)->toMatch('/^[A-Z0-9]+$/')
            ->and(AdmissionNumber::fromBarcodePayload($payload)->canonical())->toBe($canonical);
    }
});

it('refuses to emit a barcode payload that would scan back as a different number', function () {
    // A single-segment prefix ending in the ADM marker is the one ambiguous
    // shape: BADM2021... would re-read as B/ADM/2021/... - so the payload
    // side refuses rather than print a barcode that lies.
    AdmissionNumber::fromCanonical('BADM/2021/00045')->barcodePayload();
})->throws(DomainException::class);

it('rejects strings that are not canonical admission numbers', function (string $bad) {
    AdmissionNumber::fromCanonical($bad);
})->throws(InvalidArgumentException::class)->with([
    'lowercase' => ['ha/2021/00045'],
    'missing sequence' => ['HA/2021'],
    'two-digit year' => ['HA/21/00045'],
    'punctuation payload' => ['HA-2021-00045'],
    'empty' => [''],
]);

it('rejects strings that are not barcode payloads', function (string $bad) {
    AdmissionNumber::fromBarcodePayload($bad);
})->throws(InvalidArgumentException::class)->with([
    'still punctuated' => ['HA/2021/00045'],
    'no letters' => ['202100045'],
    'no year digits' => ['HAX'],
    'lowercase' => ['ha202100045'],
    'empty' => [''],
]);
