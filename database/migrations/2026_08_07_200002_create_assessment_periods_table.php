<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FULL column set from 01-assessment 4.1, created now so the table
     * never needs an ALTER when the Assessment phase lands. Phase 1 only USES
     * type year|term|trimestre rows; the enum still carries all six values.
     */
    public function up(): void
    {
        Schema::create('assessment_periods', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 10.5 deletion matrix: AcademicYear is RESTRICT - a year
            // with periods (and, later, marks under them) cannot be deleted.
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            // Deliberately a plain nullable column WITHOUT a foreign key: the
            // assessment_frameworks table arrives in the assessment phase
            // (01-assessment 3.1), and a constraint cannot reference a table
            // that does not exist yet. That phase adds the FK (RESTRICT) in
            // its own migration. Phase 1 rows carry NULL here.
            $table->unsignedBigInteger('framework_id')->nullable();

            // Self-referencing tree: year -> (term|trimestre) -> leaf types.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('assessment_periods')
                ->restrictOnDelete();

            $table->enum('type', ['year', 'term', 'trimestre', 'sequence', 'evaluation', 'month']);

            // Identifier collation per 00-core 4 - see academic_years.code.
            $table->string('code', 32)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 160);
            $table->string('name_fr', 160);
            $table->smallInteger('order_index')->default(0);
            $table->date('starts_on');
            $table->date('ends_on');

            // Arbitrary positive; normalised at composition time by the sum
            // over participating children (01-assessment 9.1).
            $table->decimal('weight', 8, 4)->default(1);
            $table->boolean('counts_toward_parent')->default(true);
            $table->dateTime('marks_entry_opens_at')->nullable();
            $table->dateTime('marks_entry_closes_at')->nullable();
            $table->boolean('is_reporting_period')->default(false);
            $table->enum('status', ['planned', 'open', 'closed'])->default('planned');
            $table->timestamps();

            // 01-assessment 4.1. Caveat while framework_id is nullable: MySQL
            // permits duplicate (year, NULL, code) tuples through a UNIQUE
            // index, so until frameworks land, per-year code uniqueness is
            // additionally guarded in DefineTermStructure (which refuses to
            // create a second structure for a year at all).
            $table->unique(
                ['academic_year_id', 'framework_id', 'code'],
                'uq_assessment_periods_year_fw_code'
            );

            $table->index(['academic_year_id', 'type']);
            $table->index('parent_id');
        });

        // 01-assessment 4.1: starts_on <= ends_on. Containment within the
        // parent's range is multi-row and is validated in the Actions.
        DB::statement(
            'ALTER TABLE assessment_periods ADD CONSTRAINT chk_assessment_periods_dates CHECK (starts_on <= ends_on)'
        );

        DB::statement(
            'ALTER TABLE assessment_periods ADD CONSTRAINT chk_assessment_periods_weight CHECK (weight > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_periods');
    }
};
