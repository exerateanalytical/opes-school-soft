<?php

declare(strict_types=1);

use App\Modules\Guardians\Domain\Gender;
use App\Modules\Guardians\Domain\GuardianIdType;
use App\Modules\Guardians\Domain\GuardianLanguage;
use App\Modules\Guardians\Domain\GuardianStatus;
use App\Modules\Guardians\Domain\MaritalStatus;
use App\Modules\Guardians\Domain\PreferredContactMethod;
use App\Modules\Guardians\Domain\ResidentialStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/07-students.md 7.1.
 *
 * A guardian is a first-class person record with its own list and profile
 * screens - explicitly "not three pivot flags on a link table". Everything
 * that varies per CHILD lives on `student_guardians` (7.2), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Case-SENSITIVE, like every human-facing series in this product
            // (00-core 12): `HA/GRD/2026/001` and `ha/grd/2026/001` are
            // different strings on paper and must be different strings here.
            $table->string('guardian_no', 32)->collation('utf8mb4_0900_as_cs')->unique();

            $table->string('title', 16)->nullable();

            // Accent- and case-INSENSITIVE: searching "belinga" must find
            // "Bélinga". The opposite choice from guardian_no, deliberately.
            $table->string('first_name', 80)->collation('utf8mb4_0900_ai_ci');
            $table->string('last_name', 80)->collation('utf8mb4_0900_ai_ci');

            $table->date('date_of_birth')->nullable();
            $table->enum('gender', Gender::values());
            $table->char('nationality', 2)->default('CM');

            $table->enum('id_type', GuardianIdType::values())->nullable();

            // ENCRYPTED at rest (00-core 9.5). Laravel's `encrypted` cast
            // produces a base64 envelope far longer than the 40 characters of
            // plaintext the spec names, hence the generous column.
            $table->string('id_number', 512)->nullable();

            // The duplicate-detection key (7.7 tier 1). A blind index exists
            // precisely because the ciphertext above is non-deterministic and
            // therefore unsearchable: two records for the same national ID
            // encrypt to two different strings. UNIQUE where non-null - MySQL
            // treats every NULL as distinct, so any number of guardians may
            // have no ID on file while no two may claim the same one.
            $table->char('id_number_blind_index', 64)->nullable()->unique();

            $table->string('occupation', 120)->nullable();
            $table->string('employer', 120)->nullable();
            $table->enum('marital_status', MaritalStatus::values())->nullable();

            // NOT NULL: 7.1. A guardian with no reachable number cannot be
            // told their child is ill, which makes the record useless for the
            // one purpose it must never fail at. Stored E.164-normalised so
            // that 7.7's tier-2 exact match actually matches.
            $table->string('phone', 24);
            $table->string('alternative_phone', 24)->nullable();
            $table->string('email', 160)->nullable();

            $table->string('address_line', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('country', 120)->nullable();

            $table->enum('residential_status', ResidentialStatus::values())->nullable();
            $table->enum('preferred_contact_method', PreferredContactMethod::values())
                ->default(PreferredContactMethod::Phone->value);

            // Drives every message and document sent to them (7.1). Cameroon
            // is bilingual; this is a property of the person, not of the
            // school section their child happens to attend.
            $table->enum('language', GuardianLanguage::values())->default(GuardianLanguage::English->value);

            $table->string('emergency_contact_name', 160)->nullable();
            $table->string('emergency_contact_phone', 24)->nullable();
            $table->string('emergency_contact_relationship', 60)->nullable();
            $table->string('emergency_contact_address', 255)->nullable();

            $table->string('photo_path', 255)->nullable();

            // A conjunctive gate on EVERY row of the 7.5 matrix: deactivating
            // a guardian closes the portal across all their children at once
            // without touching a link row.
            $table->enum('status', GuardianStatus::values())->default(GuardianStatus::Active->value);

            // 7.4: these five are DELIVERY preferences - "do we push this to
            // them?" - and are never authorization. The authorization answer
            // to "may they see it if they go looking?" lives on the link, in
            // columns of the same name. The duplication of the names is the
            // spec's, and the separation is the point: a guardian of two
            // children may see fees for one and not the other, which a
            // person-level flag cannot express.
            $table->boolean('notify_sms')->default(true);
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_push')->default(false);
            $table->boolean('receives_reports')->default(true);
            $table->boolean('receives_invoices')->default(true);

            // The portal account, when they have one. Identity owns the row;
            // this module owns only the pointer. RESTRICT: a user account
            // referenced by a guardian is not deletable.
            $table->unsignedBigInteger('portal_user_id')->nullable()->unique();

            $table->boolean('is_archived')->default(false);

            $table->timestamps();

            // 13: (phone) and (last_name, first_name) - both serve duplicate
            // detection tiers 2 and 3 as much as they serve the list screen.
            $table->index('phone', 'idx_guardians_phone');
            $table->index(['last_name', 'first_name'], 'idx_guardians_name');
            $table->index('status', 'idx_guardians_status');

            $table->foreign('portal_user_id', 'fk_guardians_portal_user')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
