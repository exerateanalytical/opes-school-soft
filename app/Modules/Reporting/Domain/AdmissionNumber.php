<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use DomainException;
use InvalidArgumentException;

/**
 * docs/specs/10-documents.md 3 (D3 resolution) - ONE admission number, two
 * spellings, one documented transform between them:
 *
 *  - canonical (human-readable, printed beneath the barcode):  HA/2021/00045
 *  - barcode payload (Code 39 has no "/"):                     HA202100045
 *
 * The canonical shape is what Students issues (CreateStudent's
 * ADM/{year}/{seq}, section-prefixed variants like HA/ADM/{year}/{seq}):
 * one or more UPPERCASE ALPHA segments, a 4-digit year, then the zero-padded
 * sequence, "/"-separated. The payload is that string with the separators
 * removed - uppercase alphanumerics only, exactly the Code 39 subset the ID
 * card's symbology needs.
 *
 * fromBarcodePayload() re-punctuates by structure: leading letters, then the
 * first four digits as the year, then the remaining digits as the sequence
 * (leading zeros preserved). A letter run that ends in the "ADM" marker
 * (e.g. HAADM) is split before it (HA/ADM) - that is the one documented
 * disambiguation rule, and barcodePayload() REFUSES (DomainException) any
 * number whose payload would not survive the round trip, so an ambiguous
 * payload can never reach a printed card. Round-trip property-tested in
 * AdmissionNumberRoundTripTest.
 */
final readonly class AdmissionNumber
{
    private const CANONICAL_PATTERN = '/^([A-Z]+(?:\/[A-Z]+)*)\/(\d{4})\/(\d+)$/';

    private const PAYLOAD_PATTERN = '/^([A-Z]+)(\d{4})(\d+)$/';

    private const ADMISSIONS_MARKER = 'ADM';

    /**
     * @param  list<string>  $prefixSegments  e.g. ['HA', 'ADM']
     * @param  string  $year  4 digits, e.g. '2021'
     * @param  string  $sequence  digits, leading zeros significant, e.g. '00045'
     */
    private function __construct(
        public array $prefixSegments,
        public string $year,
        public string $sequence,
    ) {
    }

    public static function fromCanonical(string $canonical): self
    {
        if (preg_match(self::CANONICAL_PATTERN, $canonical, $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$canonical}' is not a canonical admission number "
                .'(expected e.g. HA/2021/00045 or ADM/2026/000123).'
            );
        }

        return new self(explode('/', $m[1]), $m[2], $m[3]);
    }

    public static function fromBarcodePayload(string $payload): self
    {
        if (preg_match(self::PAYLOAD_PATTERN, $payload, $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$payload}' is not an admission-number barcode payload "
                .'(expected e.g. HA202100045).'
            );
        }

        return new self(self::splitLetterRun($m[1]), $m[2], $m[3]);
    }

    /** The human-readable form: HA/2021/00045. */
    public function canonical(): string
    {
        return implode('/', [...$this->prefixSegments, $this->year, $this->sequence]);
    }

    /**
     * The Code 39 symbology payload: HA202100045. Uppercase alphanumerics
     * only - and guaranteed to round-trip, or it throws before a card that
     * scans back as a DIFFERENT number can be printed.
     */
    public function barcodePayload(): string
    {
        $payload = implode('', [...$this->prefixSegments, $this->year, $this->sequence]);

        if (self::fromBarcodePayload($payload)->canonical() !== $this->canonical()) {
            throw new DomainException(
                "Admission number '{$this->canonical()}' does not survive the "
                ."barcode round trip (payload '{$payload}' re-reads as "
                ."'".self::fromBarcodePayload($payload)->canonical()."'); "
                .'refusing to print a barcode that scans back as a different number.'
            );
        }

        return $payload;
    }

    /**
     * The documented disambiguation rule: a letter run ending in the "ADM"
     * admissions marker (and longer than the marker itself) is a
     * section-prefixed number, split before the marker. Everything else is a
     * single segment.
     *
     * @return list<string>
     */
    private static function splitLetterRun(string $letters): array
    {
        $marker = self::ADMISSIONS_MARKER;

        if ($letters !== $marker && str_ends_with($letters, $marker)) {
            return [substr($letters, 0, -strlen($marker)), $marker];
        }

        return [$letters];
    }
}
