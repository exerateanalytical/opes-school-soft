<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildAcademics;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use App\Modules\Guardians\Support\Portal\GuardianInbox;
use App\Modules\Guardians\Support\Portal\PublishedResults;
use App\Modules\Guardians\Support\PortalContext;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal` - the parent dashboard, built to mobile/parent-dashboard.png.
 *
 * 07-students.md 7.5 row 1 is the floor: every child this guardian holds a
 * currently-valid link for appears here, however narrow their other flags are.
 *
 * The four overview tiles are the interesting part. Each one is built ONLY if
 * the matrix grants the capability behind it, and an absent tile is ABSENT -
 * never rendered as zero or as a dash. A zero would tell a parent their child's
 * balance is nothing when the truth is that the school does not share fees
 * with them, and those are very different statements. The design shows four
 * tiles because its imaginary parent holds everything; a real parent may see
 * one.
 *
 * Every figure comes from the same readers the API uses, so the dashboard and
 * the detail screen behind it cannot disagree.
 */
#[Layout('layouts.portal')]
final class Dashboard extends Component
{
    public function render(): mixed
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        $policy = app(GuardianPortalPolicy::class);
        $links = $context->validLinks();
        $studentIds = array_map(static fn ($link): int => (int) $link->student_id, $links);

        $students = $studentIds === [] ? collect() : DB::table('students')
            ->whereIn('id', $studentIds)
            ->get(['id', 'first_name', 'last_name', 'matricule', 'photo_path'])
            ->keyBy('id');

        $classNames = $studentIds === [] ? collect() : DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->whereIn('enr.student_id', $studentIds)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->get(['enr.student_id', 'cg.name'])
            ->unique('student_id')
            ->keyBy('student_id');

        $children = [];

        foreach ($links as $link) {
            $row = $students->get($link->student_id);

            if ($row === null) {
                continue;
            }

            $children[] = [
                'id' => (int) $link->student_id,
                'name' => trim($row->first_name.' '.$row->last_name),
                'matricule' => (string) $row->matricule,
                'class' => optional($classNames->get($link->student_id))->name,
            ];
        }

        $userId = (int) (auth()->id() ?? 0);

        return view('livewire.guardians.portal.dashboard', [
            'guardianName' => $context->guardian->fullName(),
            'children' => $children,
            'tiles' => $this->tiles($policy, $children[0]['id'] ?? null, $children[0]['name'] ?? ''),
            'unreadMessages' => $this->unreadMessages($userId),
            'recentMessages' => $this->recentMessages($userId),
            'upcoming' => $this->upcoming($userId),
            'quickActions' => $this->quickActions($policy, $children[0]['id'] ?? null),
        ]);
    }

    /**
     * The overview row. One entry per capability actually held - see the class
     * docblock for why an absent tile is absent rather than zero.
     *
     * @return list<array{label: string, value: string, caption: string|null, icon: string, tone: string}>
     */
    private function tiles(GuardianPortalPolicy $policy, ?int $studentId, string $childName): array
    {
        if ($studentId === null) {
            return [];
        }

        $tiles = [];

        // Academic average, straight off the published snapshot - never
        // recomputed, so it agrees with the printed bulletin (01-assessment
        // 13.3).
        if ($policy->allows(GuardianCapability::R05ViewReportCard, $studentId)) {
            $snapshot = app(PublishedResults::class)->snapshots($studentId)->first();
            $average = $snapshot === null
                ? null
                : (app(PublishedResults::class)->payload($snapshot, false)['general_average'] ?? null);

            if ($average !== null) {
                $tiles[] = [
                    'label' => __('opes.guardian_portal.tile_average'),
                    'value' => rtrim(rtrim((string) $average, '0'), '.').'%',
                    'caption' => __('opes.guardian_portal.tile_average_caption'),
                    'icon' => 'chart',
                    'tone' => 'primary',
                ];
            }
        }

        if ($policy->allows(GuardianCapability::R11ViewAttendanceSummary, $studentId)) {
            $summary = app(ChildAcademics::class)->attendanceSummaries($studentId)->first();

            if ($summary !== null && (int) $summary->sessions_expected > 0) {
                $rate = (int) round(((int) $summary->sessions_present / (int) $summary->sessions_expected) * 100);

                $tiles[] = [
                    'label' => __('opes.guardian_portal.tile_attendance'),
                    'value' => $rate.'%',
                    'caption' => __('opes.guardian_portal.tile_attendance_caption'),
                    'icon' => 'calendar',
                    'tone' => $rate >= 90 ? 'success' : 'warning',
                ];
            }
        }

        $canFees = $policy->allows(GuardianCapability::R13ViewInvoices, $studentId)
            || $policy->allows(GuardianCapability::R14ViewFeeStatement, $studentId);

        if ($canFees) {
            $fees = app(ChildFeeStatement::class);
            $enrollmentId = $fees->latestEnrollmentId($studentId);

            if ($enrollmentId !== null) {
                $totals = $fees->totals($enrollmentId);

                $tiles[] = [
                    'label' => __('opes.guardian_portal.tile_outstanding'),
                    'value' => Money::of($totals['outstanding'])->format(),
                    'caption' => $totals['next_due_on'] === null
                        ? null
                        : __('opes.guardian_portal.fees_due').' '.$totals['next_due_on'],
                    'icon' => 'wallet',
                    'tone' => $totals['outstanding'] > 0 ? 'danger' : 'success',
                ];
            }
        }

        // Rank needs row 9 specifically - a parent may see the average without
        // being entitled to where their child stands against the class.
        if ($policy->allows(GuardianCapability::R09ViewRankAndClassMean, $studentId)) {
            $snapshot = app(PublishedResults::class)->snapshots($studentId)->first();
            $payload = $snapshot === null ? [] : app(PublishedResults::class)->payload($snapshot, true);

            if (($payload['rank_position'] ?? null) !== null) {
                $tiles[] = [
                    'label' => __('opes.guardian_portal.tile_rank', ['name' => $childName]),
                    'value' => $payload['rank_position'].' / '.($payload['rank_denominator'] ?? '—'),
                    'caption' => null,
                    'icon' => 'chart',
                    'tone' => 'primary',
                ];
            }
        }

        return $tiles;
    }

    private function unreadMessages(int $userId): int
    {
        return collect(app(\App\Modules\Communication\Actions\Messaging\ListThreadsForUser::class)->handle($userId))
            ->reject(static fn (array $thread): bool => $thread['kind'] === 'announcement')
            ->sum('unread_count');
    }

    /**
     * @return list<array{id: int, title: string, subtitle: string, unread: bool}>
     */
    private function recentMessages(int $userId): array
    {
        return collect(app(\App\Modules\Communication\Actions\Messaging\ListThreadsForUser::class)->handle($userId))
            ->reject(static fn (array $thread): bool => $thread['kind'] === 'announcement')
            ->take(3)
            ->map(static fn (array $thread): array => [
                'id' => $thread['id'],
                'title' => $thread['title'],
                'subtitle' => (string) ($thread['last_message_at'] ?? ''),
                'unread' => $thread['unread_count'] > 0,
            ])
            ->values()
            ->all();
    }

    /**
     * The "Upcoming Activities" panel. Backed by announcements, which is what
     * the school genuinely broadcasts - activities are a P0 non-goal and have
     * no endpoint, so inventing a calendar here would be fiction.
     *
     * @return list<array{month: string, day: string, title: string, when: string}>
     */
    private function upcoming(int $userId): array
    {
        return app(GuardianInbox::class)->announcements($userId, 3)
            ->map(static function (object $row): array {
                $at = $row->last_message_at === null ? now() : \Illuminate\Support\Carbon::parse($row->last_message_at);

                return [
                    'month' => $at->translatedFormat('M'),
                    'day' => $at->format('j'),
                    'title' => (string) $row->title,
                    'when' => $at->translatedFormat('j F Y'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, icon: string, href: string}>
     */
    private function quickActions(GuardianPortalPolicy $policy, ?int $studentId): array
    {
        $actions = [];

        if ($studentId !== null) {
            if ($policy->allows(GuardianCapability::R05ViewReportCard, $studentId)) {
                $actions[] = ['label' => __('opes.guardian_portal.tab_results'), 'icon' => 'book',
                    'href' => route('portal.children.results', $studentId)];
            }

            if ($policy->allows(GuardianCapability::R16ViewOwnPayments, $studentId)) {
                $actions[] = ['label' => __('opes.guardian_portal.tab_fees'), 'icon' => 'card',
                    'href' => route('portal.children.fees', $studentId)];
            }

            if ($policy->allows(GuardianCapability::R11ViewAttendanceSummary, $studentId)) {
                $actions[] = ['label' => __('opes.guardian_portal.tab_attendance'), 'icon' => 'calendar',
                    'href' => route('portal.children.attendance', $studentId)];
            }

            if ($policy->allows(GuardianCapability::R22ViewSchoolIssuedDocuments, $studentId)) {
                $actions[] = ['label' => __('opes.guardian_portal.tab_documents'), 'icon' => 'file',
                    'href' => route('portal.children.documents', $studentId)];
            }
        }

        $actions[] = ['label' => __('opes.guardian_portal.nav_messages'), 'icon' => 'chat',
            'href' => route('portal.messages')];
        $actions[] = ['label' => __('opes.guardian_portal.nav_account'), 'icon' => 'gear',
            'href' => route('portal.account')];

        return $actions;
    }
}
