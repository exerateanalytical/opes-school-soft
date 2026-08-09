<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (docs/plans/phase-12-13.md 12.1): the staff portal account link,
 * an exact mirror of `guardians.portal_user_id`.
 *
 * Identity owns the user row; HR owns only the pointer. Nullable because most
 * staff never activate a portal account; UNIQUE because one user account may
 * never speak for two staff members. RESTRICT: a user account referenced by a
 * staff member is not deletable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('portal_user_id')->nullable()->unique();

            $table->foreign('portal_user_id', 'fk_staff_members_portal_user')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->dropForeign('fk_staff_members_portal_user');
            $table->dropColumn('portal_user_id');
        });
    }
};
