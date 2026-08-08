<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\PostingRule;
use App\Modules\Accounting\Models\PostingRuleLine;
use App\Support\Expression\Expression;
use App\Support\Expression\LabelTemplate;
use App\Support\Expression\Payload;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/02-accounting.md §11.1. Resolves ONE rule against ONE payload
 * into the concrete journal-entry line set - and nothing else. No entry is
 * created here; PostFromEvent feeds the result to DraftJournalEntry.
 *
 *  - `account_source`: literal code, payload path, or settings key;
 *  - `iterates_over`: one physical line per collection element, the element
 *    exposed to expressions under the 'item.' prefix;
 *  - `skip_if_zero`: zero lines are dropped (a zero line violates L1);
 *  - `is_balancing`: computed LAST as total − Σ(others) per 00-core §7.3 -
 *    it is never an expression result, so the entry cannot fail to balance
 *    on a rounding residual.
 *
 * @phpstan-type ResolvedLine array{account_id: int, label: string, debit: int, credit: int, partner_type: string|null, partner_id: int|null, tax_code_id: int|null, due_date: string|null}
 */
final class EvaluatePostingRule
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @phpstan-return list<ResolvedLine>
     */
    public function handle(PostingRule $rule, array $payload): array
    {
        $flat = Payload::flatten($payload);

        $resolved = [];
        $balancingLine = null;

        /** @var PostingRuleLine $line */
        foreach ($rule->lines()->get() as $line) {
            if ($line->is_balancing) {
                if ($balancingLine !== null) {
                    throw new DomainException("Posting rule {$rule->code} v{$rule->version} has more than one balancing line.");
                }

                $balancingLine = $line;

                continue;
            }

            foreach ($this->expand($line, $flat) as $vars) {
                $amount = Expression::parse($line->amount_expression)->value(Payload::scalars($vars));
                $entry = $this->buildLine($line, $vars, $amount);

                if ($entry !== null) {
                    $resolved[] = $entry;
                }
            }
        }

        if ($balancingLine !== null) {
            $debits = Money::sum(array_map(static fn (array $l): Money => Money::of($l['debit']), $resolved));
            $credits = Money::sum(array_map(static fn (array $l): Money => Money::of($l['credit']), $resolved));

            // total − Σ(others), placed on the line's declared side
            // (00-core §7.3): whatever the others left unbalanced.
            $amount = $balancingLine->sign === LineSign::Debit
                ? $credits->minus($debits)->amount()
                : $debits->minus($credits)->amount();

            $entry = $this->buildLine($balancingLine, $flat, $amount);

            if ($entry !== null) {
                $resolved[] = $entry;
            }
        }

        if ($resolved === []) {
            throw new DomainException(
                "Posting rule {$rule->code} v{$rule->version} resolved to no lines for this payload; nothing to post."
            );
        }

        return $resolved;
    }

    /**
     * One variable map per physical line: the payload itself, or - when the
     * line iterates - one map per collection element with the element's
     * fields merged under 'item.'.
     *
     * @param  array<string, mixed>  $flat
     * @return list<array<string, mixed>>
     */
    private function expand(PostingRuleLine $line, array $flat): array
    {
        if ($line->iterates_over === null) {
            return [$flat];
        }

        $collection = $flat[$line->iterates_over] ?? null;

        if (! is_array($collection) || ! array_is_list($collection)) {
            throw new DomainException(
                "Payload path '{$line->iterates_over}' did not resolve to a collection for the iterating line #{$line->sequence}."
            );
        }

        $maps = [];

        foreach ($collection as $element) {
            if (! is_array($element)) {
                throw new DomainException(
                    "An element of '{$line->iterates_over}' is not a structure; the iterating line #{$line->sequence} cannot resolve it."
                );
            }

            /** @var array<string, mixed> $element */
            $maps[] = array_merge($flat, Payload::flatten($element, 'item'));
        }

        return $maps;
    }

    /**
     * @param  array<string, mixed>  $vars
     *
     * @phpstan-return ResolvedLine|null
     */
    private function buildLine(PostingRuleLine $line, array $vars, int $amount): ?array
    {
        if ($amount === 0 && $line->skip_if_zero) {
            return null;
        }

        if ($line->sign === LineSign::Signed) {
            $debit = $amount > 0 ? $amount : 0;
            $credit = $amount < 0 ? Money::of($amount)->absolute()->amount() : 0;
        } else {
            if ($amount < 0) {
                throw new DomainException(sprintf(
                    'Line #%d evaluated to a negative amount (%d) on a fixed %s side; declare the line `signed` if that is intended.',
                    $line->sequence,
                    $amount,
                    $line->sign->value,
                ));
            }

            $debit = $line->sign === LineSign::Debit ? $amount : 0;
            $credit = $line->sign === LineSign::Credit ? $amount : 0;
        }

        [$partnerType, $partnerId] = $this->resolvePartner($line, $vars);

        return [
            'account_id' => $this->resolveAccountId($line, $vars),
            'label' => LabelTemplate::render($line->label_expression, Payload::printables($vars)),
            'debit' => $debit,
            'credit' => $credit,
            'partner_type' => $partnerType,
            'partner_id' => $partnerId,
            'tax_code_id' => $this->intAt($vars, $line->tax_code_source),
            'due_date' => $this->stringAt($vars, $line->due_date_source),
        ];
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function resolveAccountId(PostingRuleLine $line, array $vars): int
    {
        if ($line->account_source === AccountSource::Literal) {
            $id = ChartOfAccount::query()->where('code', (string) $line->account_code)->value('id');

            if ($id === null) {
                throw new DomainException("No account with code '{$line->account_code}' for line #{$line->sequence}.");
            }

            return (int) $id;
        }

        if ($line->account_source === AccountSource::Setting) {
            $value = DB::table('settings')->where('key', (string) $line->account_path)->value('value');

            if ($value === null) {
                throw new DomainException(
                    "Setting '{$line->account_path}' has no configured account for line #{$line->sequence}; an accountant must assign it before this rule can post."
                );
            }

            $decoded = json_decode((string) $value, true);

            if (! is_int($decoded)) {
                throw new DomainException("Setting '{$line->account_path}' does not hold an account id.");
            }

            return $decoded;
        }

        $id = $this->intAt($vars, $line->account_path);

        if ($id === null) {
            throw new DomainException(
                "Payload path '{$line->account_path}' did not resolve to an account id for line #{$line->sequence}."
            );
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array{string|null, int|null}
     */
    private function resolvePartner(PostingRuleLine $line, array $vars): array
    {
        if ($line->partner_source === null) {
            return [null, null];
        }

        $tuple = $vars[$line->partner_source] ?? null;

        if (! is_array($tuple)
            || ! is_string($tuple['type'] ?? null)
            || ! is_int($tuple['id'] ?? null)) {
            throw new DomainException(
                "Payload path '{$line->partner_source}' did not resolve to a (type, id) partner tuple for line #{$line->sequence}."
            );
        }

        return [$tuple['type'], $tuple['id']];
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function intAt(array $vars, ?string $path): ?int
    {
        if ($path === null) {
            return null;
        }

        $value = $vars[$path] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function stringAt(array $vars, ?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $value = $vars[$path] ?? null;

        return is_string($value) ? $value : null;
    }
}
