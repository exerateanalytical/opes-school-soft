<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Guardians\Models\Guardian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo family from the reference screens in `mobile/*.png`.
 *
 * The portal is structurally correct but reads as a stranger's account: the
 * screens show Mrs. Ngo Laura with Emmanuel and Esther, three announcements
 * and a full inbox, and the database has Ferdinand Biya with one child and
 * nothing else. This makes what a reviewer sees match what they were shown.
 *
 * WHY IT ATTACHES TO THE EXISTING DEMO GUARDIAN rather than creating a new
 * family: the login screen's one-click "Sign in as Guardian" resolves to
 * demo.guardian1, so a new family would be invisible behind that button and
 * every reviewer would still land on Ferdinand. Renaming in place keeps the
 * one door that people actually use.
 *
 * Idempotent per section, like DemoDataSeeder: re-running converges rather
 * than duplicating.
 *
 * Fees and published results are NOT set here. Those go through
 * GenerateInvoices / IssueInvoice / RecordPayment and the assessment
 * publication chain, which own posting rules and journal entries; forging the
 * rows directly would produce a portal that disagrees with the ledger, which
 * is the one thing this whole build has been careful not to do.
 */
final class PortalShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        // Resolved by user id rather than a relation: Guardian has no
        // `portalUser` relation, only the `portal_user_id` column.
        $demoUserId = DB::table('users')->where('email', 'demo.guardian1@opeschool.test')->value('id');

        $guardian = ($demoUserId === null
            ? null
            : Guardian::query()->where('portal_user_id', $demoUserId)->first())
            ?? Guardian::query()->whereNotNull('portal_user_id')->orderBy('id')->first();

        if ($guardian === null) {
            $this->command?->warn('No portal guardian found - run DemoDataSeeder first.');

            return;
        }

        $this->dressGuardian($guardian);
        $children = $this->dressChildren($guardian);
        $userId = (int) $guardian->portal_user_id;

        $this->seedAnnouncements($userId);
        $this->seedConversations($userId, $children);
        $this->seedNotifications($userId, $children);
        $this->seedAttendance($children);
        $this->seedMedical($children);
        $this->seedFees($guardian, $children);

        $this->command?->info('Portal showcase: '.$guardian->fullName().' with '.count($children).' children.');
    }

    /** The parent from mobile/parent-profile.png. */
    private function dressGuardian(Guardian $guardian): void
    {
        $guardian->forceFill([
            'first_name' => 'Laura',
            'last_name' => 'Ngo',
            'title' => 'Mrs',
            'email' => 'laura.ngo@gmail.com',
            'phone' => Guardian::normalisePhone('+237675234567'),
            'address_line' => 'Bonamoussadi',
            'city' => 'Douala',
            'region' => 'Littoral',
            'country' => 'CM',
            'occupation' => 'Entrepreneur',
            'notify_sms' => true,
            'notify_email' => true,
            'notify_push' => true,
        ])->save();

        // The user row carries the name the header greets.
        DB::table('users')->where('id', $guardian->portal_user_id)
            ->update(['name' => 'Mrs. Ngo Laura']);
    }

    /**
     * Emmanuel (Grade 6B, HBC24567) and Esther (Grade 4A, HBC35678).
     *
     * Renames the children already linked to this guardian rather than
     * inserting new ones: a new student would need an enrollment, a class
     * segment and a fee structure to be visible at all, and the linked
     * children already have every one of those.
     *
     * @return list<int>
     */
    private function dressChildren(Guardian $guardian): array
    {
        $linked = DB::table('student_guardians')
            ->where('guardian_id', $guardian->getKey())
            ->orderBy('student_id')
            ->pluck('student_id')
            ->all();

        /*
         * The designs show a parent with several children, and the carousel is
         * the first thing on the dashboard - with one card it reads as a list
         * of one rather than as the child switcher it is.
         *
         * So link a SECOND existing student. An existing one, never a new one:
         * a fresh student would need an enrollment, a class segment and a fee
         * structure before any child screen had anything to show, whereas the
         * already-seeded cohort has all three.
         *
         * The link is given the full flag set, because the second child exists
         * to demonstrate the switcher and every tab behind it. The FIRST
         * child's link is left exactly as the demo seeder made it.
         */
        if (count($linked) < 2) {
            $spare = DB::table('students as s')
                ->join('enrollments as e', 'e.student_id', '=', 's.id')
                ->whereNotIn('s.id', $linked === [] ? [0] : $linked)
                ->whereNotExists(function ($q) use ($guardian): void {
                    $q->select(DB::raw(1))->from('student_guardians as sg')
                        ->whereColumn('sg.student_id', 's.id')
                        ->where('sg.guardian_id', $guardian->getKey());
                })
                ->orderBy('s.id')
                ->value('s.id');

            if ($spare !== null) {
                DB::table('student_guardians')->insert([
                    'student_id' => (int) $spare,
                    'guardian_id' => $guardian->getKey(),
                    'relationship' => 'mother',
                    'is_primary' => false,
                    'has_custody' => true,
                    'receives_reports' => true,
                    'receives_invoices' => true,
                    'is_emergency_contact' => true,
                    'is_authorised_for_pickup' => true,
                    'is_fee_payer' => true,
                    // Matches DemoDataSeeder's own value, so a re-seed agrees.
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $linked[] = (int) $spare;
            }
        }

        $cast = [
            ['first_name' => 'Emmanuel', 'last_name' => 'Ngo', 'matricule' => 'HBC24567', 'gender' => 'male'],
            ['first_name' => 'Esther', 'last_name' => 'Ngo', 'matricule' => 'HBC35678', 'gender' => 'female'],
        ];

        $ids = [];

        foreach ($linked as $index => $studentId) {
            if (! isset($cast[$index])) {
                break;
            }

            // `matricule` is unique - free it from any other row first so a
            // re-run against a half-applied database still converges.
            DB::table('students')
                ->where('matricule', $cast[$index]['matricule'])
                ->where('id', '!=', $studentId)
                ->update(['matricule' => DB::raw("CONCAT('OLD-', id)")]);

            DB::table('students')->where('id', $studentId)->update($cast[$index]);
            $ids[] = (int) $studentId;
        }

        return $ids;
    }

    /** The three activities from the dashboard's Upcoming panel. */
    private function seedAnnouncements(int $userId): void
    {
        $announcements = [
            ['Science Fair 2024', "Our annual Science Fair takes place in the School Main Hall from 08:00 to 13:00.\n\nParents are warmly invited to attend and view the projects.", 15],
            ['End of Term 3 Exams', "End of term examinations begin across all classrooms.\n\nPlease ensure your child arrives by 07:30 each day with all required materials.", 20],
            ['PTA Meeting', "The Parent-Teacher Association meets in the Conference Room from 14:00 to 16:00.\n\nAgenda: term review, fee schedule for next year, and the excursion programme.", 25],
        ];

        foreach ($announcements as [$title, $body, $day]) {
            $this->thread($userId, $title, $body, 'announcement', now()->setDay(min($day, 28)));
        }
    }

    /** The three rows in the dashboard's Recent Messages panel. */
    private function seedConversations(int $userId, array $children): void
    {
        $child = $children[0] ?? null;
        $childName = $child === null ? 'your child' : (string) DB::table('students')->where('id', $child)->value('first_name');

        $conversations = [
            ['Term 3 Report Cards Released', 'Report cards for Term 3 are now available in the portal under Results.', 2],
            ['School Excursion to Limbe', 'Permission slips are now available. Please collect one from the school office.', 1],
            ['Discipline Update: '.$childName, 'New comment from the Class Teacher regarding conduct this week.', 0],
        ];

        foreach ($conversations as [$title, $body, $daysAgo]) {
            $this->thread($userId, $title, $body, 'conversation', now()->subDays($daysAgo));
        }
    }

    /**
     * One thread with one message, and this guardian as a participant.
     *
     * `last_read_message_id` is left null so the thread reads as UNREAD -
     * which is what the designs show, and what makes the bell badge and the
     * gold "you have N unread messages" strip appear at all.
     */
    private function thread(int $userId, string $title, string $body, string $kind, \DateTimeInterface $at): void
    {
        $existing = DB::table('message_threads')->where('title', $title)->value('id');

        if ($existing !== null) {
            return;
        }

        $threadId = (int) DB::table('message_threads')->insertGetId([
            'title' => $title,
            'kind' => $kind,
            'created_by' => $userId,
            'last_message_at' => $at,
            'is_archived' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('messages')->insert([
            'message_thread_id' => $threadId,
            'sender_id' => $userId,
            'body' => $body,
            'is_system' => $kind === 'announcement',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('message_thread_participants')->insert([
            'message_thread_id' => $threadId,
            'user_id' => $userId,
            'added_at' => $at,
            'is_muted' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** The bell badge in the header. */
    private function seedNotifications(int $userId, array $children): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('notifications')) {
            return;
        }

        $notices = [
            ['results.published', 'Term 3 report cards released', 'Report cards for Term 3 are now available.'],
            ['fees.due', 'School fees due 15 May', 'Two fee items are due this month.'],
            ['school.announcement', 'School Excursion to Limbe', 'Permission slips are now available.'],
        ];

        foreach ($notices as $index => [$kind, $title, $body]) {
            $exists = DB::table('notifications')
                ->where('user_id', $userId)->where('title', $title)->exists();

            if ($exists) {
                continue;
            }

            DB::table('notifications')->insert([
                'user_id' => $userId,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'read_at' => null,
                'created_at' => now()->subDays($index),
                'updated_at' => now()->subDays($index),
            ]);
        }
    }

    /**
     * Fee activity a parent can actually see TODAY.
     *
     * DemoDataSeeder bills the 2026/2027 year on 2026-09-08. Today is earlier
     * than that, and ChildFeeStatement filters `issue_date <= today`, so the
     * portal correctly shows nothing owed - the year has not been billed yet.
     * That is right, not a bug, and it is why the Outstanding tile reads zero.
     *
     * So this issues a SECOND invoice dated in the past rather than
     * back-dating the existing one. Moving an issued invoice's date would
     * desynchronise it from the journal entry that posted it, which is exactly
     * the portal-disagrees-with-the-ledger failure this build has avoided
     * throughout.
     *
     * Everything goes through the real Actions - GenerateInvoices, IssueInvoice,
     * RecordPayment - so the posting rules fire and the books balance. A
     * partial payment leaves a visible outstanding balance, as the designs show.
     */
    private function seedFees(Guardian $guardian, array $children): void
    {
        if ($children === []) {
            return;
        }

        $accountant = DB::table('users')->where('email', 'demo.bursar@opeschool.test')->first();

        if ($accountant === null) {
            $this->command?->warn('Fees: demo bursar missing - run DemoDataSeeder first.');

            return;
        }

        $bursar = \App\Modules\Identity\Models\User::query()->find($accountant->id);
        $actor = $bursar?->toAuditActor();

        if ($bursar === null || $actor === null) {
            return;
        }

        /*
         * The fee Actions call Gate::authorize, and a seeder has no
         * authenticated user - so without this every call answers "This action
         * is unauthorized". DemoDataSeeder does the same thing for the same
         * reason. Signing in as the BURSAR rather than an admin keeps the
         * audit trail honest about who issued these invoices.
         */
        $previous = auth()->user();
        \Illuminate\Support\Facades\Auth::login($bursar);

        foreach ($children as $studentId) {
            // `fiscal_year_id` lives on the INVOICE, not the enrollment - the
            // two calendars are deliberately separate (02-accounting).
            $enrollment = DB::table('enrollments')->where('student_id', $studentId)
                ->orderByDesc('id')->first(['id', 'academic_year_id']);

            if ($enrollment === null) {
                continue;
            }

            $fiscalYearId = DB::table('invoices')->where('enrollment_id', $enrollment->id)
                ->value('fiscal_year_id')
                ?? DB::table('fiscal_years')->orderByDesc('id')->value('id');

            if ($fiscalYearId === null) {
                continue;
            }

            $key = 'showcase-invoice-'.$enrollment->id;

            if (DB::table('invoices')->where('idempotency_key', $key)->exists()) {
                continue;
            }

            try {
                $result = app(\App\Modules\Fees\Actions\GenerateInvoices::class)->forEnrollments(
                    [(int) $enrollment->id],
                    [
                        'academic_year_id' => (int) $enrollment->academic_year_id,
                        'fiscal_year_id' => (int) $fiscalYearId,
                        'term_id' => null,
                        // Billed a month ago, due a fortnight ago: an overdue
                        // balance is what the reference dashboard shows.
                        'issue_date' => now()->subMonth()->toDateString(),
                        'due_date' => now()->subDays(14)->toDateString(),
                    ],
                    $actor,
                );

                $created = $result['created'] ?? [];

                if ($created === []) {
                    /*
                     * GenerateInvoices refuses to bill an enrollment twice for
                     * the same academic year, which is correct - and it means
                     * this showcase CANNOT add current-dated fees on top of
                     * DemoDataSeeder's future-dated ones.
                     *
                     * So the Outstanding tile reads zero, and that is the
                     * truth rather than a gap: the 2026/2027 year is billed on
                     * 2026-09-08, today is earlier, and a parent genuinely owes
                     * nothing yet. Making it show a balance would require
                     * moving the demo billing date AND the journal entry that
                     * posted it - an accounting change, not a cosmetic one,
                     * and not something a showcase seeder should do quietly.
                     */
                    $this->command?->warn(
                        'Fees: enrollment '.$enrollment->id.' is already billed for its year '
                        .'(issue date '.(DB::table('invoices')->where('enrollment_id', $enrollment->id)->value('issue_date') ?? '?')
                        .'). Nothing owed until then, so the Outstanding tile stays at zero.'
                    );

                    continue;
                }

                $issued = app(\App\Modules\Fees\Actions\IssueInvoice::class)->handle($created, $actor);

                foreach ($issued as $invoice) {
                    $gross = (int) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->sum('amount');

                    if ($gross <= 0) {
                        continue;
                    }

                    // 60% paid - the proportion the fees dashboard shows.
                    $paid = (int) round($gross * 0.6);
                    $treasury = DB::table('chart_of_accounts')->where('code', '571')->value('id');

                    app(\App\Modules\Fees\Actions\RecordPayment::class)->handle(
                        studentId: (int) $invoice->student_id,
                        academicYearId: (int) $enrollment->academic_year_id,
                        fiscalYearId: (int) $fiscalYearId,
                        method: \App\Modules\Fees\Domain\PaymentMethod::Cash,
                        amount: \App\Support\Money\Money::of($paid),
                        payerName: $guardian->fullName(),
                        valueDate: now()->subDays(20)->toDateString(),
                        actor: $actor,
                        feeAmount: \App\Support\Money\Money::zero(),
                        feeBearer: \App\Modules\Fees\Domain\FeeBearer::None,
                        reference: null,
                        // The guardian's OWN number, so row 16's best-effort
                        // phone match marks this receipt as theirs.
                        payerPhone: $guardian->phone,
                        enrollmentId: (int) $invoice->enrollment_id,
                        targets: null,
                        idempotencyKey: 'showcase-payment-'.$invoice->id,
                        notes: 'Portal showcase payment',
                        treasuryAccountId: is_numeric($treasury) ? (int) $treasury : null,
                    );
                }
            } catch (\Throwable $e) {
                // Reported, not swallowed: fee posting depends on rules and a
                // chart of accounts this seeder does not own, and a silent
                // skip would leave a reviewer wondering why the tile is empty.
                $this->command?->warn('Fees for student '.$studentId.': '.$e->getMessage());
            }
        }

        // Put the guard back as it was, so a caller that chained seeders after
        // this one is not silently left signed in as the bursar.
        if ($previous === null) {
            \Illuminate\Support\Facades\Auth::logout();
        } else {
            \Illuminate\Support\Facades\Auth::login($previous);
        }
    }

    /**
     * The medical records behind Health, Medical history, Immunisations and
     * the Health ID card.
     *
     * `is_emergency_relevant` is what splits rows 3 and 4, so the set
     * deliberately spans both: a blood group and an allergy an emergency
     * contact must see, and a clinical note only a custodial guardian may
     * read. Seeding only one kind would make the scope split invisible, which
     * is the single most important thing these screens demonstrate.
     */
    private function seedMedical(array $children): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('student_medical_records')) {
            return;
        }

        /*
         * `condition_type` and `severity` are both ENUMs - allergy /
         * chronic_condition / medication / disability / immunisation /
         * incident, and low / moderate / high. Inventing values outside them
         * fails at insert, so these are the schema's own.
         */
        $records = [
            ['allergy', 'Peanut allergy - carries an auto-injector', 'Adrenaline auto-injector kept in the school bag and with the nurse. Notify the clinic immediately on exposure.', 'high', true],
            ['chronic_condition', 'Blood group O+', 'Recorded at admission. No transfusion history on file.', 'low', true],
            ['immunisation', 'BCG, Polio and Measles - complete', 'Administered under the national schedule. Booster due next academic year.', 'low', false],
            ['immunisation', 'Yellow fever - administered', 'Given at the district clinic; certificate held by the school office.', 'low', false],
            ['chronic_condition', 'Mild asthma', 'Inhaler kept with the school nurse. Exercise tolerance normal; no restriction on sport.', 'moderate', false],
        ];

        foreach ($children as $studentId) {
            foreach ($records as [$type, $summary, $detail, $severity, $emergency]) {
                $exists = DB::table('student_medical_records')
                    ->where('student_id', $studentId)
                    ->where('summary', $summary)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('student_medical_records')->insert([
                    'student_id' => $studentId,
                    'condition_type' => $type,
                    'summary' => $summary,
                    'detail' => $detail,
                    'severity' => $severity,
                    'is_emergency_relevant' => $emergency,
                    'recorded_at' => now()->subMonths(3),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** The 95% attendance tile. */
    private function seedAttendance(array $children): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('attendance_summaries')) {
            return;
        }

        foreach ($children as $studentId) {
            $enrollmentId = DB::table('enrollments')->where('student_id', $studentId)->value('id');
            $periodId = DB::table('assessment_periods')->orderByDesc('starts_on')->value('id');

            if ($enrollmentId === null || $periodId === null) {
                continue;
            }

            $exists = DB::table('attendance_summaries')
                ->where('enrollment_id', $enrollmentId)
                ->where('assessment_period_id', $periodId)
                ->exists();

            if ($exists) {
                continue;
            }

            // 57 of 60 sessions => 95%, the figure on the dashboard.
            DB::table('attendance_summaries')->insert([
                'enrollment_id' => $enrollmentId,
                'assessment_period_id' => $periodId,
                'sessions_expected' => 60,
                'sessions_present' => 57,
                'sessions_absent' => 2,
                'sessions_excused' => 1,
                'sessions_late' => 3,
                'retards' => 3,
                'hours_absent_justified' => 4,
                'hours_absent_unjustified' => 2,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
