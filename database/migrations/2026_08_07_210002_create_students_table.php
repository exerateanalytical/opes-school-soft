<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/07-students.md 3.1. Deliberately absent, because every
        // one of them belongs to an Enrollment or is derived (3.1, C4):
        // is_repeater, class_group_id, stream_id, academic_year_id,
        // roll_number, position_in_class. The profile screen's "Class: Form 2A"
        // is read through the current EnrollmentSegment, not from here.
        Schema::create('students', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 12: globally unique, immutable once official.
            // AS_CS (accent-sensitive, case-sensitive) is explicit and load
            // bearing: under the connection default (utf8mb4_0900_ai_ci)
            // 'HA2026-45a' and 'HA2026-45A' would collide, and the UNIQUE index
            // would reject a legitimate second matricule.
            $table->string('matricule', 32)->collation('utf8mb4_0900_as_cs')->unique();

            // false = the temporary prefix awaiting the official government
            // number. 07-students 6.4 permits exactly one supervised
            // transition to true, after which a model observer refuses writes.
            $table->boolean('matricule_is_official')->default(false);

            // Distinct from matricule and issued by Admissions - the profile
            // mockup shows both (HA2026-00045 and ADM/2026/045).
            $table->string('admission_no', 32)->collation('utf8mb4_0900_as_cs')->unique();

            // Names take the connection default utf8mb4_0900_ai_ci: searching
            // for "Mbah" must find "Mbáh".
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('preferred_name', 80)->nullable();

            // Age is DERIVED from this against business_date(), never stored -
            // a stored age is wrong from the day after it is written.
            $table->date('date_of_birth');

            $table->string('birth_certificate_no', 64)->nullable();
            $table->string('place_of_birth', 120)->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->char('nationality', 2)->default('CM');

            // Free text: 07-students 15 item 5 - the 10-region list is stable
            // but department names are unverified, so nothing is seeded.
            $table->string('state_of_origin', 80)->nullable();

            // 00-core 9.5 encrypted fields. In Cameroon genotype is sickle-cell
            // status: health data about a child, which v1 left in a plain
            // column. TEXT rather than the spec's VARCHAR(60)/VARCHAR(5)
            // because Laravel's non-deterministic ciphertext is a base64
            // envelope several hundred bytes long - the spec states the
            // PLAINTEXT width. These columns can never be indexed or made
            // unique, which is why national_id gets a blind index below.
            $table->text('religion')->nullable();
            $table->text('blood_group')->nullable();
            $table->text('genotype')->nullable();
            $table->text('national_id_number')->nullable();

            // HMAC-SHA256(value, key) - the only way to get uniqueness on an
            // encrypted column (00-core 9.5). NULL where no ID is held; MySQL
            // permits many NULLs in a UNIQUE index, which is the behaviour
            // wanted here.
            $table->char('national_id_blind_index', 64)->nullable()->unique();

            // Private disk only, served through a policy-checked controller.
            // Never storage:link (08-operations); acceptance criterion 14 is a
            // logged-out request for this path returning 404.
            $table->string('photo_path', 255)->nullable();

            // The student's own, present on the profile mockup for seniors.
            $table->string('phone', 24)->nullable();
            $table->string('email', 160)->nullable();

            $table->string('address_line', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();

            // RESTRICT: a house with students in it is not deletable.
            $table->foreignId('house_id')->nullable()->constrained('houses')->restrictOnDelete();

            // 07-students 3.2: a DERIVED cache of the enrollment history,
            // recomputed by one Action and never hand-edited. The model keeps
            // it out of $fillable and guards writes for that reason.
            $table->enum('status', [
                'prospective', 'active', 'inactive', 'graduated',
                'transferred_out', 'withdrawn', 'deceased',
            ])->default('prospective');

            $table->date('first_admission_date')->nullable();
            $table->date('left_on')->nullable();

            // The first row of the 3.2 derivation table, and one of only two
            // student-level flags an operator sets directly.
            $table->date('deceased_on')->nullable();

            // 00-core 10.5: archive flag, never SoftDeletes. SoftDeletes plus
            // a UNIQUE matricule would let a deleted row block re-creation
            // forever.
            $table->boolean('is_archived')->default(false);

            // Actor FKs are RESTRICT (00-core 10.5): users are deactivated,
            // never deleted. Nullable because a student created by an import
            // job or a console command has a system actor and no user row.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            // 08-operations erasure path: students are pseudonymised, never
            // deleted.
            $table->timestamp('pseudonymised_at')->nullable();

            $table->timestamps();

            // 07-students 13. matricule, admission_no and
            // national_id_blind_index are already unique-indexed above.
            $table->index(['last_name', 'first_name'], 'students_name_index');
            $table->index('status', 'students_status_index');
            // house_id carries an index from its foreign key.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
