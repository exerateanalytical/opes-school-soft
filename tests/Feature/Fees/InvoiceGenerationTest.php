<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\GenerateInvoices;
use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('invoiceUserAs')) {
    function invoiceUserAs(Role $role = Role::Bursar): User
    {
        $user = ledgerUser($role);
        \Pest\Laravel\actingAs($user);

        return $user;
    }
}

if (! function_exists('feesAccountId')) {
    function feesAccountId(string $code): int
    {
        return (int) assertNotNull(
            DB::table('chart_of_accounts')->where('code', $code)->value('id'),
            "Account {$code} is not seeded.",
        );
    }
}

if (! function_exists('feesOwnItemId')) {
    function feesOwnItemId(string $name, string $revenueCode = '706'): int
    {
        $categoryId = DB::table('fee_categories')->insertGetId([
            'code' => 'CAT'.Str::upper(Str::random(6)),
            'name' => $name.' category',
            'name_fr' => $name.' catégorie',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('fee_items')->insertGetId([
            'code' => 'ITM'.Str::upper(Str::random(6)),
            'name' => $name,
            'name_fr' => $name.' (fr)',
            'fee_category_id' => $categoryId,
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => feesAccountId($revenueCode),
            'recognition_method' => 'on_issue',
            'default_recurrence' => 'per_year',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('feesStructure')) {
    /**
     * Inserts an active fee structure with the given scope and item lines.
     *
     * @param  list<array{fee_item_id: int, amount: int}>  $lines
     * @param  array<string, mixed>  $scope
     */
    function feesStructure(int $academicYearId, int $schoolSectionId, array $lines, array $scope = []): int
    {
        $structureId = (int) DB::table('fee_structures')->insertGetId(array_merge([
            'academic_year_id' => $academicYearId,
            'school_section_id' => $schoolSectionId,
            'name' => 'Structure '.Str::upper(Str::random(5)),
            'status' => 'active',
            'effective_from' => '2030-09-01',
            'created_at' => now(),
            'updated_at' => now(),
        ], $scope));

        foreach ($lines as $order => $line) {
            DB::table('fee_structure_lines')->insert([
                'fee_structure_id' => $structureId,
                'fee_item_id' => $line['fee_item_id'],
                'amount' => $line['amount'],
                'display_order' => $order + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $structureId;
    }
}

if (! function_exists('feesFixture')) {
    /**
     * The §15.1-shaped baseline: an enrollment plus an active structure of
     * two own-revenue lines totalling 350 000, sharing the ledger calendar
     * that covers the 2031-03-15 issue date.
     *
     * @return array{enrollment: Enrollment, structure_id: int, options: array{academic_year_id: int, fiscal_year_id: int, term_id: int|null, issue_date: string, due_date: string, installment_plan_id?: int|null}}
     */
    function feesFixture(): array
    {
        $calendar = ledgerCalendar('2031-03-15');

        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $calendar['academic_year_id'],
        ]);

        $structureId = feesStructure(
            $enrollment->academic_year_id,
            $enrollment->school_section_id,
            [
                ['fee_item_id' => feesOwnItemId('Tuition Fee'), 'amount' => 200_000],
                ['fee_item_id' => feesOwnItemId('Development Fee'), 'amount' => 150_000],
            ],
        );

        return [
            'enrollment' => $enrollment,
            'structure_id' => $structureId,
            'options' => [
                'academic_year_id' => $enrollment->academic_year_id,
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'term_id' => null,
                'issue_date' => '2031-03-15',
                'due_date' => '2031-06-30',
            ],
        ];
    }
}

it('generates a draft invoice with snapshotted lines from the resolved structure', function (): void {
    $fixture = feesFixture();
    $user = invoiceUserAs();

    $result = app(GenerateInvoices::class)->forEnrollments(
        [$fixture['enrollment']->id],
        $fixture['options'],
        $user->toAuditActor(),
    );

    expect($result['created'])->toHaveCount(1)
        ->and($result['rejected'])->toBe([]);

    /** @var Invoice $invoice */
    $invoice = Invoice::query()->findOrFail($result['created'][0]);

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->invoice_no)->toBeNull() // allocated only at issue
        ->and($invoice->fee_structure_id)->toBe($fixture['structure_id'])
        ->and($invoice->fee_structure_version)->toBe(1)
        ->and($invoice->grossTotal())->toBe(350_000);

    $lines = $invoice->lines()->get()->all();
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->description)->toBe('Tuition Fee') // snapshot, not a join
        ->and($lines[0]->collection_basis)->toBe('own_revenue')
        ->and($lines[0]->revenue_account_id)->toBe(feesAccountId('706'))
        ->and($lines[1]->description)->toBe('Development Fee');

    // No plan: a single full-amount instalment carrying the header due date.
    $installments = $invoice->installments()->get()->all();
    expect($installments)->toHaveCount(1)
        ->and($installments[0]->amount)->toBe(350_000)
        ->and($installments[0]->due_date->toDateString())->toBe('2031-06-30');
});

it('is idempotent on (enrollment, structure, term) - a second run skips, never duplicates', function (): void {
    $fixture = feesFixture();
    $user = invoiceUserAs();

    $first = app(GenerateInvoices::class)->forEnrollments([$fixture['enrollment']->id], $fixture['options'], $user->toAuditActor());
    $second = app(GenerateInvoices::class)->forEnrollments([$fixture['enrollment']->id], $fixture['options'], $user->toAuditActor());

    expect($first['created'])->toHaveCount(1)
        ->and($second['created'])->toBe([])
        ->and($second['skipped'])->toBe([$fixture['enrollment']->id])
        ->and(Invoice::query()->count())->toBe(1);
});

it('splits percentage-plan instalments through Money::allocate with the LAST tranche absorbing the residual', function (): void {
    $fixture = feesFixture();
    $user = invoiceUserAs();

    // §15.2's hard case: thirds of 350 000 leave two spare francs.
    $planId = (int) DB::table('installment_plans')->insertGetId([
        'academic_year_id' => $fixture['options']['academic_year_id'],
        'name' => 'Three equal tranches',
        'basis' => 'percentage',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([1, 2, 3] as $sequence) {
        DB::table('installment_plan_lines')->insert([
            'installment_plan_id' => $planId,
            'sequence_no' => $sequence,
            'label' => "Tranche {$sequence}",
            'label_fr' => "{$sequence}e tranche",
            'percentage_bp' => 333_333,
            'due_date' => sprintf('2031-0%d-01', $sequence + 3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $options = $fixture['options'];
    $options['installment_plan_id'] = $planId;

    $result = app(GenerateInvoices::class)->forEnrollments([$fixture['enrollment']->id], $options, $user->toAuditActor());

    /** @var Invoice $invoice */
    $invoice = Invoice::query()->findOrFail($result['created'][0]);
    $amounts = $invoice->installments()->pluck('amount')->all();

    expect($amounts)->toBe([116_666, 116_667, 116_667]) // §15.2 exactly
        ->and(array_sum($amounts))->toBe(350_000);
});

it('rejects an enrollment no active structure covers, without throwing the batch', function (): void {
    $calendar = ledgerCalendar('2031-03-15');
    /** @var Enrollment $enrollment */
    $enrollment = Enrollment::factory()->create(['academic_year_id' => $calendar['academic_year_id']]);
    $user = invoiceUserAs();

    $result = app(GenerateInvoices::class)->forEnrollments([$enrollment->id], [
        'academic_year_id' => $enrollment->academic_year_id,
        'fiscal_year_id' => $calendar['fiscal_year_id'],
        'term_id' => null,
        'issue_date' => '2031-03-15',
        'due_date' => '2031-06-30',
    ], $user->toAuditActor());

    expect($result['created'])->toBe([])
        ->and($result['rejected'][$enrollment->id])->toContain('No active fee structure');
});

it('resolves the MOST SPECIFIC structure and rejects a tie as a configuration error', function (): void {
    $fixture = feesFixture();
    $enrollment = $fixture['enrollment'];
    $user = invoiceUserAs();

    // More specific competitor: boarding_scope pinned to the student's
    // status scores 2 against the fixture's 0 - it must win.
    $specificId = feesStructure(
        $enrollment->academic_year_id,
        $enrollment->school_section_id,
        [['fee_item_id' => feesOwnItemId('Day Tuition'), 'amount' => 180_000]],
        ['boarding_scope' => 'day', 'effective_from' => '2030-10-01'],
    );

    $result = app(GenerateInvoices::class)->forEnrollments([$enrollment->id], $fixture['options'], $user->toAuditActor());

    /** @var Invoice $invoice */
    $invoice = Invoice::query()->findOrFail($result['created'][0]);
    expect($invoice->fee_structure_id)->toBe($specificId)
        ->and($invoice->grossTotal())->toBe(180_000);

    // Now a second equally-specific structure: the tie is REJECTED, never
    // silently resolved (§2.5).
    /** @var Enrollment $second */
    $second = Enrollment::factory()->create([
        'academic_year_id' => $enrollment->academic_year_id,
        'school_section_id' => $enrollment->school_section_id,
        'class_level_id' => $enrollment->class_level_id,
    ]);
    feesStructure(
        $enrollment->academic_year_id,
        $enrollment->school_section_id,
        [['fee_item_id' => feesOwnItemId('Rival Day Tuition'), 'amount' => 190_000]],
        ['boarding_scope' => 'day', 'effective_from' => '2030-11-01'],
    );

    $tied = app(GenerateInvoices::class)->forEnrollments([$second->id], $fixture['options'], $user->toAuditActor());

    expect($tied['created'])->toBe([])
        ->and($tied['rejected'][$second->id])->toContain('Ambiguous');
});

it('backstops issue idempotency with the generated-column UNIQUE index on ISSUED invoices', function (): void {
    $fixture = feesFixture();
    $user = invoiceUserAs();

    $result = app(GenerateInvoices::class)->forEnrollments([$fixture['enrollment']->id], $fixture['options'], $user->toAuditActor());
    expect($result['created'])->toHaveCount(1);

    // Flip the generated draft to ISSUED - the state the constraint binds.
    DB::table('invoices')->where('id', $result['created'][0])->update(['status' => 'issued']);

    // A direct ISSUED write that bypasses the Action's pre-check - exactly
    // what the 00-core §10.3 constraint exists to stop.
    DB::table('invoices')->insert([
        'enrollment_id' => $fixture['enrollment']->id,
        'student_id' => $fixture['enrollment']->student_id,
        'academic_year_id' => $fixture['options']['academic_year_id'],
        'fiscal_year_id' => $fixture['options']['fiscal_year_id'],
        'fee_structure_id' => $fixture['structure_id'],
        'type' => 'standard',
        'issue_date' => '2031-03-15',
        'due_date' => '2031-06-30',
        'currency' => 'XAF',
        'status' => 'issued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(Illuminate\Database\QueryException::class);

it('does not bind the idempotency UNIQUE to a CANCELLED invoice - regeneration is possible', function (): void {
    $fixture = feesFixture();
    $user = invoiceUserAs();

    $result = app(GenerateInvoices::class)->forEnrollments([$fixture['enrollment']->id], $fixture['options'], $user->toAuditActor());
    expect($result['created'])->toHaveCount(1);

    // Cancel the first invoice: the StatementTest-shaped duplicate that used
    // to raise SQLSTATE 23000 on uq_invoices_issue_idem.
    DB::table('invoices')->where('id', $result['created'][0])->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    $second = app(GenerateInvoices::class)->forEnrollments([$fixture['enrollment']->id], $fixture['options'], $user->toAuditActor());

    expect($second['created'])->toHaveCount(1)
        ->and($second['skipped'])->toBe([])
        ->and(Invoice::query()->count())->toBe(2);
});
