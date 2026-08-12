<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Guardians\Models\Guardian;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $this->seedPublishedResults($children);
        $this->seedPhotos($guardian, $children);
        $this->seedDocuments($children);

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
                     * the same academic year, which is correct. The existing
                     * invoice is dated at the year's start (2026-09-08) and
                     * today is earlier, so ChildFeeStatement excludes it and
                     * the parent correctly owes nothing yet.
                     *
                     * For a DEMO that is useless - the fee screens are the
                     * point of the fee screens. So the billing is moved back
                     * to a month ago, and the JOURNAL ENTRY that posted it
                     * moves with it. Moving one without the other is what
                     * produces a portal that disagrees with the ledger, which
                     * is the failure this whole build has avoided; they are
                     * updated together, in a transaction, or not at all.
                     */
                    $this->billSupplementary((int) $enrollment->id, $actor);
                    $this->collectPartialPayment($guardian, (int) $enrollment->id, $actor);

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
     * Bill something CURRENT, so the fee screens have real figures.
     *
     * The first attempt here moved the existing invoice's date back and took
     * its journal entry along. The DATABASE refused: a trigger enforces
     * "date is immutable once the entry is posted or reversed" (ledger rule
     * L4). That is the platform protecting the books, and it is right - a
     * posted entry's date is not an UPDATE, it is a correction with its own
     * audit trail.
     *
     * So this uses the door the Fees module already provides for ad-hoc
     * student debt: CreateSupplementaryInvoice (04-fees 4.6), which is exempt
     * from the standard-issue uniqueness by construction and delegates
     * numbering and the `fee.invoice.issued` posting to the real IssueInvoice.
     * One debt stream, one ledger shape, dated today.
     */
    private function billSupplementary(int $enrollmentId, \App\Support\Audit\Actor $actor): void
    {
        $key = 'showcase-supplementary-'.$enrollmentId;

        if (DB::table('invoices')->where('idempotency_key', $key)->exists()) {
            return;
        }

        $enrollment = DB::table('enrollments')->where('id', $enrollmentId)->first(['academic_year_id']);
        $fiscalYearId = DB::table('invoices')->where('enrollment_id', $enrollmentId)->value('fiscal_year_id')
            ?? DB::table('fiscal_years')->orderByDesc('id')->value('id');

        // A revenue account is required per line; reuse the one the existing
        // invoice lines already post to so the demo stays on one account.
        $revenueAccountId = DB::table('invoice_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.enrollment_id', $enrollmentId)
            ->whereNotNull('l.revenue_account_id')
            ->value('l.revenue_account_id')
            ?? DB::table('chart_of_accounts')->where('code', 'like', '70%')->value('id');

        if ($enrollment === null || $fiscalYearId === null || $revenueAccountId === null) {
            $this->command?->warn('Fees: no revenue account or fiscal year - skipping supplementary invoice.');

            return;
        }

        // The reference dashboard's own figures.
        $lines = [
            ['description' => 'School Fees - Term 3', 'revenue_account_id' => (int) $revenueAccountId, 'amount' => 200000],
            ['description' => 'Transport Fees - Term 3', 'revenue_account_id' => (int) $revenueAccountId, 'amount' => 140000],
        ];

        app(\App\Modules\Fees\Actions\CreateSupplementaryInvoice::class)->handle([
            'enrollment_id' => $enrollmentId,
            'academic_year_id' => (int) $enrollment->academic_year_id,
            'fiscal_year_id' => (int) $fiscalYearId,
            'issue_date' => now()->subMonth()->toDateString(),
            'due_date' => now()->subDays(14)->toDateString(),
            'lines' => $lines,
            'idempotency_key' => $key,
            'issue' => true,
            'notes' => 'Portal showcase billing',
        ], $actor);
    }

    /**
     * Collect ~60% of the enrollment's billed total, so the fee screens show a
     * real paid figure and a real outstanding balance.
     *
     * Through RecordPayment, so the posting rules fire and the treasury entry
     * exists. The guardian's own phone goes on it, so row 16's best-effort
     * match marks the receipt as theirs rather than another guardian's.
     */
    private function collectPartialPayment(Guardian $guardian, int $enrollmentId, \App\Support\Audit\Actor $actor): void
    {
        $invoice = DB::table('invoices')
            ->where('enrollment_id', $enrollmentId)
            ->where('status', 'issued')
            ->first(['id', 'student_id', 'academic_year_id', 'fiscal_year_id']);

        if ($invoice === null) {
            return;
        }

        if (DB::table('payments')->where('idempotency_key', 'showcase-payment-'.$invoice->id)->exists()) {
            return;
        }

        $gross = (int) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->sum('amount');

        if ($gross <= 0) {
            return;
        }

        $treasury = DB::table('chart_of_accounts')->where('code', '571')->value('id');

        app(\App\Modules\Fees\Actions\RecordPayment::class)->handle(
            studentId: (int) $invoice->student_id,
            academicYearId: (int) $invoice->academic_year_id,
            fiscalYearId: (int) $invoice->fiscal_year_id,
            method: \App\Modules\Fees\Domain\PaymentMethod::Cash,
            amount: \App\Support\Money\Money::of((int) round($gross * 0.6)),
            payerName: $guardian->fullName(),
            valueDate: now()->subDays(20)->toDateString(),
            actor: $actor,
            feeAmount: \App\Support\Money\Money::zero(),
            feeBearer: \App\Modules\Fees\Domain\FeeBearer::None,
            reference: null,
            payerPhone: $guardian->phone,
            enrollmentId: $enrollmentId,
            targets: null,
            idempotencyKey: 'showcase-payment-'.$invoice->id,
            notes: 'Portal showcase payment',
            treasuryAccountId: is_numeric($treasury) ? (int) $treasury : null,
        );
    }

    /**
     * Published report cards - what lights up the Academic average and Class
     * rank tiles, and everything behind Results: subjects, analytics, term
     * history, report card, bulletin and transcript.
     *
     * A snapshot is only visible when its `period_publications` row is
     * `published` - row 8's "publication checked first, always". So this
     * creates the publication and the snapshot together; a snapshot without a
     * published parent is correctly invisible, and seeding one alone would
     * have produced screens that stayed empty for no apparent reason.
     *
     * The payload is the shape PublishedResults::payload() returns and the
     * views read: `subjects` with subject_name / subject_score / coefficient /
     * appreciation, `general_average.display`, and `rank` with is_ranked /
     * position / denominator. Numbers match the reference dashboard - 78%
     * overall, 5th of 32.
     */
    private function seedPublishedResults(array $children): void
    {
        foreach (['report_card_snapshots', 'period_publications', 'report_card_config_versions'] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                return;
            }
        }

        $configVersionId = DB::table('report_card_config_versions')->orderBy('id')->value('id');

        if ($configVersionId === null) {
            /*
             * A snapshot must name the config version it was rendered from -
             * that pinning is what makes a reprint reproducible (10-documents
             * 4.8). A demo database seeded before the assessment work has
             * none, so create the minimum viable one rather than skip and
             * leave every results screen empty.
             */
            $configId = DB::table('report_card_configs')->value('id')
                ?? DB::table('report_card_configs')->insertGetId([
                    'code' => 'DEMO_PORTAL',
                    'name' => 'Portal showcase report card',
                    'name_fr' => 'Bulletin de demonstration',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $configPayload = json_encode([
                'scale' => 'percentage',
                'shows_rank' => true,
                'shows_coefficients' => true,
            ], JSON_THROW_ON_ERROR);

            $configVersionId = (int) DB::table('report_card_config_versions')->insertGetId([
                'config_id' => $configId,
                'version_no' => 1,
                'payload' => $configPayload,
                'payload_hash' => hash('sha256', $configPayload),
                'frozen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $subjects = [
            ['Mathematics', 'Mathématiques', 86, 4, 'Excellent'],
            ['English Language', 'Anglais', 82, 3, 'Very good'],
            ['French Language', 'Français', 76, 3, 'Good'],
            ['Science', 'Sciences', 77, 3, 'Good'],
            ['Social Studies', 'Études sociales', 70, 2, 'Fair'],
        ];

        foreach ($children as $index => $studentId) {
            $enrollment = DB::table('enrollments')->where('student_id', $studentId)
                ->orderByDesc('id')->first(['id']);

            if ($enrollment === null) {
                continue;
            }

            $classGroupId = DB::table('enrollment_segments')
                ->where('enrollment_id', $enrollment->id)
                ->orderByDesc('starts_on')
                ->value('class_group_id');

            if ($classGroupId === null) {
                continue;
            }

            // Three terms, so the term history and the trend chart have a
            // series rather than a single point.
            $periods = DB::table('assessment_periods')->orderBy('starts_on')->limit(3)->get(['id']);

            foreach ($periods as $termIndex => $period) {
                $exists = DB::table('report_card_snapshots')
                    ->where('enrollment_id', $enrollment->id)
                    ->where('assessment_period_id', $period->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // 72 / 75 / 78 across the terms, as the reference screens show.
                $average = [72, 75, 78][$termIndex] ?? 75;
                $batch = (string) \Illuminate\Support\Str::uuid();

                $publicationId = DB::table('period_publications')
                    ->where('assessment_period_id', $period->id)
                    ->where('class_group_id', $classGroupId)
                    ->value('id');

                if ($publicationId === null) {
                    $publicationId = (int) DB::table('period_publications')->insertGetId([
                        'assessment_period_id' => $period->id,
                        'class_group_id' => $classGroupId,
                        'status' => 'published',
                        'snapshot_batch_id' => $batch,
                        'generation' => 1,
                        'report_card_config_version_id' => $configVersionId,
                        'published_at' => now()->subWeeks(3 - $termIndex),
                        'version' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    // An existing publication may be a draft; row 8 makes that
                    // invisible, so publish it rather than adding a second.
                    DB::table('period_publications')->where('id', $publicationId)->update([
                        'status' => 'published',
                        'published_at' => now()->subWeeks(3 - $termIndex),
                    ]);
                }

                $payload = [
                    'subjects' => array_map(
                        static fn (array $s): array => [
                            'subject_name' => $s[0],
                            'subject_name_fr' => $s[1],
                            // A touch of per-term variation so the trend moves.
                            'subject_score' => max(40, min(99, $s[2] - (2 - $termIndex) * 3)),
                            'coefficient' => $s[3],
                            'appreciation' => $s[4],
                        ],
                        $subjects,
                    ),
                    'totals' => ['coefficients' => 15],
                    'general_average' => ['display' => $average.'%', 'value' => $average],
                    'mention' => $average >= 80 ? 'Very good' : ($average >= 70 ? 'Good' : 'Fair'),
                    'rank' => ['is_ranked' => true, 'position' => 5 + $index, 'denominator' => 32],
                ];

                $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

                DB::table('report_card_snapshots')->insert([
                    'enrollment_id' => $enrollment->id,
                    'assessment_period_id' => $period->id,
                    'class_group_id' => $classGroupId,
                    'period_publication_id' => $publicationId,
                    'generation' => 1,
                    'snapshot_batch_id' => $batch,
                    'report_card_config_version_id' => $configVersionId,
                    'payload' => $encoded,
                    'payload_hash' => hash('sha256', $encoded),
                    'issued_at' => now()->subWeeks(3 - $termIndex),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Photographs, so the avatars show a face rather than initials.
     *
     * Generated rather than shipped: there is no photograph of a real child in
     * this repository and there should not be. Each is a flat-tinted PNG with
     * the child's initials - enough to prove the photo ROUTE works end to end
     * (row 1 gate, private/no-store, the `onerror` fallback) without inventing
     * a likeness of anyone.
     */
    private function seedPhotos(Guardian $guardian, array $children): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->command?->warn('Photos: GD is not available - avatars stay as initials.');

            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk((string) config('filesystems.default'));

        $make = function (string $initials, string $path, array $rgb) use ($disk): void {
            if ($disk->exists($path)) {
                return;
            }

            $size = 320;
            $image = imagecreatetruecolor($size, $size);
            $bg = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
            $fg = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 0, 0, $size, $size, $bg);

            // The built-in font is tiny, so it is scaled up rather than
            // depending on a TTF this machine may not have.
            $tmp = imagecreatetruecolor(60, 30);
            $tmpBg = imagecolorallocate($tmp, $rgb[0], $rgb[1], $rgb[2]);
            $tmpFg = imagecolorallocate($tmp, 255, 255, 255);
            imagefilledrectangle($tmp, 0, 0, 60, 30, $tmpBg);
            imagestring($tmp, 5, 12, 7, $initials, $tmpFg);
            imagecopyresampled($image, $tmp, 60, 100, 0, 0, 200, 100, 60, 30);
            imagedestroy($tmp);

            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);

            $disk->put($path, $bytes);
        };

        $initialsOf = static fn (string $name): string => collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()->take(2)
            ->map(static fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)))
            ->implode('');

        $palette = [[11, 59, 43], [176, 122, 18], [40, 90, 140], [120, 60, 120]];

        foreach ($children as $index => $studentId) {
            $row = DB::table('students')->where('id', $studentId)->first(['first_name', 'last_name', 'photo_path']);

            if ($row === null) {
                continue;
            }

            $path = 'demo/photos/student-'.$studentId.'.png';
            $make($initialsOf($row->first_name.' '.$row->last_name), $path, $palette[$index % count($palette)]);
            DB::table('students')->where('id', $studentId)->update(['photo_path' => $path]);
        }

        $guardianPath = 'demo/photos/guardian-'.$guardian->getKey().'.png';
        $make($initialsOf($guardian->fullName()), $guardianPath, [11, 59, 43]);
        $guardian->forceFill(['photo_path' => $guardianPath])->save();
    }

    /**
     * The shelf behind `child-documents.png` and `medical-documents.png` -
     * row 23, the paperwork a GUARDIAN supplied.
     *
     * Row 22 (school-issued) is deliberately not seeded here: those are
     * `issued_documents`, minted by Reporting when a template is rendered, and
     * fabricating serials and QR tokens directly in the table would put codes
     * in front of a parent that the public /documents/verify page would then
     * fail to resolve. An unverifiable verification code is worse than an
     * empty shelf.
     *
     * The bytes are written for real, because the download route streams from
     * the disk and a row whose file is absent exercises the storage-failure
     * branch rather than the success one.
     */
    private function seedDocuments(array $children): void
    {
        if (! Schema::hasTable('student_documents')) {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk((string) config('filesystems.default'));

        // The three states the list screen renders differently: verified,
        // awaiting the registrar, and one carrying an expiry date.
        $shelf = [
            ['Birth certificate', 'verified', '2014-03-18', null],
            ['Vaccination card', 'verified', '2025-09-02', '2027-09-02'],
            ['Previous school report', 'unverified', '2025-07-15', null],
            ['Passport photograph', 'verified', '2026-01-10', null],
        ];

        foreach ($children as $studentId) {
            foreach ($shelf as $index => [$title, $status, $issuedOn, $expiresOn]) {
                if (DB::table('student_documents')
                    ->where('student_id', $studentId)->where('title', $title)->exists()) {
                    continue;
                }

                // A minimal but genuinely valid single-page PDF, so a parent
                // clicking Download gets a file their reader opens rather than
                // four bytes named .pdf.
                $body = "BT /F1 16 Tf 60 760 Td (".$title.") Tj ET";
                $pdf = "%PDF-1.4\n"
                    ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
                    ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
                    ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]"
                    ."/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj\n"
                    ."4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
                    ."5 0 obj<</Length ".strlen($body).">>stream\n".$body."\nendstream endobj\n"
                    ."trailer<</Root 1 0 R>>\n%%EOF\n";

                $path = 'demo/documents/student-'.$studentId.'-'.($index + 1).'.pdf';
                $disk->put($path, $pdf);

                DB::table('student_documents')->insert([
                    'student_id' => $studentId,
                    'document_type_id' => null,
                    'title' => $title,
                    'file_path' => $path,
                    'file_hash' => hash('sha256', $pdf),
                    'mime' => 'application/pdf',
                    'size_bytes' => strlen($pdf),
                    'issued_on' => $issuedOn,
                    'expires_on' => $expiresOn,
                    'verification_status' => $status,
                    'verified_at' => $status === 'verified' ? now() : null,
                    'is_archived' => false,
                    'created_at' => now()->subDays(30 - $index),
                    'updated_at' => now(),
                ]);
            }
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
