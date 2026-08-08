<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\PostingRule;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * The docs/specs/02-accounting.md §11.3 worked example, saved through the
 * real SavePostingRule gate.
 *
 * @param  array<string, mixed>  $overrides
 */
function saveMomoRule(array $overrides = []): PostingRule
{
    $user = ledgerUser(Role::Accountant);
    actingAs($user);

    /** @var Journal $journal */
    $journal = Journal::query()->where('code', 'MM')->firstOrFail();

    /** @var array{code: string, event: string, journal_id: int, label_expression: string, condition_expression: string|null, priority: int, is_active: bool, effective_from: string, effective_to: string|null} $data */
    $data = array_merge([
        'code' => 'momo_fee_payment',
        'event' => PostingEvent::FeePaymentReceived->value,
        'journal_id' => $journal->id,
        'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
        'condition_expression' => null,
        'priority' => 100,
        'is_active' => true,
        'effective_from' => '2030-01-01',
        'effective_to' => null,
    ], $overrides);

    return app(SavePostingRule::class)->handle($data, [
        [
            'sequence' => 1,
            'account_source' => AccountSource::Literal,
            'account_code' => '552',
            'sign' => LineSign::Debit,
            'amount_expression' => 'payment.amount - payment.commission',
            'label_expression' => 'Encaissement MoMo réf. {payment.reference}',
        ],
        [
            'sequence' => 2,
            'account_source' => AccountSource::Literal,
            'account_code' => '6317',
            'sign' => LineSign::Debit,
            'amount_expression' => 'payment.commission',
            'label_expression' => 'Commission opérateur MoMo {payment.commission_rate_label}',
        ],
        [
            'sequence' => 3,
            'account_source' => AccountSource::Literal,
            'account_code' => '4111',
            'sign' => LineSign::Credit,
            'amount_expression' => 'payment.amount',
            'is_balancing' => true,
            'partner_source' => 'payment.partner',
            'label_expression' => '{payment.partner_label} — Facture {payment.invoice_reference}',
        ],
    ], $user->toAuditActor());
}

/**
 * @return array<string, mixed>
 */
function momoPayload(): array
{
    return [
        'payment' => [
            'amount' => 350_000,
            'commission' => 5_250,
            'net_amount' => 344_750,
            'reference' => 'MM-88421',
            'commission_rate_label' => '1,5%',
            'partner' => ['type' => 'student', 'id' => 4412],
            'partner_label' => 'Ncham Andre Bela',
            'invoice_reference' => 'INV-2026-1188',
        ],
    ];
}

function postMomo(): JournalEntry
{
    $user = ledgerUser(Role::Accountant);
    actingAs($user);
    ledgerCalendar('2031-03-15');

    return app(PostFromEvent::class)->handle(
        PostingEvent::FeePaymentReceived->value,
        momoPayload(),
        '2031-03-15',
        $user->toAuditActor(),
        'MM-88421',
    );
}

it('posts the §11.3 MoMo fee payment exactly as the spec table says', function (): void {
    saveMomoRule();
    $entry = postMomo();

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit)->toBe(350_000)  // L2
        ->and($entry->total_credit)->toBe(350_000)
        ->and($entry->piece_no)->not->toBeNull();

    $lines = $entry->lines()->orderBy('sequence')->get();
    expect($lines)->toHaveCount(3);

    $accountCode = function (JournalEntryLine $line): string {
        /** @var ChartOfAccount $account */
        $account = ChartOfAccount::query()->findOrFail($line->account_id);

        return $account->code;
    };

    /** @var JournalEntryLine $momo */
    $momo = $lines[0];
    expect($accountCode($momo))->toBe('552')
        ->and($momo->debit)->toBe(344_750)
        ->and($momo->credit)->toBe(0)
        ->and($momo->label)->toBe('Encaissement MoMo réf. MM-88421')
        ->and($momo->partner_type)->toBeNull();

    /** @var JournalEntryLine $commission */
    $commission = $lines[1];
    expect($accountCode($commission))->toBe('6317')
        ->and($commission->debit)->toBe(5_250)
        ->and($commission->credit)->toBe(0)
        ->and($commission->label)->toBe('Commission opérateur MoMo 1,5%')
        ->and($commission->partner_type)->toBeNull();

    /** @var JournalEntryLine $client */
    $client = $lines[2];
    expect($accountCode($client))->toBe('4111')
        ->and($client->debit)->toBe(0)
        ->and($client->credit)->toBe(350_000) // the balancing line: total − Σ(others)
        ->and($client->label)->toBe('Ncham Andre Bela — Facture INV-2026-1188')
        ->and($client->partner_type?->value)->toBe('student')
        ->and($client->partner_id)->toBe(4412);
});

it('stamps posting_rule_id and posting_rule_version and locks the rule', function (): void {
    $rule = saveMomoRule();
    expect($rule->is_locked)->toBeFalse();

    $entry = postMomo();

    expect($entry->posting_rule_id)->toBe($rule->id)
        ->and($entry->posting_rule_version)->toBe(1)
        ->and(assertNotNull($rule->fresh())->is_locked)->toBeTrue();
});

