<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use InvalidArgumentException;

/**
 * The deterministic metadata a PDF render is stamped with.
 *
 * dompdf writes `date('YmdHisO')` into CreationDate/ModDate and a
 * `md5(microtime().mt_rand())` file identifier into the trailer - so two
 * renders of identical content are never byte-identical unless both are
 * overridden. 10-documents 4.5 makes byte-identity the reprint guarantee
 * (content_hash compared on every reprint), so the stamp is derived from the
 * ISSUE facts and reproduced on reprint: creation timestamp = issued_at,
 * seed = the document's own identity.
 */
final readonly class PdfStamp
{
    /**
     * @param  string  $creationTimestamp  `YmdHis`, 14 digits, in the business timezone of issue
     * @param  string  $seed  deterministic identity, e.g. "RPT-CARD|Enrollment|42|snap:7"
     */
    public function __construct(
        public string $creationTimestamp,
        public string $seed,
    ) {
        if (preg_match('/^\d{14}$/', $creationTimestamp) !== 1) {
            throw new InvalidArgumentException(
                "PdfStamp timestamp [{$creationTimestamp}] must be 14 digits (YmdHis); "
                .'a free-form value would defeat the byte-identity it exists to provide.'
            );
        }
    }

    /** The PDF-syntax date string (D:YmdHis, no zone - the zone would vary by server config). */
    public function pdfDate(): string
    {
        return 'D:'.$this->creationTimestamp;
    }

    /** The 32-hex trailer /ID, derived from the seed instead of microtime(). */
    public function fileIdentifier(): string
    {
        return md5('OPES-DOC.'.$this->seed);
    }
}
