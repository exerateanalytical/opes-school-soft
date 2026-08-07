<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 01-assessment 3.3. Bands are half-open [min, max) except the top band,
     * which is closed, so 20.00 out of 20 bands instead of falling off the end.
     *
     * `purpose` and `scale_basis` are the two columns v1 lacked. Without
     * `scale_basis` a /20 framework matched no percentage band and printed a
     * blank grade; without `purpose`, GCE Board letter grades leaked into
     * internal reporting.
     */
    public function up(): void
    {
        Schema::create('grade_bands', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('framework_id')
                ->constrained('assessment_frameworks')
                ->restrictOnDelete();

            $table->enum('purpose', ['internal', 'exam_o_level', 'exam_a_level'])->default('internal');
            $table->enum('scale_basis', ['out_of_max', 'percentage'])->default('out_of_max');

            // Optional narrowing: different bands in an exam class, say.
            $table->foreignId('class_level_id')
                ->nullable()
                ->constrained('class_levels')
                ->restrictOnDelete();

            $table->decimal('min_score', 6, 3);
            $table->decimal('max_score', 6, 3);

            $table->string('label', 48);
            $table->string('label_fr', 48);

            // The Appreciation / Mention printed on the bulletin. NEVER an
            // award: 01-assessment 12.2 keeps mention and conseil_award apart.
            $table->string('mention', 48)->nullable();

            $table->decimal('grade_point', 4, 2)->nullable();

            // Advisory only. The authoritative pass test is
            // score >= framework.pass_score (01-assessment 10.3).
            $table->boolean('is_pass')->default(false);

            $table->char('colour', 7)->nullable();
            $table->smallInteger('order_index')->default(0);

            $table->timestamps();

            $table->index(['framework_id', 'purpose', 'scale_basis'], 'idx_grade_bands_tuple');
        });

        // 00-core 10.1's generated-column trick, used here for a different
        // reason: class_level_id is legitimately NULL ("applies to every
        // level"), and MySQL permits unlimited duplicate NULLs in a UNIQUE
        // index - so a nullable column in the key would let the same band be
        // declared twice for the whole-framework case. The 0-sentinel key
        // makes the NULL case collide like any other.
        Schema::table('grade_bands', function (Blueprint $table): void {
            $table->unsignedBigInteger('class_level_id_key')
                ->storedAs('(COALESCE(`class_level_id`, 0))');

            $table->unique(
                ['framework_id', 'purpose', 'scale_basis', 'class_level_id_key', 'min_score'],
                'uq_grade_bands_interval'
            );
        });

        // The per-row half of the coverage invariant. The whole-set half -
        // starts at 0, contiguous, closed at the ceiling - is multi-row and
        // therefore lives in ConfigureGradeBands (01-assessment 3.3, T12).
        DB::statement(
            'ALTER TABLE grade_bands '
            .'ADD CONSTRAINT chk_grade_bands_interval CHECK (min_score >= 0 AND min_score < max_score)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_bands');
    }
};
