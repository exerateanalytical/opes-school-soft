<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use DomainException;
use InvalidArgumentException;

/**
 * ONE asset tag, two spellings, one documented transform between them -
 * modelled directly on AdmissionNumber, for the same reason:
 *
 *  - canonical (human-readable, printed beneath the barcode):  HBC/AST/2026/000145
 *  - barcode payload (Code 39 has no "/"):                     HBCAST2026000145
 *
 * The canonical shape is one or more UPPERCASE ALPHA segments ending in the
 * "AST" asset marker, a 4-digit year, then the sequence, "/"-separated. The
 * payload is that string with the separators removed - the uppercase
 * alphanumeric subset Code 39 needs.
 *
 * fromBarcodePayload() re-punctuates by structure: the letter run is split
 * before the AST marker, then the first four digits are the year and the rest
 * is the sequence, leading zeros preserved. barcodePayload() REFUSES
 * (DomainException) any tag whose payload would not survive that round trip,
 * so a label that scans back as a DIFFERENT asset can never be printed - a
 * stock-take believes the scanner, and a wrong scan silently moves the wrong
 * asset's custody record.
 *
 * The marker is MANDATORY, not optional as it is for admission numbers, and
 * it is enforced by barcodePayload() rather than by the round-trip check
 * alone. An unmarked tag like HBC/2026/000145 does happen to survive the
 * naive round trip - but only because the re-punctuation guesses, and the
 * same payload HBC2026000145 could equally have been HB/C2026/000145 in a
 * register whose prefixes were one character shorter. Guessing at the
 * structure of an unmarked tag is exactly the ambiguity this class exists to
 * refuse, so an unmarked tag gets no barcode at all.
 *
 * tryFromCanonical() is the caller's escape hatch - the label template prints
 * such a tag as text with NO barcode, which is honest.
 */
final readonly class AssetTagBarcode
{
    private const CANONICAL_PATTERN = '/^([A-Z]+(?:\/[A-Z]+)*)\/(\d{4})\/(\d+)$/';

    private const PAYLOAD_PATTERN = '/^([A-Z]+)(\d{4})(\d+)$/';

    private const ASSET_MARKER = 'AST';

    /**
     * @param  list<string>  $prefixSegments  e.g. ['HBC', 'AST']
     * @param  string  $year  4 digits
     * @param  string  $sequence  digits, leading zeros significant
     */
    private function __construct(
        public array $prefixSegments,
        public string $year,
        public string $sequence,
    ) {}

    public static function fromCanonical(string $canonical): self
    {
        if (preg_match(self::CANONICAL_PATTERN, $canonical, $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$canonical}' is not a canonical asset tag "
                .'(expected e.g. HBC/AST/2026/000145 or AST/2026/000001).'
            );
        }

        return new self(explode('/', $m[1]), $m[2], $m[3]);
    }

    /**
     * The register contains hand-entered and imported legacy tags. This is
     * how a caller asks "can this one carry a barcode?" without catching an
     * exception for the ordinary case.
     */
    public static function tryFromCanonical(string $canonical): ?self
    {
        try {
            $tag = self::fromCanonical($canonical);
            $tag->barcodePayload();

            return $tag;
        } catch (InvalidArgumentException|DomainException) {
            return null;
        }
    }

    public static function fromBarcodePayload(string $payload): self
    {
        if (preg_match(self::PAYLOAD_PATTERN, $payload, $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$payload}' is not an asset-tag barcode payload (expected e.g. HBCAST2026000145)."
            );
        }

        return new self(self::splitLetterRun($m[1]), $m[2], $m[3]);
    }

    /** The human-readable form: HBC/AST/2026/000145. */
    public function canonical(): string
    {
        return implode('/', [...$this->prefixSegments, $this->year, $this->sequence]);
    }

    /**
     * The Code 39 payload: HBCAST2026000145. Guaranteed to round-trip, or it
     * throws before a label that scans back as a DIFFERENT asset is printed.
     */
    public function barcodePayload(): string
    {
        $payload = implode('', [...$this->prefixSegments, $this->year, $this->sequence]);

        // Without the marker there is no rule by which a scanner's letter run
        // can be re-punctuated: the split point is a guess, and a guess that
        // happens to be right today is wrong for the next register whose
        // prefix is a different length.
        $lastSegment = $this->prefixSegments[count($this->prefixSegments) - 1] ?? '';

        if ($lastSegment !== self::ASSET_MARKER) {
            throw new DomainException(
                "Asset tag '{$this->canonical()}' carries no ".self::ASSET_MARKER
                ." marker, so payload '{$payload}' has no unambiguous round trip back "
                .'to a tag; refusing to print a label that may scan back as a different asset.'
            );
        }

        $reread = self::fromBarcodePayload($payload)->canonical();

        if ($reread !== $this->canonical()) {
            throw new DomainException(
                "Asset tag '{$this->canonical()}' does not survive the barcode "
                ."round trip (payload '{$payload}' re-reads as '{$reread}'); "
                .'refusing to print a label that scans back as a different asset.'
            );
        }

        return $payload;
    }

    /**
     * The documented disambiguation rule: the letter run must END in the AST
     * marker, and is split before it. A run that is exactly the marker is one
     * segment; anything else is refused by the checks above.
     *
     * @return list<string>
     */
    private static function splitLetterRun(string $letters): array
    {
        $marker = self::ASSET_MARKER;

        if ($letters !== $marker && str_ends_with($letters, $marker)) {
            return [substr($letters, 0, -strlen($marker)), $marker];
        }

        return [$letters];
    }
}
