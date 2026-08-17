<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Handles (`@amina.n`) and the official-account tick.
     *
     * `username` is nullable because every existing account predates it and a
     * handle is opt-in; UNIQUE because it is an addressing key - the messenger
     * resolves a recipient by it, so two users answering to one handle would
     * silently misdeliver. Uniqueness is case-INSENSITIVE: the column inherits
     * the table's utf8mb4 _ci collation, and Identity\Domain\Username
     * lower-cases before storing so the guarantee does not rest on collation
     * alone (SQLite, used by no environment here but by contributors, is
     * case-sensitive for non-ASCII).
     *
     * 32 characters: long enough for `firstname.lastname` at Cameroonian name
     * lengths, short enough to render in a message header without truncating.
     *
     * `is_official` marks a school's own accounts - the Bursar's office, the
     * Proviseur - so a guardian can tell a real fee demand from a parent
     * impersonating one. It is NOT self-serviceable: only Identity's
     * MarkUserOfficial writes it, under `user.manage`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 32)->nullable()->unique()->after('email');
            $table->boolean('is_official')->default(false)->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_official']);
        });
    }
};
