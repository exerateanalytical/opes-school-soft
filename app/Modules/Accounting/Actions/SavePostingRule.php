<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\PostingRule;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Expression\Expression;
use App\Support\Expression\LabelTemplate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use ValueError;

/**
 * docs/specs/02-accounting.md §11.1. Creates or edits a PostingRule with all
 * of the save-time gates the spec demands:
 *
 *  - every expression (condition, amounts, labels) is PARSED HERE against
 *    the event's declared payload schema - an unknown variable, a stray
 *    function call or a malformed token rejects the save with the offending
 *    name and position; nothing unparseable can ever reach posting time;
 *  - at most one `is_balancing` line;
 *  - the ambiguity check (ValidatePostingRules) runs INSIDE the save
 *    transaction - a configuration that could tie at posting time is
 *    rejected at save;
 *  - immutable versioning: editing a rule whose `is_locked` is true creates
 *    `version + 1` and closes the predecessor's `effective_to` at the new
 *    version's `effective_from` (exclusive); a locked row is never mutated.
 *
 * @phpstan-type RuleData array{code: string, event: string, journal_id: int, label_expression: string, condition_expression?: string|null, priority?: int, is_active?: bool, effective_from: string, effective_to?: string|null}
 * @phpstan-type LineData array{sequence: int, account_source: AccountSource, account_code?: string|null, account_path?: string|null, sign: LineSign, amount_expression: string, is_balancing?: bool, partner_source?: string|null, analytic_source?: string|null, tax_code_source?: string|null, due_date_source?: string|null, iterates_over?: string|null, label_expression: string, skip_if_zero?: bool}
 */
