<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\AssetTagBarcode;

/**
 * Same discipline as AdmissionNumberRoundTripTest: a barcode that scans back
 * as a DIFFERENT tag is worse than no barcode, because a stock-take believes
 * the scanner.
 */
it('strips the separators for the Code 39 payload', function (): void {
    expect(AssetTagBarcode::fromCanonical('HBC/AST/2026/000145')->barcodePayload())
        ->toBe('HBCAST2026000145');
});

it('round-trips every canonical shape the register produces', function (string $canonical): void {
    expect(AssetTagBarcode::fromBarcodePayload(
        AssetTagBarcode::fromCanonical($canonical)->barcodePayload()
    )->canonical())->toBe($canonical);
})->with([
    'HBC/AST/2026/000145',
    'AST/2026/000001',
    'HBC/AST/2026/1',
    'LAB/AST/1999/999999',
]);

it('refuses a tag that would not survive the round trip', function (): void {
    // A tag with no AST marker cannot be re-punctuated unambiguously: the
    // payload HBC2026000145 could be HBC/2026/000145 or HB/C2026/... The
    // class refuses rather than printing a label that scans back wrong.
    AssetTagBarcode::fromCanonical('HBC/2026/000145')->barcodePayload();
})->throws(DomainException::class, 'round trip');

it('refuses a multi-segment prefix whose split point the scanner cannot recover', function (): void {
    // HBCLABAST20261 re-reads as HBCLAB/AST/2026/1 - a different tag.
    AssetTagBarcode::fromCanonical('HBC/LAB/AST/2026/1')->barcodePayload();
})->throws(DomainException::class, 'round trip');

it('refuses a canonical form the register never produces', function (): void {
    AssetTagBarcode::fromCanonical('hbc-ast-2026-145');
})->throws(InvalidArgumentException::class);

it('refuses a payload that is not an asset tag', function (): void {
    AssetTagBarcode::fromBarcodePayload('AST');
})->throws(InvalidArgumentException::class);

it('preserves leading zeros in the sequence', function (): void {
    expect(AssetTagBarcode::fromBarcodePayload('AST2026000007')->canonical())
        ->toBe('AST/2026/000007');
});

it('accepts a free-form tag by refusing it rather than mangling it', function (): void {
    // Real registers contain hand-entered legacy tags. The label template must
    // print those WITHOUT a barcode rather than invent one.
    expect(AssetTagBarcode::tryFromCanonical('OLD LAB MICROSCOPE 4'))->toBeNull();
});

it('refuses an unmarked tag through the escape hatch too', function (): void {
    expect(AssetTagBarcode::tryFromCanonical('HBC/2026/000145'))->toBeNull();
});
