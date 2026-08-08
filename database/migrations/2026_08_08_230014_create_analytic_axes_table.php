<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §12.2 - AnalyticAxis, the DIMENSION.
 *
 * The v1 defect (§12.1) was conflating the dimension with the member; this
 * table holds only dimensions. The four axes the spec names as seeded
 * (SECTION, ACTIVITY, SITE, PROJECT) are inserted here. Axis MEMBERS are a
 * different entity (analytic_values, next migration) - §12.2 commits to
 * seeded values only for ACTIVITY; SECTION members ("nursery/primary/
 * secondary…", note the ellipsis) and SITE campuses are school-specific
 * configuration, left to ConfigureAnalyticValue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytic_axes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifier columns are accent-/case-sensitive.
            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 200);
            $table->string('name_fr', 200);

            $table->boolean('is_mandatory')->default(false);

            // e.g. [6, 7] - the account classes AN-3 applies this axis to.
            $table->json('applies_to_classes')->nullable();

            $table->boolean('requires_full_allocation')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        $now = now();

        DB::table('analytic_axes')->insert([
            [
                'code' => 'SECTION',
                'name' => 'School section',
                'name_fr' => 'Section',
                'is_mandatory' => true,
                'applies_to_classes' => json_encode([6, 7]),
                'requires_full_allocation' => true,
                'display_order' => 1,
                'is_active' => true,
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'ACTIVITY',
                'name' => 'Activity',
                'name_fr' => 'Activite',
                'is_mandatory' => true,
                'applies_to_classes' => json_encode([6, 7]),
                'requires_full_allocation' => true,
                'display_order' => 2,
                'is_active' => true,
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'SITE',
                'name' => 'Site',
                'name_fr' => 'Site',
                'is_mandatory' => false,
                'applies_to_classes' => json_encode([6, 7]),
                'requires_full_allocation' => true,
                'display_order' => 3,
                'is_active' => true,
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                // §12.2 marks PROJECT "(optional)" - never mandatory.
                'code' => 'PROJECT',
                'name' => 'Project',
                'name_fr' => 'Projet',
                'is_mandatory' => false,
                'applies_to_classes' => json_encode([6, 7]),
                'requires_full_allocation' => false,
                'display_order' => 4,
                'is_active' => true,
                'is_archived' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // §12.3: "`requires_analytic` defaults true for classes 6 and 7 - the
        // P&L - and false elsewhere." The chart seed (2026_08_07_230002) set
        // requires_analytic = false on every row, so the class-6/7 default is
        // applied here, where the analytic model that gives the flag meaning
        // first exists. Postable leaves only: non-postable parents never
        // carry lines, so flagging them would be noise.
        DB::table('chart_of_accounts')
            ->whereIn('account_class', [6, 7])
            ->where('is_postable', true)
            ->update(['requires_analytic' => true]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')
            ->whereIn('account_class', [6, 7])
            ->update(['requires_analytic' => false]);

        Schema::dropIfExists('analytic_axes');
    }
};