final class SavePostingRule
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly ValidatePostingRules $validator,
    ) {}

    /**
     * @phpstan-param RuleData $data
     * @phpstan-param list<LineData> $lines
     */
    public function handle(array $data, array $lines, Actor $actor): PostingRule
    {
        Gate::authorize(Permission::LedgerConfigure->value);

        try {
            $event = PostingEvent::from($data['event']);
        } catch (ValueError) {
            throw new DomainException("Unknown posting event '{$data['event']}'; it is not in the §11.2 catalogue.");
        }

        $this->validateExpressions($event, $data, $lines);

        return DB::transaction(function () use ($event, $data, $lines, $actor): PostingRule {
            /** @var PostingRule|null $latest */
            $latest = PostingRule::query()
                ->where('code', $data['code'])
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $attributes = [
                'code' => $data['code'],
                'event' => $event->value,
                'journal_id' => $data['journal_id'],
                'label_expression' => $data['label_expression'],
                'condition_expression' => $data['condition_expression'] ?? null,
                'priority' => $data['priority'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
            ];

            if ($latest === null) {
                $rule = PostingRule::query()->create($attributes + [
                    'version' => 1,
                    'is_locked' => false,
                    'created_by' => $actor->id,
                ]);
                $action = AuditAction::Created;
            } elseif (! $latest->is_locked) {
                $latest->fill($attributes)->save();
                $latest->lines()->delete();
                $rule = $latest;
                $action = AuditAction::Updated;
            } else {
                // Immutable versioning: the locked predecessor is closed at
                // the successor's effective_from (exclusive), never mutated
                // beyond that closing date.
                $rule = PostingRule::query()->create($attributes + [
                    'version' => $latest->version + 1,
                    'is_locked' => false,
                    'created_by' => $actor->id,
                ]);
                $latest->forceFill(['effective_to' => $data['effective_from']])->save();
                $action = AuditAction::Created;
            }

            foreach ($lines as $line) {
                $rule->lines()->create([
                    'sequence' => $line['sequence'],
                    'account_source' => $line['account_source'],
                    'account_code' => $line['account_code'] ?? null,
                    'account_path' => $line['account_path'] ?? null,
                    'sign' => $line['sign'],
                    'amount_expression' => $line['amount_expression'],
                    'is_balancing' => $line['is_balancing'] ?? false,
                    'partner_source' => $line['partner_source'] ?? null,
                    'analytic_source' => $line['analytic_source'] ?? null,
                    'tax_code_source' => $line['tax_code_source'] ?? null,
                    'due_date_source' => $line['due_date_source'] ?? null,
                    'iterates_over' => $line['iterates_over'] ?? null,
                    'label_expression' => $line['label_expression'],
                    'skip_if_zero' => $line['skip_if_zero'] ?? true,
                ]);
            }

            // The ambiguity check runs inside the transaction so an
            // ambiguous configuration is rejected AT SAVE, rolling back the
            // insert - not discovered at posting time (§11.1).
            $conflicts = $this->validator->handle($event->value);

            foreach ($conflicts as $conflict) {
                if (in_array($rule->id, $conflict['rule_ids'], true)) {
                    throw new DomainException(sprintf(
                        "Ambiguous posting rules for event '%s' at priority %d: %s share overlapping effective ranges; posting could tie.",
                        $conflict['event'],
                        $conflict['priority'],
                        implode(', ', $conflict['codes']),
                    ));
                }
            }

            $this->audit->handle(
                action: $action,
                module: 'Accounting',
                auditableType: PostingRule::class,
                auditableId: (int) $rule->getKey(),
                after: [
                    'code' => $rule->code,
                    'version' => $rule->version,
                    'event' => $rule->event,
                    'priority' => $rule->priority,
                    'line_count' => count($lines),
                ],
                actor: $actor,
            );

            return $rule->fresh(['lines']) ?? $rule;
        });
    }

    /**
     * @phpstan-param RuleData $data
     * @phpstan-param list<LineData> $lines
     */
    private function validateExpressions(PostingEvent $event, array $data, array $lines): void
    {
        if ($lines === []) {
            throw new DomainException('A posting rule needs at least one line.');
        }

        LabelTemplate::validate($data['label_expression'], $event->labelVariables());

        $condition = $data['condition_expression'] ?? null;

        if ($condition !== null) {
            Expression::parse($condition, $event->expressionVariables());
        }

        $balancing = 0;

        foreach ($lines as $line) {
            $iterates = $line['iterates_over'] ?? null;

            if ($iterates !== null && ! in_array($iterates, $event->listPaths(), true)) {
                throw new DomainException(sprintf(
                    "Line %d iterates over '%s', which is not a collection in the '%s' payload schema.",
                    $line['sequence'],
                    $iterates,
                    $event->value,
                ));
            }

            Expression::parse($line['amount_expression'], $event->expressionVariables($iterates));
            LabelTemplate::validate($line['label_expression'], $event->labelVariables($iterates));

            if ($line['is_balancing'] ?? false) {
                $balancing++;

                if ($line['sign'] === LineSign::Signed) {
                    throw new DomainException(sprintf(
                        'Line %d: a balancing line must state its side (debit or credit), not `signed`.',
                        $line['sequence'],
                    ));
                }
            }

            $source = $line['account_source'];

            if ($source === AccountSource::Literal) {
                $code = $line['account_code'] ?? null;

                if ($code === null || $code === '') {
                    throw new DomainException("Line {$line['sequence']}: account_source `literal` requires account_code.");
                }

                $exists = ChartOfAccount::query()
                    ->where('code', $code)
                    ->where('is_postable', true)
                    ->exists();

                if (! $exists) {
                    throw new DomainException("Line {$line['sequence']}: no postable account with code '{$code}'.");
                }
            } else {
                $path = $line['account_path'] ?? null;

                if ($path === null || $path === '') {
                    throw new DomainException("Line {$line['sequence']}: account_source `{$source->value}` requires account_path.");
                }

                if ($source === AccountSource::PayloadPath
                    && ! in_array($path, $event->accountPaths($iterates), true)) {
                    throw new DomainException(sprintf(
                        "Line %d: '%s' is not an account path in the '%s' payload schema.",
                        $line['sequence'],
                        $path,
                        $event->value,
                    ));
                }
            }

            $partner = $line['partner_source'] ?? null;

            if ($partner !== null && ! in_array($partner, $event->partnerPaths($iterates), true)) {
                throw new DomainException(sprintf(
                    "Line %d: '%s' is not a partner path in the '%s' payload schema.",
                    $line['sequence'],
                    $partner,
                    $event->value,
                ));
            }
        }

        if ($balancing > 1) {
            throw new DomainException('At most one line per rule may be `is_balancing` (§11.1).');
        }
    }
}
