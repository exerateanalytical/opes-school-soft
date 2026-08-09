<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Models\User;
use App\Modules\Library\Actions\AddBookCopies;
use App\Modules\Library\Actions\EnrollLibraryMember;
use App\Modules\Library\Actions\IssueBook;
use App\Modules\Library\Actions\RegisterBook;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\Book;
use App\Modules\Library\Models\BookCategory;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\MembershipClass;
use App\Modules\Library\Models\ShelfLocation;
use App\Modules\Students\Models\Enrollment;
use App\Support\Audit\Actor;
use Database\Factories\JournalEntryFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 9 F4 library suites. Prefix phase9Lib,
 * every helper function_exists-guarded (00-core test discipline; names
 * must never collide with another agent's).
 */
if (! function_exists('phase9LibUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function phase9LibUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('phase9LibActor')) {
    function phase9LibActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('phase9LibLibrarian')) {
    /**
     * The full-powered desk user most flows need: catalogue + circulation
     * + fine levy (FeeCollect drives the Fees door; LedgerPost is demanded
     * by the posting engine when the fine invoice posts).
     */
    function phase9LibLibrarian(): User
    {
        return phase9LibUser(
            LibraryPermission::MANAGE,
            LibraryPermission::CIRCULATE,
            Permission::FeeCollect->value,
            Permission::LedgerPost->value,
        );
    }
}

if (! function_exists('phase9LibAccountId')) {
    function phase9LibAccountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }
}

if (! function_exists('phase9LibCalendar')) {
    /**
     * Fiscal + academic year with an open period covering the date.
     *
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    function phase9LibCalendar(string $date = '2031-03-15'): array
    {
        return (new JournalEntryFactory)->buildCalendar(Carbon::parse($date));
    }
}

if (! function_exists('phase9LibCatalog')) {
    /**
     * A registered title with N available copies, through the REAL doors.
     *
     * @param  array<string, mixed>  $bookOverrides
     * @return array{book: Book, copies: list<\App\Modules\Library\Models\BookCopy>, shelf: ShelfLocation, category: BookCategory}
     */
    function phase9LibCatalog(User $user, int $copies = 1, array $bookOverrides = []): array
    {
        $category = BookCategory::factory()->create();
        $shelf = ShelfLocation::factory()->create();

        $book = app(RegisterBook::class)->handle(null, [
            'isbn' => '979'.fake()->unique()->numerify('##########'),
            'title' => 'Physics for Colleges '.Str::upper(Str::random(6)),
            'author' => 'A. N. Whitehead',
            'book_category_id' => (int) $category->getKey(),
            'replacement_cost' => 6_000,
            ...$bookOverrides,
        ], phase9LibActor($user));

        $copyRows = $copies < 1 ? [] : app(AddBookCopies::class)->handle([
            'book_id' => (int) $book->getKey(),
            'shelf_location_id' => (int) $shelf->getKey(),
            'count' => $copies,
            'unit_cost' => 4_500,
        ], phase9LibActor($user));

        return ['book' => $book, 'copies' => $copyRows, 'shelf' => $shelf, 'category' => $category];
    }
}

if (! function_exists('phase9LibMembershipClass')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function phase9LibMembershipClass(array $overrides = []): MembershipClass
    {
        return MembershipClass::factory()->create($overrides);
    }
}

if (! function_exists('phase9LibEnrollment')) {
    /** An ACTIVE enrollment inside the given academic year. */
    function phase9LibEnrollment(int $academicYearId): Enrollment
    {
        return Enrollment::factory()->create(['academic_year_id' => $academicYearId]);
    }
}

if (! function_exists('phase9LibStudentMember')) {
    /**
     * A student member through the REAL EnrollLibraryMember door.
     *
     * @param  array<string, mixed>  $overrides
     */
    function phase9LibStudentMember(
        User $user,
        int $academicYearId,
        MembershipClass $class,
        ?Enrollment $enrollment = null,
        array $overrides = [],
    ): LibraryMember {
        $enrollment ??= phase9LibEnrollment($academicYearId);

        return app(EnrollLibraryMember::class)->handle([
            'member_type' => 'student',
            'membership_class_id' => (int) $class->getKey(),
            'academic_year_id' => $academicYearId,
            'joined_on' => '2031-01-10',
            'enrollment_id' => (int) $enrollment->getKey(),
            ...$overrides,
        ], phase9LibActor($user));
    }
}

if (! function_exists('phase9LibStaffMember')) {
    /**
     * A staff member through the REAL door.
     */
    function phase9LibStaffMember(User $user, int $academicYearId, MembershipClass $class): LibraryMember
    {
        $staffId = (int) \App\Modules\HR\Models\StaffMember::factory()->create()->getKey();

        return app(EnrollLibraryMember::class)->handle([
            'member_type' => 'staff',
            'membership_class_id' => (int) $class->getKey(),
            'academic_year_id' => $academicYearId,
            'joined_on' => '2031-01-10',
            'staff_member_id' => $staffId,
        ], phase9LibActor($user));
    }
}

if (! function_exists('phase9LibIssue')) {
    /** An open issue through the REAL IssueBook door. */
    function phase9LibIssue(User $user, int $copyId, int $memberId, string $issuedOn = '2031-03-02'): LibraryIssue
    {
        return app(IssueBook::class)->handle([
            'book_copy_id' => $copyId,
            'library_member_id' => $memberId,
            'issued_on' => $issuedOn,
        ], phase9LibActor($user));
    }
}

if (! function_exists('phase9LibPostingRules')) {
    /**
     * The Fees-side rules the fine flows post through - the SAME shape the
     * Fees suites configure (§10.7: a levied student fine IS an invoice;
     * a waiver of an invoiced fine IS a credit note).
     */
    function phase9LibPostingRules(): void
    {
        $accountant = phase9LibUser(Permission::LedgerConfigure->value);
        $journal = Journal::factory()->create();
        $save = app(SavePostingRule::class);

        $save->handle([
            'code' => 'p9f4_invoice_issued',
            'event' => PostingEvent::FeeInvoiceIssued->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Facture {invoice.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'invoice.receivable_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'invoice.total',
                'partner_source' => 'invoice.partner',
                'label_expression' => 'Client — {invoice.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.revenue_account_id',
                'iterates_over' => 'invoice.lines',
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
        ], phase9LibActor($accountant));

        $save->handle([
            'code' => 'p9f4_credit_note_issued',
            'event' => PostingEvent::FeeCreditNoteIssued->value,
            'journal_id' => $journal->id,
            'label_expression' => 'Avoir {invoice.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.revenue_account_id',
                'iterates_over' => 'invoice.lines',
                'sign' => LineSign::Debit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'invoice.receivable_account_id',
                'sign' => LineSign::Credit,
                'amount_expression' => 'invoice.total',
                'partner_source' => 'invoice.partner',
                'label_expression' => 'Client — {invoice.reference}',
            ],
        ], phase9LibActor($accountant));
    }
}

if (! function_exists('phase9LibFeeItemId')) {
    /**
     * The accountant-configured own-revenue FeeItem the fine levy targets
     * (§10.7). 706 stands in for the V14 income account - which account it
     * is remains the accountant's data, exactly the point.
     */
    function phase9LibFeeItemId(): int
    {
        $categoryId = DB::table('fee_categories')->insertGetId([
            'code' => 'LIB'.Str::upper(Str::random(6)),
            'name' => 'Library charges',
            'name_fr' => 'Frais de bibliothèque',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('fee_items')->insertGetId([
            'code' => 'LIBFINE'.Str::upper(Str::random(4)),
            'name' => 'Library fine',
            'name_fr' => 'Amende de bibliothèque',
            'fee_category_id' => $categoryId,
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => phase9LibAccountId('706'),
            'recognition_method' => 'on_issue',
            'default_recurrence' => 'one_off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('phase9LibEntryLines')) {
    /**
     * Journal-entry lines joined to account codes.
     *
     * @return list<object{code: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}>
     */
    function phase9LibEntryLines(int $journalEntryId): array
    {
        /** @var list<object{code: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null}> $rows */
        $rows = DB::table('journal_entry_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entry_lines.journal_entry_id', $journalEntryId)
            ->orderBy('journal_entry_lines.id')
            ->get([
                'chart_of_accounts.code',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entry_lines.partner_type',
                'journal_entry_lines.partner_id',
            ])
            ->map(static fn (object $row): object => (object) [
                'code' => (string) $row->code,
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
                'partner_type' => $row->partner_type === null ? null : (string) $row->partner_type,
                'partner_id' => $row->partner_id === null ? null : (int) $row->partner_id,
            ])
            ->values()
            ->all();

        return $rows;
    }
}
