<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.1 - the document template registry.
 *
 * Every printable in the suite is a REGISTERED template, not an ad-hoc Blade
 * file: registration is what gives it a series, a print log, bilingual
 * rendering and the integrity policy's signature-role allow-list (2.3),
 * validated at template save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Case-SENSITIVE per the spec's own collation callout: 'CERT-COMP'
            // is an identifier, and identifiers are as_cs (00-core 4).
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs')->unique();

            $table->string('name', 160);
            $table->string('name_fr', 160);

            // The owning module per 00-core 6.3. VARCHAR, not a MySQL ENUM:
            // the module list grows by phase and an ALTER on this registry
            // for every new module would churn a table that certificates pin
            // versions against.
            $table->string('module', 30);

            $table->enum('paper_size', ['A4', 'A5', 'A3', 'CR80', 'LETTER']);
            $table->enum('orientation', ['portrait', 'landscape']);
            $table->enum('duplex', ['none', 'double_sided'])->default('none');

            // Nullable: live documents and blank forms carry no series (4.2).
            // The FK is added by 310002 - document_series does not exist yet
            // at this point in the run and filenames are pre-assigned.
            $table->string('series_code', 16)->collation('utf8mb4_0900_as_cs')->nullable();

            $table->boolean('is_snapshot_backed');

            // The snapshot model this template renders from, e.g.
            // 'ReportCardSnapshot'. Named, not derived, so a template cannot
            // silently switch payload sources between versions.
            $table->string('snapshot_source', 64)->nullable();

            $table->boolean('carries_qr')->default(false);
            $table->boolean('carries_barcode')->default(false);

            $table->enum('state_header', ['none', 'optional', 'default_on'])
                ->default('none');

            // Ordered signature roles, validated at save against the 2.3
            // allow-list (principal|vice_principal|registrar|...). 'minister'
            // and friends are denied with the 13.2 message - in the Action,
            // where the message can quote the spec; the column just stores
            // what passed. Null = the document carries no signature block.
            $table->json('signature_roles')->nullable();

            $table->enum('min_phase', ['v1', 'later'])->default('v1');

            // 18.1: whether the Bulk Prints screen offers this template.
            $table->boolean('bulk_printable')->default(false);

            $table->string('blade_view', 160);

            // Bumped on ANY layout change. IssuedDocument pins the version it
            // was rendered with, so a reprint years later reproduces the
            // issued artefact - layout, labels and branding, not only the
            // numbers (4.1).
            $table->unsignedInteger('version')->default(1);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
