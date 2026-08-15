<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use InvalidArgumentException;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * A Code 39 barcode as a base64 PNG data URI.
 *
 * `picqer/php-barcode-generator` has been in vendor since the register was
 * built and referenced by nothing; this is its first and only call site.
 *
 * PNG rather than SVG: dompdf's SVG support is partial and its rasteriser
 * antialiases thin strokes, which is exactly what a scanner cannot read. A
 * PNG at a whole-number width factor produces crisp bar edges. It is embedded
 * as a data URI for the same reason every other image in a document is -
 * DompdfRenderer sets setIsRemoteEnabled(false).
 *
 * The alphabet is checked here rather than trusted: the generator will
 * happily encode characters outside the subset this platform uses, producing
 * a barcode that scans back as something the register has never heard of.
 * Callers pass a payload from AssetTagBarcode::barcodePayload(), which is
 * already round-trip verified; this is the second gate.
 */
final class Code39Image
{
    /** The Code 39 subset this platform uses: uppercase alphanumerics only. */
    private const ALPHABET = '/^[0-9A-Z]+$/';

    public static function dataUri(string $payload, int $widthFactor = 2, int $height = 44): string
    {
        if (preg_match(self::ALPHABET, $payload) !== 1) {
            throw new InvalidArgumentException(
                "'{$payload}' is outside the Code 39 subset this platform prints "
                .'(uppercase letters and digits only).'
            );
        }

        $png = (new BarcodeGeneratorPNG)->getBarcode(
            $payload,
            BarcodeGenerator::TYPE_CODE_39,
            $widthFactor,
            $height,
        );

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
