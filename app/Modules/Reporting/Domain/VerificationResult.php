<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 17.2 - what the verification screen may show:
 * the four-state status "plus template, issue date and issuer". Detail
 * fields are non-null ONLY when the token verified cryptographically AND the
 * serial resolved locally; a NotFound result is always bare, so the generic
 * failure path cannot leak whether a serial exists.
 */
final readonly class VerificationResult
{
    private function __construct(
        public VerificationStatus $status,
        public ?string $serial,
        public ?string $templateCode,
        public ?string $templateName,
        public ?string $templateNameFr,
        public ?string $issuedOn,
        public ?string $issuerName,
        public ?string $supersededBySerial,
    ) {
    }

    public static function notFound(): self
    {
        return new self(VerificationStatus::NotFound, null, null, null, null, null, null, null);
    }

    public static function found(
        VerificationStatus $status,
        string $serial,
        string $templateCode,
        string $templateName,
        string $templateNameFr,
        string $issuedOn,
        string $issuerName,
        ?string $supersededBySerial = null,
    ): self {
        return new self(
            $status, $serial, $templateCode, $templateName, $templateNameFr,
            $issuedOn, $issuerName, $supersededBySerial,
        );
    }
}
