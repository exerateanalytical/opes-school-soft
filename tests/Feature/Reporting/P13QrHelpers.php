<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Actions\SignDocumentQrToken;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
 * Shared fixtures for the P13-D2 QR/verification suite. Every helper is
 * p13qr-prefixed and function_exists-guarded per the parallel-agent
 * convention: Pest includes every suite file before running any test.
 *
 * Fixture rows go in through DB::table on purpose - these tests exercise the
 * verification READ path against the 310001/310003 schema, not any model's
 * write path.
 */

if (! function_exists('p13qrUserAs')) {
    /** A logged-in user holding the union of the given roles' permissions. */
    function p13qrUserAs(Role ...$roles): User
    {
        (new RolePermissionSeeder)->run();
        $user = User::factory()->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p13qrTemplateId')) {
    /** A registered snapshot-backed template row; returns its id. */
    function p13qrTemplateId(string $code = 'CERT-COMP'): int
    {
        $existing = DB::table('document_templates')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('document_templates')->insertGetId([
            'code' => $code,
            'name' => 'Certificate of Completion',
            'name_fr' => 'Attestation de fin d\'études',
            'module' => 'Assessment',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'none',
            'series_code' => null,
            'is_snapshot_backed' => true,
            'snapshot_source' => 'ReportCardSnapshot',
            'carries_qr' => true,
            'carries_barcode' => false,
            'state_header' => 'default_on',
            'signature_roles' => json_encode(['principal']),
            'min_phase' => 'v1',
            'bulk_printable' => false,
            'blade_view' => 'documents.certificates.completion',
            'version' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p13qrIssuedDocument')) {
    /**
     * An issued_documents row shaped like a RenderDocument commit, returned
     * as ['id' => ..., 'serial' => ..., 'content_hash' => ..., ...].
     *
     * @param  array<string, mixed>  $overrides
     * @return array{id: int, serial: string, content_hash: string, issued_on: string, issuer_name: string, template_id: int}
     */
    function p13qrIssuedDocument(array $overrides = []): array
    {
        $templateId = isset($overrides['document_template_id'])
            ? (int) $overrides['document_template_id']
            : p13qrTemplateId();

        /** @var User $issuer */
        $issuer = User::factory()->create();

        $serial = isset($overrides['serial'])
            ? (string) $overrides['serial']
            : 'HA/2026/COM/'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        $contentHash = isset($overrides['content_hash'])
            ? (string) $overrides['content_hash']
            : hash('sha256', 'p13qr-pdf-bytes-'.$serial);

        $row = array_merge([
            'document_template_id' => $templateId,
            'template_version' => 1,
            'series_code' => null,
            'serial' => $serial,
            'subject_type' => 'Student',
            'subject_id' => random_int(1, 100000),
            'snapshot_type' => 'ReportCardSnapshot',
            'snapshot_id' => random_int(1, 100000),
            'language' => 'en',
            'content_hash' => $contentHash,
            'qr_token' => null,
            'issued_by' => $issuer->id,
            'issued_at' => '2026-05-04 09:30:00',
            'issued_by_name_at_time' => $issuer->name,
            'status' => 'valid',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $id = (int) DB::table('issued_documents')->insertGetId($row);

        return [
            'id' => $id,
            'serial' => (string) $row['serial'],
            'content_hash' => (string) $row['content_hash'],
            'issued_on' => substr((string) $row['issued_at'], 0, 10),
            'issuer_name' => (string) $row['issued_by_name_at_time'],
            'template_id' => $templateId,
        ];
    }
}

if (! function_exists('p13qrTokenFor')) {
    /**
     * A real signed token for an issued row - provisioning the signing key
     * and the instance uuid on first use, exactly like a live render would.
     *
     * @param  array{serial: string, content_hash: string, issued_on: string}  $document
     */
    function p13qrTokenFor(array $document, string $templateCode = 'CERT-COMP'): string
    {
        return app(SignDocumentQrToken::class)->handle(
            templateCode: $templateCode,
            serial: $document['serial'],
            contentHash: $document['content_hash'],
            issueDate: $document['issued_on'],
        );
    }
}

if (! function_exists('p13qrKeypair')) {
    /**
     * A throwaway P-256 keypair independent of the database - for
     * wrong-key and tamper tests.
     *
     * @return array{private: string, public: string}
     */
    function p13qrKeypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        assert($resource !== false);

        $private = '';
        openssl_pkey_export($resource, $private);

        $details = openssl_pkey_get_details($resource);
        assert($details !== false);

        return ['private' => $private, 'public' => (string) $details['key']];
    }
}
