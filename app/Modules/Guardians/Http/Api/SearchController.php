<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDocuments;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F - guardian-scoped search
 * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4 row 22 of the endpoint
 * table; build plan §8).
 *
 * The plan's instruction is one sentence and it is the whole design: "No admin
 * GlobalSearch reuse without re-scoping." Search is the single most dangerous
 * endpoint to build carelessly, because its natural implementation - one index,
 * one LIKE, filter afterwards - leaks by construction: a result count, a
 * highlighted snippet or an autocomplete suggestion discloses the existence of
 * a record even when the record itself is withheld.
 *
 * So this endpoint does not search a corpus and then filter. It walks the
 * caller's own valid links, and for each child asks the SAME policy the
 * corresponding read endpoint asks before looking at that kind of thing at
 * all. A guardian without row 13 does not get an invoice search that returns
 * nothing - they get no invoice search.
 *
 * Consequently it searches ONLY what earlier slices already ship: children,
 * invoices, payments, documents, discipline cases and announcements. A source
 * with no read endpoint has no re-scoped search either, because there would be
 * no vetted reader to route it through.
 */
final class SearchController
{
    /** Below this a query matches half the school and tells the caller nothing. */
    private const MIN_LENGTH = 2;

    private const MAX_PER_SOURCE = 10;

    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly ChildFeeStatement $fees,
        private readonly ChildDocuments $documents,
    ) {
    }

    /** `GET /v1/me/search?q=` */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        $term = trim((string) $validated['q']);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json([
                'data' => ['query' => $term, 'results' => []],
                'meta' => ['total' => 0, 'min_length' => self::MIN_LENGTH],
            ]);
        }

        // Escaped before it reaches a LIKE: `%` and `_` in user input are
        // wildcards, and an unescaped `%` turns any query into "everything".
        $like = '%'.addcslashes($term, '%_\\').'%';

        $context = $this->context();
        $results = [];

        foreach ($context->validLinks() as $link) {
            $studentId = (int) $link->student_id;

            // Row 1 is the floor and the gate: no identity, no child, nothing
            // about the child in a search result either.
            if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $studentId)) {
                continue;
            }

            foreach ($this->searchChild($studentId, $term, $like) as $hit) {
                $results[] = $hit;
            }
        }

        foreach ($this->searchAnnouncements($like) as $hit) {
            $results[] = $hit;
        }

        return response()->json([
            'data' => ['query' => $term, 'results' => $results],
            'meta' => ['total' => count($results)],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchChild(int $studentId, string $term, string $like): array
    {
        $hits = [];

        $student = DB::table('students')
            ->where('id', $studentId)
            ->first(['id', 'first_name', 'last_name', 'matricule']);

        if ($student === null) {
            return [];
        }

        $childName = trim($student->first_name.' '.$student->last_name);

        // The child themselves - row 1 already established above.
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

        // Invoices - row 13, asked BEFORE the query runs, not after.
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

        // Receipts - row 15 is the wide grant; a link holding only the row-16
        // floor searches its OWN payments, matched the same best-effort way
        // ChildFeeStatement documents, and never the unfiltered list.
        if ($enrollmentId !== null) {
            $canWide = $this->policy->allows(GuardianCapability::R15ViewReceipts, $studentId);
            $canOwn = $this->policy->allows(GuardianCapability::R16ViewOwnPayments, $studentId);

            if ($canWide || $canOwn) {
                $phone = $this->context()->guardian->phone;
                $found = 0;

                foreach ($this->fees->receipts($enrollmentId, $phone) as $receipt) {
                    if ($found >= self::MAX_PER_SOURCE) {
                        break;
                    }

                    if (! $canWide && ! $receipt->is_own) {
                        continue;
                    }

                    if (stripos($receipt->receipt_no, $term) === false) {
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

        // Documents - rows 22 and 23, each searched only if held. The two
        // shelves stay separate here exactly as they do in DocumentsController.
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

        // Discipline - row 19 for the list, and `visibility = 'guardian'` is
        // still the query conjunct the matrix does not own. Searching the
        // NARRATIVE additionally needs row 20; without it only the category
        // name is matchable, because matching prose a parent may not read
        // would disclose that prose through the fact of the match.
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
     * Announcements - row 26, granted on any valid link, and scoped by
     * PARTICIPATION for the same reason CommunicationController scopes them
     * that way: the resolved participant list is who was actually addressed.
     *
     * @return list<array<string, mixed>>
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
            'subtitle' => $row->last_message_at,
            'deep_link' => 'opes://announcements/'.(int) $row->id,
        ])->all());
    }

    private function context(): PortalContext
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        return $context;
    }
}
