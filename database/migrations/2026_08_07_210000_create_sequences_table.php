<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/00-core.md 12. Every generated number in the product
        // (matricule, admission_no, receipt_no, piece_no, document series)
        // comes from a row in this table locked FOR UPDATE inside the caller's
        // transaction. `max(column) + 1` is never used: two concurrent readers
        // see the same max and produce the same number, and the failure only
        // shows up as a UNIQUE violation on a busy admissions day.
        Schema::create('sequences', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The series key carries its own scope: `matricule.2026.SEC1` is a
            // different counter from `matricule.2027.SEC1`. Scope is therefore
            // stated explicitly by the caller, which 00-core 12 requires and
            // which v1 left ambiguous. Binary-ish comparison is deliberate:
            // `AS_CS` so 'ADM' and 'adm' cannot silently share a counter.
            $table->string('series', 120)->collation('utf8mb4_0900_as_cs')->unique();

            // next_value is the number the NEXT allocation will return, so a
            // fresh row starts at 1 and no "have we issued yet?" flag is
            // needed.
            $table->unsignedBigInteger('next_value')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
