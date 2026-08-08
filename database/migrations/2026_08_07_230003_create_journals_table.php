<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifier columns are accent- and case-sensitive.
            $table->string('code', 10)->collation('utf8mb4_0900_as_cs')->unique();

            $table->string('name', 120);
            $table->string('name_fr', 120);

            $table->enum('type', [
                'sales', 'purchases', 'cash', 'bank', 'mobile_money',
                'payroll', 'operations_diverses', 'opening', 'closing',
            ]);

            $table->foreignId('default_debit_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('default_credit_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('treasury_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('requires_maker_checker')->default(false);
            $table->string('piece_no_format', 40);

            // Referenced in §3's prose ("AN and CL are is_system and only
            // writable by the year-end Actions ... and the opening-balance
            // import") though absent from the field table above it - the
            // field table is defective there. ConfigureJournal is the
            // enforcement point; see that Action for the guard.
            $table->boolean('is_system')->default(false);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        // §3's CHECK, stated exactly: "type IN (cash,bank,mobile_money) =>
        // treasury_account_id IS NOT NULL". Implemented as BOTH a real CHECK
        // (belt) and Action-level validation in ConfigureJournal (braces) -
        // the spec's "cannot dereference the FK" caveat is about validating
        // the FK's TARGET (a job for a nightly integrity check against
        // chart_of_accounts), not this same-table IS NOT NULL condition,
        // which both tables in the expression belong to `journals` itself.
        //
        // The `is_active = 0` escape clause is required for the seed data
        // in the next migration: CA/BQ/MM ship seeded but INACTIVE, because
        // no treasury chart-of-accounts row can be assigned to them until
        // the school's accountant configures one at setup (02-accounting
        // §2.3 "blocking gate 6" pattern - a wrong seeded value is worse
        // than an empty one, and here even a CORRECT-looking placeholder FK
        // would misrepresent which physical account holds the school's
        // money). ConfigureJournal refuses to activate one of these three
        // types without a treasury_account_id already set.
        DB::statement(
            "ALTER TABLE journals ADD CONSTRAINT chk_journals_treasury CHECK ".
            "(is_active = 0 OR type NOT IN ('cash','bank','mobile_money') OR treasury_account_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