it('a locked rule edit creates v2 and new postings stamp the new version', function (): void {
    saveMomoRule();
    postMomo();

    // The first post locked v1; this edit must therefore create v2.
    $v2 = saveMomoRule(['priority' => 200, 'effective_from' => '2031-01-01']);
    expect($v2->version)->toBe(2);

    $entry = postMomo();
    expect($entry->posting_rule_id)->toBe($v2->id)
        ->and($entry->posting_rule_version)->toBe(2);
});

it('fails loudly when two rules tie at the top priority - it never picks one', function (): void {
    saveMomoRule();

    // A second same-priority rule slipped in past the save gate (a direct
    // write, as a bad import might do) - exactly what posting must refuse.
    PostingRule::factory()->create([
        'code' => 'momo_fee_payment_rogue',
        'event' => PostingEvent::FeePaymentReceived->value,
        'priority' => 100,
        'effective_from' => '2030-01-01',
    ]);

    postMomo();
})->throws(DomainException::class, 'Ambiguous posting rules');

it('selects by condition and priority, ignoring rules whose condition is false', function (): void {
    // Higher-priority rule that only fires when the school does NOT bear
    // the fee - false for this payload - so the lower one must win.
    saveMomoRule([
        'code' => 'momo_other_bearer',
        'priority' => 500,
        'condition_expression' => 'payment.commission == 0',
    ]);
    $expected = saveMomoRule(['code' => 'momo_fee_payment', 'priority' => 100]);

    $entry = postMomo();

    expect($entry->posting_rule_id)->toBe($expected->id);
});

it('fails loudly when no rule matches at all', function (): void {
    $user = ledgerUser(Role::Accountant);
    actingAs($user);
    ledgerCalendar('2031-03-15');

    app(PostFromEvent::class)->handle(
        PostingEvent::FeePaymentReceived->value,
        momoPayload(),
        '2031-03-15',
        $user->toAuditActor(),
    );
})->throws(DomainException::class, 'No active posting rule matches');

it('expands iterates_over into one line per collection element with partners', function (): void {
    $user = ledgerUser(Role::Accountant);
    actingAs($user);
    ledgerCalendar('2031-03-15');

    /** @var Journal $journal */
    $journal = Journal::query()->where('code', 'PA')->firstOrFail();

    // A 4111-style collective per-staff payable needs a collective account;
    // reuse 4111 (allows student partners) with student partners for the
    // shape - the point under test is the expansion, not the payroll codes.
    app(SavePostingRule::class)->handle([
        'code' => 'payroll_net_shape',
        'event' => PostingEvent::PayrollApproved->value,
        'journal_id' => $journal->id,
        'label_expression' => 'Paie {run.reference}',
        'condition_expression' => null,
        'priority' => 100,
        'is_active' => true,
        'effective_from' => '2030-01-01',
        'effective_to' => null,
    ], [
        [
            'sequence' => 1,
            'account_source' => AccountSource::Literal,
            'account_code' => '601',
            'sign' => LineSign::Debit,
            'amount_expression' => 'run.total_gross',
            'label_expression' => 'Salaires bruts {run.reference}',
        ],
        [
            'sequence' => 2,
            'account_source' => AccountSource::Literal,
            'account_code' => '4111',
            'sign' => LineSign::Credit,
            'amount_expression' => 'item.net',
            'iterates_over' => 'run.items',
            'partner_source' => 'item.staff_partner',
            'label_expression' => '{item.label}',
        ],
        [
            'sequence' => 3,
            'account_source' => AccountSource::Literal,
            'account_code' => '477',
            'sign' => LineSign::Credit,
            'amount_expression' => '0',
            'is_balancing' => true,
            'label_expression' => 'Retenues {run.reference}',
        ],
    ], $user->toAuditActor());

    $entry = app(PostFromEvent::class)->handle(
        PostingEvent::PayrollApproved->value,
        [
            'run' => [
                'total_gross' => 1_000_000,
                'total_employer_charges' => 0,
                'total_net' => 800_000,
                'reference' => 'PA-2031-03',
                'items' => [
                    ['net' => 500_000, 'staff_partner' => ['type' => 'student', 'id' => 1], 'label' => 'Net A'],
                    ['net' => 300_000, 'staff_partner' => ['type' => 'student', 'id' => 2], 'label' => 'Net B'],
                ],
                'remittances' => [],
            ],
        ],
        '2031-03-15',
        $user->toAuditActor(),
    );

    $lines = $entry->lines()->orderBy('sequence')->get();

    expect($lines)->toHaveCount(4) // 1 gross + 2 iterated nets + balancing 200 000
        ->and($entry->total_debit)->toBe(1_000_000)
        ->and($entry->total_credit)->toBe(1_000_000);

    /** @var JournalEntryLine $netA */
    $netA = $lines[1];
    /** @var JournalEntryLine $netB */
    $netB = $lines[2];
    /** @var JournalEntryLine $balancing */
    $balancing = $lines[3];

    expect($netA->credit)->toBe(500_000)
        ->and($netA->label)->toBe('Net A')
        ->and($netA->partner_id)->toBe(1)
        ->and($netB->credit)->toBe(300_000)
        ->and($netB->partner_id)->toBe(2)
        ->and($balancing->credit)->toBe(200_000); // total − Σ(others), 00-core §7.3
});
