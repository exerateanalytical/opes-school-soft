<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §12.2 - AnalyticValue, the MEMBER.
 *
 * `linked_type`/`linked_id` are plain columns - deliberately NOT a
 * polymorphic FK. They optionally point at a SchoolSection, Route or Hostel
 * in OTHER modules; 00-core §6.2 forbids cross-module Model imports, so
 * integrity here is Action-level, same as journal_entry_lines.partner_id.
 *
 * Seeded members: only the ACTIVITY list §12.2 commits to ("teaching,
 * boarding, transport, canteen, library, administration"). SECTION, SITE
 * and PROJECT members are per-school configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytic_values', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('analytic_axis_id')->constrained('analytic_axes')->restrictOnDelete();

            // 00-core §4: identifier columns are accent-/case-sensitive.
            $table->string('code', 30)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 200);
            $table->string('name_fr', 200);

            // Hierarchical members (a campus under a region, a sub-activity
            // under an activity). RESTRICT: a parent cannot vanish under its
            // children.
            $table->foreignId('parent_id')->nullable()->constrained('analytic_values')->restrictOnDelete();

            $table->string('linked_type', 100)->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);

            $table->timestamps();

            $table->unique(['analytic_axis_id', 'code'], 'uq_analytic_value_code');
        });

        $activityAxisId = DB::table('analytic_axes')->where('code', 'ACTIVITY')->value('id');

        if ($activityAxisId !== null) {
            $now = now();

            $members = [
                ['code' => 'TEACHING', 'name' => 'Teaching', 'name_fr' => 'Enseignement'],
                ['code' => 'BOARDING', 'name' => 'Boarding', 'name_fr' => 'Internat'],
                ['code' => 'TRANSPORT', 'name' => 'Transport', 'name_fr' => 'Transport'],
                ['code' => 'CANTEEN', 'name' => 'Canteen', 'name_fr' => 'Cantine'],
                ['code' => 'LIBRARY', 'name' => 'Library', 'name_fr' => 'Bibliotheque'],
                ['code' => 'ADMINISTRATION', 'name' => 'Administration', 'name_fr' => 'Administration'],
            ];

            DB::table('analytic_values')->insert(array_map(
                static fn (array $member): array => [
                    'analytic_axis_id' => (int) $activityAxisId,
                    'code' => $member['code'],
                    'name' => $member['name'],
                    'name_fr' => $member['name_fr'],
                    'parent_id' => null,
                    'linked_type' => null,
                    'linked_id' => null,
                    'is_active' => true,
                    'is_archived' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $members,
            ));
        }
    }

    public function down(): void
    {
        // Children before parents: parent_id is RESTRICT.
        DB::table('analytic_values')
            ->whereNotNull('parent_id')
            ->delete();

        Schema::dropIfExists('analytic_values');
    }
};
