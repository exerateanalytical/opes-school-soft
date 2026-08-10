<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Actions\ValidatePostingRules;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PostingRule;
use App\Modules\Identity\Domain\Role;
use App\Support\Expression\ExpressionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AccountingTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array{code: string, event: string, journal_id: int, label_expression: string, condition_expression: string|null, priority: int, is_active: bool, effective_from: string, effective_to: string|null}
 */
function ruleData(array $overrides = []): array
{
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

    return $data;
}

/**
 * @return list<array{sequence: int, account_source: AccountSource, account_code?: string|null, account_path?: string|null, sign: LineSign, amount_expression: string, is_balancing?: bool, partner_source?: string|null, analytic_source?: string|null, tax_code_source?: string|null, due_date_source?: string|null, iterates_over?: string|null, label_expression: string, skip_if_zero?: bool}>
 */
function momoLines(): array
{
    return [
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
    ];
}

/**
 * @param  array<string, mixed>  $data
 * @param  list<array{sequence: int, account_source: AccountSource, account_code?: string|null, account_path?: string|null, sign: LineSign, amount_expression: string, is_balancing?: bool, partner_source?: string|null, analytic_source?: string|null, tax_code_source?: string|null, due_date_source?: string|null, iterates_over?: string|null, label_expression: string, skip_if_zero?: bool}>|null  $lines
 */
function saveRule(array $data = [], ?array $lines = null): PostingRule
{
    $user = ledgerUser(Role::Accountant);
    actingAs($user);

    return app(SavePostingRule::class)->handle(
        ruleData($data),
        $lines ?? momoLines(),
        $user->toAuditActor(),
    );
}

it('saves a valid rule with its lines', function (): void {
    $rule = saveRule();

    expect($rule->version)->toBe(1)
        ->and($rule->is_locked)->toBeFalse()
        ->and($rule->lines)->toHaveCount(3);
});

it('requires the ledger.configure permission', function (): void {
    $user = ledgerUser(Role::Bursar);
    actingAs($user);

    app(SavePostingRule::class)->handle(ruleData(), momoLines(), $user->toAuditActor());
})->throws(AuthorizationException::class);

it('rejects an unknown variable at save time, carrying the variable name', function (): void {
    saveRule([], [[
        'sequence' => 1,
        'account_source' => AccountSource::Literal,
        'account_code' => '552',
        'sign' => LineSign::Debit,
        'amount_expression' => 'payment.amount - payment.nonexistent_field',
        'label_expression' => 'x',
    ]]);
})->throws(ExpressionException::class, "Unknown variable 'payment.nonexistent_field'");

it('rejects an injection attempt in an amount expression at save time', function (): void {
    saveRule([], [[
        'sequence' => 1,
        'account_source' => AccountSource::Literal,
        'account_code' => '552',
        'sign' => LineSign::Debit,
        'amount_expression' => 'system(1)',
        'label_expression' => 'x',
    ]]);
})->throws(ExpressionException::class, "Function 'system' is not allowed");

it('rejects a condition referencing a variable outside the event schema', function (): void {
    saveRule(['condition_expression' => 'secret.balance > 0']);
})->throws(ExpressionException::class, "Unknown variable 'secret.balance'");

it('rejects an unknown posting event', function (): void {
    saveRule(['event' => 'made.up.event']);
})->throws(DomainException::class, "Unknown posting event 'made.up.event'");

it('rejects more than one balancing line', function (): void {
    $lines = momoLines();
    $lines[1] = array_merge($lines[1], ['is_balancing' => true]);

    saveRule([], $lines);
})->throws(DomainException::class, 'At most one line per rule may be `is_balancing`');

it('rejects a literal line whose account code is not postable', function (): void {
    $lines = momoLines();
    $lines[0] = array_merge($lines[0], ['account_code' => '99999999']);

    saveRule([], $lines);
})->throws(DomainException::class, "no postable account with code '99999999'");

it('rejects iterates_over outside the declared payload collections', function (): void {
    $lines = momoLines();
    $lines[0] = array_merge($lines[0], ['iterates_over' => 'payment.amount']);

    saveRule([], $lines);
})->throws(DomainException::class, 'not a collection');

it('rejects a partner_source outside the declared partner paths', function (): void {
    $lines = momoLines();
    $lines[2] = array_merge($lines[2], ['partner_source' => 'payment.reference']);

    saveRule([], $lines);
})->throws(DomainException::class, 'not a partner path');

it('rejects an ambiguous overlap at save, not at posting time', function (): void {
    saveRule();

    saveRule(['code' => 'momo_fee_payment_bis']);
})->throws(DomainException::class, 'Ambiguous posting rules');

it('accepts same-priority rules whose effective ranges do not overlap', function (): void {
    saveRule(['effective_to' => '2031-01-01']);
    $second = saveRule(['code' => 'momo_fee_payment_bis', 'effective_from' => '2031-01-01']);

    expect($second->exists)->toBeTrue()
        ->and(app(ValidatePostingRules::class)->handle())->toBe([]);
});

it('reports ambiguous overlaps standalone for the nightly check', function (): void {
    // Two overlapping same-priority rules written directly (bypassing the
    // save gate, as a bad import might) are what the nightly run must find.
    $a = PostingRule::factory()->create(['priority' => 10]);
    $b = PostingRule::factory()->create(['priority' => 10]);

    $conflicts = app(ValidatePostingRules::class)->handle();

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['rule_ids'])->toContain($a->id, $b->id);
});

it('mutates an unlocked rule in place on edit', function (): void {
    $rule = saveRule();
    $edited = saveRule(['priority' => 200]);

    // Scoped to this rule's own code, NOT a global count: migrations now
    // seed real posting rules of their own (year-end appropriation, treasury
    // statement charges), so a global count measures the seed set rather
    // than the behaviour under test. What matters here is that editing
    // mutated the existing row instead of adding a second one.
    expect($edited->id)->toBe($rule->id)
        ->and($edited->version)->toBe(1)
        ->and($edited->priority)->toBe(200)
        ->and(PostingRule::query()->where('code', 'momo_fee_payment')->count())->toBe(1);
});

it('creates version+1 and closes the predecessor once the rule is locked', function (): void {
    $rule = saveRule();
    $rule->forceFill(['is_locked' => true])->save();

    $v2 = saveRule(['priority' => 200, 'effective_from' => '2031-06-01']);

    expect($v2->id)->not->toBe($rule->id)
        ->and($v2->version)->toBe(2)
        ->and($v2->is_locked)->toBeFalse();

    $v1 = $rule->fresh();
    expect(assertNotNull($v1)->priority)->toBe(100) // the locked row was not mutated
        ->and(assertNotNull(assertNotNull($v1)->effective_to)->toDateString())->toBe('2031-06-01');
});

it('declares a payload schema for every event in the catalogue', function (): void {
    foreach (PostingEvent::cases() as $event) {
        // payloadSchema() is an exhaustive match with no default arm - a
        // case added without a schema throws UnhandledMatchError here.
        expect($event->payloadSchema())->not->toBeEmpty();
        expect($event->expressionVariables())->not->toBeEmpty();
    }
});
