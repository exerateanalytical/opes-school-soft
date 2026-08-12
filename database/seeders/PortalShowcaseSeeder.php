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
