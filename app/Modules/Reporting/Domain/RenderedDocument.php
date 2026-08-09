<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * What RenderDocument hands back: the output bytes plus the facts the caller
 * (a print button, the bulk printer, a portal download) needs to label them.
 *
 * `contentHash` is the SHA-256 of the CLEAN render - the artefact as issued,
 * before any DUPLICATA/VOID overlay - which is what IssuedDocument stores
 * and what every reprint is compared against (10-documents 4.5). `bytes` is
 * what actually goes to the printer and DOES carry the overlay on a
 * duplicate.
 *
 * `html` is the rendered markup behind `bytes`, exposed so tests and
 * previews can assert on content without a PDF text extractor.
 */
final readonly class RenderedDocument
{
    public function __construct(
        public string $bytes,
        public string $html,
        public string $contentHash,
        public DocumentLanguage $language,
        public bool $isDuplicate,
        public int $copyNo,
        public ?string $serial,
        public ?int $issuedDocumentId,
        public int $printLogId,
        public string $storagePath,
    ) {}
}
