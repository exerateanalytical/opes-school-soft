<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11: the staff directory record grows the identity, statutory and
 * payment-coordinate columns of docs/specs/05-hr-payroll.md 3.3.
 *
 * Nothing employment-related lands here - position, grade, dates, salary all
 * live on `staff_contracts` (3.4), the single source of employment truth.
 * Everything the Phase 3 directory table already carried (staff_no, names,
 * gender, phone, email, photo_path, status) is kept intact so the existing
 * directory tests stay green.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->string('place_of_birth', 150)->nullable()->after('date_of_birth');

            // ISO 3166-1 alpha-2. Drives the MINTSS visa requirement on
            // contracts: a non-Cameroonian employee needs a work visa
            // reference before a contract may open (3.4).
            $table->char('nationality', 2)->default('CM')->after('place_of_birth');

            $table->enum('national_id_type', ['cni', 'passport', 'residence_permit', 'refugee_card'])
                ->nullable()
                ->after('nationality');

            // 00-core 9.5: identifiers are encrypted at rest, so they cannot
            // be indexed; uniqueness rides on an HMAC-SHA256 blind index of
            // the canonicalised plaintext (same construction as students and
            // guardians - hex, CHAR(64), NULLs distinct).
            $table->text('national_id_number')->nullable()->after('national_id_type');
            $table->char('national_id_blind_index', 64)->nullable()->unique()->after('national_id_number');

            $table->text('cnps_number')->nullable()->after('national_id_blind_index');
            $table->char('cnps_number_blind_index', 64)->nullable()->unique()->after('cnps_number');

            // 11.5 worker lifecycle: registration within 8 days of hire. The
            // hire Action sets `pending` + deadline; `not_required` is the
            // pre-Phase-11 rows' safe default.
            $table->enum('cnps_registration_status', ['not_required', 'pending', 'registered', 'declared_departed'])
                ->default('not_required')
                ->after('cnps_number_blind_index');
            $table->date('cnps_registered_on')->nullable()->after('cnps_registration_status');
            $table->date('cnps_registration_deadline')->nullable()->after('cnps_registered_on');

            // The taxpayer number is not secret (it appears on every payslip)
            // but it is case-sensitive and unique where present.
            $table->string('niu', 32)->collation('utf8mb4_0900_as_cs')->nullable()->unique()->after('cnps_registration_deadline');

            // Payment coordinates - encrypted, never indexed, decrypted only
            // at disbursement-file export (8.8).
            $table->text('bank_name')->nullable()->after('niu');
            $table->text('bank_account')->nullable()->after('bank_name');
            $table->text('mobile_money_number')->nullable()->after('bank_account');

            // Reporting and CNPS family-allowance entitlement only. NOT an
            // IRPP input - defect N3: Cameroonian salary IRPP has no
            // dependants relief and no quotient familial.
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])
                ->nullable()
                ->after('mobile_money_number');

            $table->string('next_of_kin_name', 150)->nullable()->after('marital_status');
            $table->string('next_of_kin_relationship', 50)->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_phone', 30)->nullable()->after('next_of_kin_relationship');

            // Plain column, no FK: the `documents` table belongs to Phase 13
            // (docs/plans/phase-12-13.md); the constraint is added there.
            $table->unsignedBigInteger('photo_document_id')->nullable()->after('next_of_kin_phone');

            // 00-core 10.5: archive flag, never SoftDeletes. A staff member
            // with payroll history is never deleted.
            $table->boolean('is_archived')->default(false)->after('photo_document_id');

            $table->unsignedInteger('version')->default(0)->after('is_archived');
        });

        // The Phase 3 directory knew only active/inactive. Payroll needs the
        // person-level lifecycle of 3.3. `inactive` is retained: existing
        // rows and the directory tests use it, and mapping it silently to a
        // richer state would invent facts about people.
        DB::statement(
            "ALTER TABLE staff_members MODIFY status ENUM('active', 'inactive', 'on_leave', 'suspended', 'terminated', 'retired') NOT NULL DEFAULT 'active'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE staff_members MODIFY status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'"
        );

        Schema::table('staff_members', function (Blueprint $table): void {
            $table->dropColumn([
                'place_of_birth',
                'nationality',
                'national_id_type',
                'national_id_number',
                'national_id_blind_index',
                'cnps_number',
                'cnps_number_blind_index',
                'cnps_registration_status',
                'cnps_registered_on',
                'cnps_registration_deadline',
                'niu',
                'bank_name',
                'bank_account',
                'mobile_money_number',
                'marital_status',
                'next_of_kin_name',
                'next_of_kin_relationship',
                'next_of_kin_phone',
                'photo_document_id',
                'is_archived',
                'version',
            ]);
        });
    }
};
