<?php

declare(strict_types=1);

namespace App\Modules\Library\Livewire;

use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Library member detail page at /library/members/{member}, gated
 * `library.view`, modeled on Students\Livewire\Students\Show and
 * Assets\Livewire\Show: identity/membership-class summary, currently active
 * issues, full circulation history, outstanding fines, and a printable
 * library card.
 *
 * Same-module DB::table reads only (ModuleBoundaryTest); the LibraryMember
 * model itself is the one Eloquent read (own module, so allowed), everything
 * else is a bounded query builder read - no unbounded collection reaches the
 * view (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class MemberShow extends Component
{
    /** Cap per history list. */
    private const int HISTORY_LIMIT = 100;

    public LibraryMember $member;

    public function mount(LibraryMember $member): void
    {
        Gate::authorize(LibraryPermission::VIEW);

        $this->member = $member;
    }

    private function className(): string
    {
        $name = DB::table('membership_classes')->where('id', $this->member->membership_class_id)->value('name');

        return is_string($name) ? $name : '—';
    }

    private function displayName(): string
    {
        if ($this->member->external_name !== null) {
            return $this->member->external_name;
        }

        if ($this->member->student_id !== null) {
            $name = DB::table('students')->where('id', $this->member->student_id)->value('first_name');
            $last = DB::table('students')->where('id', $this->member->student_id)->value('last_name');

            $full = trim(((string) ($name ?? '')).' '.((string) ($last ?? '')));

            return $full !== '' ? $full : '—';
        }

        if ($this->member->staff_member_id !== null) {
            $name = DB::table('staff_members')->where('id', $this->member->staff_member_id)->value('first_name');
            $last = DB::table('staff_members')->where('id', $this->member->staff_member_id)->value('last_name');

            $full = trim(((string) ($name ?? '')).' '.((string) ($last ?? '')));

            return $full !== '' ? $full : '—';
        }

        return '—';
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function activeIssues(): Collection
    {
        return DB::table('library_issues as li')
            ->join('book_copies as bcp', 'bcp.id', '=', 'li.book_copy_id')
            ->join('books as b', 'b.id', '=', 'bcp.book_id')
            ->where('li.library_member_id', $this->member->getKey())
            ->whereIn('li.status', ['open', 'overdue'])
            ->orderBy('li.due_on')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'li.id', 'li.issue_no', 'b.title as book_title', 'bcp.accession_no',
                'li.issued_on', 'li.due_on', 'li.status', 'li.renewal_count',
            ])
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function circulationHistory(): Collection
    {
        return DB::table('library_issues as li')
            ->join('book_copies as bcp', 'bcp.id', '=', 'li.book_copy_id')
            ->join('books as b', 'b.id', '=', 'bcp.book_id')
            ->where('li.library_member_id', $this->member->getKey())
            ->orderByDesc('li.issued_on')
            ->orderByDesc('li.id')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'li.id', 'li.issue_no', 'b.title as book_title', 'bcp.accession_no',
                'li.issued_on', 'li.due_on', 'li.returned_on', 'li.status',
            ])
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function fines(): Collection
    {
        return DB::table('library_fines')
            ->where('library_member_id', $this->member->getKey())
            ->orderByDesc('assessed_on')
            ->limit(self::HISTORY_LIMIT)
            ->select(['id', 'fine_no', 'fine_type', 'assessed_on', 'amount', 'waived_amount', 'status'])
            ->get();
    }

    private function outstandingFinesTotal(): int
    {
        return (int) DB::table('library_fines')
            ->where('library_member_id', $this->member->getKey())
            ->whereIn('status', ['assessed', 'invoiced'])
            ->sum(DB::raw('amount - waived_amount'));
    }

    private function activeIssuesCount(): int
    {
        return (int) DB::table('library_issues')
            ->where('library_member_id', $this->member->getKey())
            ->whereIn('status', ['open', 'overdue'])
            ->count();
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function exportLibraryCardPdf(): Response
    {
        Gate::authorize(LibraryPermission::VIEW);

        return PdfExport::download(
            'Library Card — '.$this->member->member_no,
            ['Field', 'Value'],
            $this->libraryCardRows(),
            'library-card-'.$this->member->member_no.'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function libraryCardRows(): iterable
    {
        yield ['Member No.', $this->member->member_no];
        yield ['Name', $this->displayName()];
        yield ['Membership Class', $this->className()];
        yield ['Member Type', ucfirst($this->member->member_type->value)];
        yield ['Joined On', $this->member->joined_on];
        yield ['Expires On', $this->member->expires_on ?? '—'];
    }

    public function render(): mixed
    {
        return view('livewire.library.member-show', [
            'className' => $this->className(),
            'displayName' => $this->displayName(),
            'activeIssues' => $this->activeIssues(),
            'circulationHistory' => $this->circulationHistory(),
            'fines' => $this->fines(),
            'outstandingFinesTotal' => $this->outstandingFinesTotal(),
            'activeIssuesCount' => $this->activeIssuesCount(),
        ]);
    }
}
