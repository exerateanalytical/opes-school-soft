<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Domain\QrToken;
use App\Modules\Reporting\Domain\VerificationResult;
use App\Modules\Reporting\Domain\VerificationStatus;
use App\Modules\Reporting\Models\DocumentSigningKey;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md 17.2 - the LAN verification path: validate the
 * token's signature against this instance's own keys, look the serial up
 * locally, answer VALID / REVOKED / SUPERSEDED / NOT FOUND "and nothing
 * else".
 *
 * EVERY failure is the same bare NOT FOUND - malformed token, unknown key,
 * bad signature, a token some OTHER school signed, an unknown serial, a
 * content-hash prefix that does not match the issued row. Distinguishing
 * those states is exactly what would let the surface be used to enumerate
 * serials, so it cannot.
 *
 * Read-only and ungated here: the route decides who may ask (the in-app
 * screen sits behind auth; a VPS's public page sits behind the `verify`
 * rate limiter), and the result contains no student data by construction.
 */
final class VerifyDocumentQrToken
{
    public function handle(string $token): VerificationResult
    {
        $payload = QrToken::decode($token);

        if ($payload === null) {
            return VerificationResult::notFound();
        }

        // A token naming another school's instance cannot be resolved here -
        // and if this instance has never signed anything, there is no uuid
        // and nothing to verify against. Reading, never minting: a verify
        // must not write.
        $instanceUuid = DB::table('document_instance')->where('id', 1)->value('uuid');

        if (! is_string($instanceUuid) || $payload->instanceUuid !== $instanceUuid) {
            return VerificationResult::notFound();
        }

        // Key lookup by the token's `k` field, active AND retired alike:
        // retirement stops signing, never verification (17.1).
        /** @var DocumentSigningKey|null $key */
        $key = DocumentSigningKey::query()->where('key_id', $payload->keyId)->first();

        if ($key === null) {
            return VerificationResult::notFound();
        }

        if (QrToken::verify($token, $key->public_key) === null) {
            return VerificationResult::notFound();
        }

        /** @var object{serial: string, status: string, content_hash: string,
         *     issued_at: string, issued_by_name_at_time: string,
         *     superseded_by_document_id: int|null, template_code: string,
         *     template_name: string, template_name_fr: string}|null $row */
        $row = DB::table('issued_documents')
            ->join('document_templates', 'document_templates.id', '=', 'issued_documents.document_template_id')
            ->where('issued_documents.serial', $payload->serial)
            ->select([
                'issued_documents.serial',
                'issued_documents.status',
                'issued_documents.content_hash',
                'issued_documents.issued_at',
                'issued_documents.issued_by_name_at_time',
                'issued_documents.superseded_by_document_id',
                'document_templates.code as template_code',
                'document_templates.name as template_name',
                'document_templates.name_fr as template_name_fr',
            ])
            ->first();

        if ($row === null) {
            return VerificationResult::notFound();
        }

        // The token pins the first 16 bytes of the content hash. A signed
        // token whose hash does not match the issued row is a token for a
        // DIFFERENT artefact - same generic answer.
        if (! str_starts_with(strtolower($row->content_hash), $payload->contentHashPrefix)) {
            return VerificationResult::notFound();
        }

        $supersededBySerial = null;

        if ($row->superseded_by_document_id !== null) {
            $serial = DB::table('issued_documents')
                ->where('id', $row->superseded_by_document_id)
                ->value('serial');
            $supersededBySerial = is_string($serial) ? $serial : null;
        }

        // The 310003 status enum verbatim - strings, not model constants,
        // because this read goes through DB::table and depends only on the
        // schema.
        $status = match ($row->status) {
            'revoked' => VerificationStatus::Revoked,
            'superseded' => VerificationStatus::Superseded,
            default => VerificationStatus::Valid,
        };

        return VerificationResult::found(
            status: $status,
            serial: $row->serial,
            templateCode: $row->template_code,
            templateName: $row->template_name,
            templateNameFr: $row->template_name_fr,
            issuedOn: substr($row->issued_at, 0, 10),
            issuerName: $row->issued_by_name_at_time,
            supersededBySerial: $supersededBySerial,
        );
    }
}
