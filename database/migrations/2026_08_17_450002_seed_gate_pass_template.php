<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §12.4 - GATE-PASS, Gate Pass / Autorisation de
 * sortie. A5 portrait, series `GP` (day-scoped - a fresh pass number every
 * day, matching VIS-PASS's own `day` scope in §12.3), snapshot-backed under
 * the "receipt pattern" (phase-12-13 D3, no SnapshotSourceMap entry): the
 * owning Action would assemble the payload (student, class, reason, time
 * out) and embed its OWN as-at-issue `school` chrome block the same way
 * ReceiptRenderTest's fixtures do, so RenderDocument freezes the payload
 * onto issued_documents.payload_snapshot at issue and the content-hash
 * compare on reprint holds it to determinism.
 *
 * Safeguarding note (§12.4): "issuing requires a permission and the
 * guardian-notification setting determines whether a message is queued to
 * the guardian (Communication module; queued to the outbox on LAN, never a
 * blocking error)." The permission gate is the existing
 * documents.print/documents.reprint pair RenderDocument already enforces;
 * the guardian-notification WIRING (a new setting + a call to
 * Communication\Actions\QueueMessage from an owning GatePass Action) is new
 * scope beyond a template/view/test and is deferred - there is no GatePass
 * Action in this change to call it from. Noted here so it is not lost.
 *
 * §2.2 compliance: no ministry seal slot; `authorized_by` and
 * `gate_security` are both on the 2.3 allow-list.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_series')->insert([
            'code' => 'GP',
            'format' => '{school}/{date}/GP/{serial:5}',
            'scope' => 'day',
            'reset_policy' => 'per_day',
            'padding' => 5,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('document_templates')->insert([
            'code' => 'GATE-PASS',
            'name' => 'Gate Pass',
            'name_fr' => 'Autorisation de sortie',
            'module' => 'Welfare',
            'paper_size' => 'A5',
            'orientation' => 'portrait',
            'duplex' => 'none',
            'series_code' => 'GP',
            'is_snapshot_backed' => true,
            'snapshot_source' => null,
            'carries_qr' => false,
            'carries_barcode' => false,
            'state_header' => 'none',
            'signature_roles' => json_encode(['authorized_by', 'gate_security'], JSON_THROW_ON_ERROR),
            'min_phase' => 'v1',
            'bulk_printable' => false,
            'version' => 1,
            'is_active' => true,
            'blade_view' => 'documents.welfare.gate-pass',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->where('code', 'GATE-PASS')->delete();
        DB::table('document_series')->where('code', 'GP')->delete();
    }
};
