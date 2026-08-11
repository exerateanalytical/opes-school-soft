<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guardian-scoped search, for both doors.
 *
 * This class is the single most important one in this namespace not to
 * duplicate, which is why it exists at all rather than living in the API
 * controller: search leaks differently from a read. A result COUNT, a snippet
 * or an autocomplete suggestion discloses that a record exists even when the
 * record itself is withheld - so a second implementation that filtered
 * afterwards instead of gating first would be a hole that no test of the
 * first implementation could catch.
 *
 * The design, therefore, is not "search an index, then filter". It walks the
 * caller's own valid links and, per child, asks the SAME capability the
 * corresponding read screen asks BEFORE it queries that source at all. A
 * guardian without row 13 does not get an invoice search that returns nothing;
 * they get no invoice search.
 *
 * It covers only what has shipped. A source with no vetted reader has no
 * re-scoped search either, because there would be nothing to route it through.
 */
final class GuardianSearch
{
    /** Below this a query matches half the school and tells the caller nothing. */
    public const MIN_LENGTH = 2;

    private const MAX_PER_SOURCE = 10;

    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly ChildFeeStatement $fees,
        private readonly ChildDocuments $documents,
    ) {
    }

    /**
     * @return list<array{type: string, id: int, student_id: int|null, title: string, subtitle: string|null, deep_link: string}>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $context = PortalContext::current();

        if ($context === null) {
            return [];
        }

        // Escaped before it reaches a LIKE. An unescaped `%` turns any query
        // into "everything", which on this surface is a disclosure, not a bug.
        $like = '%'.addcslashes($term, '%_\\').'%';

        $results = [];

        foreach ($context->validLinks() as $link) {
            $studentId = (int) $link->student_id;

            // Row 1 is the floor and the gate: no identity, no child, and
            // nothing about the child in a result either.
            if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $studentId)) {
                continue;
            }

            foreach ($this->searchChild($studentId, $term, $like, $context->guardian->phone) as $hit) {
                $results[] = $hit;
            }
        }

        foreach ($this->searchAnnouncements($like) as $hit) {
            $results[] = $hit;
        }

        return $results;
    }

    /**
     * @return list<array{type: string, id: int, student_id: int|null, title: string, subtitle: string|null, deep_link: string}>
     */
    private function searchChild(int $studentId, string $term, string $like, ?string $phone): array
    {
        $hits = [];

        $student = DB::table('students')
            ->where('id', $studentId)
            ->first(['id', 'first_name', 'last_name', 'matricule']);

        if ($student === null) {
            return [];
        }

        $childName = trim($student->first_name.' '.$student->last_name);

        if (stripos($childName, $term) !== false || stripos((string) $student->matricule, $term) !== false) {
            $hits[] = [
                'type' => 'child',
                'id' => $studentId,
                'student_id' => $studentId,
                'title' => $childName,
                'subtitle' => (string) $student->matricule,
                'deep_link' => 'opes://children/'.$studentId,
            ];
        }

        $enrollmentId = $this->fees->latestEnrollmentId($studentId);

        // Row 13, asked BEFORE the query runs.
        if ($enrollmentId !== null && $this->policy->allows(GuardianCapability::R13ViewInvoices, $studentId)) {
            $invoices = DB::table('invoices')
                ->where('enrollment_id', $enrollmentId)
                ->where('status', 'issued')
                ->where('invoice_no', 'like', $like)
                ->orderByDesc('issue_date')
                ->limit(self::MAX_PER_SOURCE)
                ->get(['id', 'invoice_no', 'issue_date']);

            foreach ($invoices as $invoice) {
                $hits[] = [
                    'type' => 'invoice',
                    'id' => (int) $invoice->id,
                    'student_id' => $studentId,
                    'title' => (string) $invoice->invoice_no,
                    'subtitle' => $childName.' - '.(string) $invoice->issue_date,
                    'deep_link' => 'opes://children/'.$studentId.'/invoices/'.(int) $invoice->id,
                ];
            }
        }

        // Row 15 is the wide grant; the row-16 floor searches only this
        // guardian's own payments, matched the same best-effort way
        // ChildFeeStatement documents.
        if ($enrollmentId !== null) {
            $canWide = $this->policy->allows(GuardianCapability::R15ViewReceipts, $studentId);
            $canOwn = $this->policy->allows(GuardianCapability::R16ViewOwnPayments, $studentId);

            if ($canWide || $canOwn) {
                $found = 0;

                foreach ($this->fees->receipts($enrollmentId, $phone ?? '') as $receipt) {
                    if ($found >= self::MAX_PER_SOURCE) {
                        break;
                    }

                    if ((! $canWide && ! $receipt->is_own) || stripos($receipt->receipt_no, $term) === false) {
                        continue;
                    }

                    $found++;
                    $hits[] = [
                        'type' => 'receipt',
                        'id' => $receipt->id,
                        'student_id' => $studentId,
                        'title' => $receipt->receipt_no,
                        'subtitle' => $childName.' - '.$receipt->value_date,
                        'deep_link' => 'opes://children/'.$studentId.'/receipts/'.$receipt->id,
                    ];
                }
            }
        }

        // Rows 22 and 23, each searched only if held - the two shelves stay
        // separate here exactly as they do everywhere else.
        if ($this->policy->allows(GuardianCapability::R22ViewSchoolIssuedDocuments, $studentId)) {
            foreach ($this->documents->schoolIssued($studentId) as $document) {
                if ($document->serial === null || stripos((string) $document->serial, $term) === false) {
                    continue;
                }

                $hits[] = [
                    'type' => 'document',
                    'id' => (int) $document->id,
                    'student_id' => $studentId,
                    'title' => (string) $document->serial,
                    'subtitle' => $childName,
                    'deep_link' => 'opes://children/'.$studentId.'/documents',
                ];
            }
        }

        if ($this->policy->allows(GuardianCapability::R23ViewGuardianSuppliedDocuments, $studentId)) {
            foreach ($this->documents->guardianSupplied($studentId) as $document) {
                if (stripos((string) $document->title, $term) === false) {
                    continue;
                }

                $hits[] = [
                    'type' => 'document',
                    'id' => (int) $document->id,
                    'student_id' => $studentId,
                    'title' => (string) $document->title,
                    'subtitle' => $childName,
                    'deep_link' => 'opes://children/'.$studentId.'/documents',
                ];
            }
        }

        // Row 19 for the list, plus `visibility = 'guardian'`. Searching the
        // NARRATIVE additionally needs row 20 - matching prose a parent may not
        // read would disclose that prose through the fact of the match.
        if ($this->policy->allows(GuardianCapability::R19ViewDisciplineList, $studentId)
            && Schema::hasTable('discipline_cases')) {
            $canNarrative = $this->policy->allows(GuardianCapability::R20ViewDisciplineNarrative, $studentId);

            $cases = DB::table('discipline_cases as c')
                ->join('discipline_categories as cat', 'cat.id', '=', 'c.discipline_category_id')
                ->where('c.student_id', $studentId)
                ->where('c.visibility', 'guardian')
                ->where(function ($query) use ($like, $canNarrative): void {
                    $query->where('cat.name', 'like', $like);

                    if ($canNarrative) {
                        $query->orWhere('c.description', 'like', $like);
                    }
                })
                ->orderByDesc('c.occurred_on')
                ->limit(self::MAX_PER_SOURCE)
                ->get(['c.id', 'c.occurred_on', 'cat.name as category_name']);

            foreach ($cases as $case) {
                $hits[] = [
                    'type' => 'discipline',
                    'id' => (int) $case->id,
                    'student_id' => $studentId,
                    'title' => (string) $case->category_name,
                    'subtitle' => $childName.' - '.(string) $case->occurred_on,
                    'deep_link' => 'opes://children/'.$studentId.'/discipline',
                ];
            }
        }

        return $hits;
    }

    /**
     * @return list<array{type: string, id: int, student_id: int|null, title: string, subtitle: string|null, deep_link: string}>
     */
    private function searchAnnouncements(string $like): array
    {
        if (! $this->policy->allowsForAnyChild(GuardianCapability::R26ViewTimetableAndAnnouncements)
            || ! Schema::hasTable('message_threads')) {
            return [];
        }

        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        $rows = DB::table('message_threads as t')
            ->join('message_thread_participants as p', 'p.message_thread_id', '=', 't.id')
            ->where('p.user_id', (int) $userId)
            ->whereNull('p.removed_at')
            ->where('t.kind', 'announcement')
            ->where('t.is_archived', false)
            ->where('t.title', 'like', $like)
            ->orderByDesc('t.last_message_at')
            ->limit(self::MAX_PER_SOURCE)
            ->get(['t.id', 't.title', 't.last_message_at']);

        return array_values($rows->map(static fn (object $row): array => [
            'type' => 'announcement',
            'id' => (int) $row->id,
            'student_id' => null,
            'title' => (string) $row->title,
            'subtitle' => $row->last_message_at === null ? null : (string) $row->last_message_at,
            'deep_link' => 'opes://announcements/'.(int) $row->id,
        ])->all());
    }
}
