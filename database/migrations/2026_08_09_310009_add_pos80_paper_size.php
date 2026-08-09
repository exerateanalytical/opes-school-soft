<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md 10.1 (phase-12-13 D3) - the Fee Receipt's
 * "80 mm thermal variant". Additive-only ALTER: the five existing enum
 * values are untouched, so no `document_templates` or `document_print_logs`
 * row already on this enum is affected by widening it.
 *
 * Both tables carry the SAME enum (RenderDocument's writePrintLog stamps
 * `document_print_logs.paper_size` straight from `document_templates.paper_size`),
 * so both must widen together or a POS80 print log insert fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE document_templates MODIFY paper_size ENUM('A4','A5','A3','CR80','LETTER','POS80') NOT NULL");
        DB::statement("ALTER TABLE document_print_logs MODIFY paper_size ENUM('A4','A5','A3','CR80','LETTER','POS80') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE document_print_logs MODIFY paper_size ENUM('A4','A5','A3','CR80','LETTER') NOT NULL");
        DB::statement("ALTER TABLE document_templates MODIFY paper_size ENUM('A4','A5','A3','CR80','LETTER') NOT NULL");
    }
};
