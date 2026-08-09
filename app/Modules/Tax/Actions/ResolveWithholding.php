<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Domain\WithholdingBase;
use App\Modules\Tax\Domain\WithholdingResolution;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Money\Money;
use DomainException;

/**
 * docs/specs/03-tax-procurement.md §6.4 - the withholding resolution
 * algorithm, batch over a document's lines.
 *
 * The supplier crosses the module boundary as a plain attribute array
 * (Supplier is Procurement's model; 00-core §6.2 forbids importing it
 * here) - Procurement's invoice/payment Actions pass the fields the §6.2
 * supplier_condition vocabulary evaluates: regime_fiscal,
 * has_contributor_card, niu_status, supplier_type, country, plus the
 * exemption trio.
 *
 * The DATE the caller passes must already be the recognition date -
 * invoice date under on_invoice, payment date under on_payment (§6.3);
 * that switch lives in TaxSettings and is applied by the caller, so this
 * engine has exactly one selection path.
 *
 * Refusal discipline (§6.1/§11.16): an EMPTY confirmed-rule table is a
 * configuration error, loudly - "configure withholding rules with your
 * accountant" - never a silent zero withheld. A non-empty table where no
 * rule matches a line yields reason 'unresolved', which flags the invoice
 * and blocks approval without the waive permission.
 */
final class ResolveWithholding
{
    /**
     * @param  array{is_withholding_exempt?:bool,withholding_exemption_ref?:string|null,withholding_exemption_expires_on?:string|null,regime_fiscal?:string|null,has_contributor_card?:bool|null,niu_status?:string|null,supplier_type?:string|null,country?:string|null}  $supplier
     * @param  list<array{amount_ht:int,amount_ttc:int,nature:string}>  $lines
     * @return list<WithholdingResolution> one per line, same order
     */
    public function handle(array $supplier, array $lines, string $date): array
    {
        // §6.4 step 1 - an unexpired supplier exemption ends resolution.
        if (($supplier['is_withholding_exempt'] ?? false) === true) {
            $expiresOn = $supplier['withholding_exemption_expires_on'] ?? null;

            if ($expiresOn === null || (string) $expiresOn >= $date) {
                $ref = $supplier['withholding_exemption_ref'] ?? null;

                return array_map(
                    static fn (array $line): WithholdingResolution => new WithholdingResolution(
                        ruleId: null,
                        baseAmount: 0,
                        rateBpApplied: 0,
                        withheldAmount: 0,
                        reason: WithholdingResolution::REASON_EXEMPT_SUPPLIER,
                        exemptionRef: is_string($ref) ? $ref : null,
                    ),
                    $lines,
                );
            }
            // Expired exemption: fall through - the supplier IS withheld
            // from (§11 test obligation 7).
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, WithholdingRule> $rules */
        $rules = WithholdingRule::query()
            ->where('is_active', true)
            ->whereNotNull('confirmed_at')
            ->effectiveOn($date)
            ->get();

        if ($rules->isEmpty()) {
            throw new DomainException(
                'No confirmed withholding rule is in force on '.$date.' - configure withholding rules '
                .'with your accountant before recording supplier documents (03-tax-procurement §6.1). '
                .'Not withholding must be a deliberate, recorded act, never the default.'
            );
        }

        return array_map(
            fn (array $line): WithholdingResolution => $this->resolveLine($rules, $supplier, $line, $date),
            $lines,
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, WithholdingRule>  $rules
     * @param  array<string, mixed>  $supplier
     * @param  array{amount_ht:int,amount_ttc:int,nature:string}  $line
     */
    private function resolveLine($rules, array $supplier, array $line, string $date): WithholdingResolution
    {
        // §6.4 step 2 - nature + supplier_condition filter.
        $matching = $rules
            ->filter(fn (WithholdingRule $rule): bool => $rule->appliesToNature($line['nature'])
                && $this->supplierConditionMatches($rule, $supplier))
            ->values();

        if ($matching->isEmpty()) {
            // §6.4 step 7 - silence is not an answer: flag, never zero out.
            return new WithholdingResolution(
                ruleId: null,
                baseAmount: 0,
                rateBpApplied: 0,
                withheldAmount: 0,
                reason: WithholdingResolution::REASON_UNRESOLVED,
            );
        }

        // §6.4 step 3 - highest priority wins; a tie is a configuration
        // error, raised defensively here even though save-time rejects it.
        $topPriority = (int) $matching->max('priority');
        $top = $matching
            ->filter(static fn (WithholdingRule $rule): bool => $rule->priority === $topPriority)
            ->values();

        if ($top->count() > 1) {
            throw new DomainException(sprintf(
                'Withholding rules %s tie at top priority %d on %s - a configuration error; exactly one must win (§6.4).',
                $top->map(static fn (WithholdingRule $rule): string => $rule->code)->implode(', '),
                $topPriority,
                $date,
            ));
        }

        /** @var WithholdingRule $rule */
        $rule = $top->first();

        // §6.4 step 4 - minimum base gate (threshold NEEDS VERIFICATION,
        // ships 0 = no threshold).
        if ($line['amount_ht'] < $rule->minimum_base) {
            return new WithholdingResolution(
                ruleId: (int) $rule->getKey(),
                baseAmount: $line['amount_ht'],
                rateBpApplied: $rule->rate_bp,
                withheldAmount: 0,
                reason: WithholdingResolution::REASON_BELOW_THRESHOLD,
            );
        }

        if ($rule->base === null) {
            // Defensive: confirm() refuses an unset base, but state it here
            // too - HT vs TTC differs by 19.25% of the base.
            throw new DomainException(sprintf(
                'Withholding rule %s has no base configured; it cannot compute (03-tax-procurement §6.2).',
                $rule->code,
            ));
        }

        // §6.4 steps 5-6.
        $base = $rule->base === WithholdingBase::AmountHt
            ? $line['amount_ht']
            : $line['amount_ttc'];

        $withheld = $rule->rate()->applyTo(Money::of($base));

        return new WithholdingResolution(
            ruleId: (int) $rule->getKey(),
            baseAmount: $base,
            rateBpApplied: $rule->rate_bp,
            withheldAmount: $withheld->amount(),
        );
    }

    /**
     * §6.2 supplier_condition: a JSON criterion set where EVERY present key
     * must match the supplier attribute of the same name. A null/empty
     * condition matches every supplier.
     *
     * @param  array<string, mixed>  $supplier
     */
    private function supplierConditionMatches(WithholdingRule $rule, array $supplier): bool
    {
        $condition = $rule->supplier_condition;

        if ($condition === null || $condition === []) {
            return true;
        }

        foreach ($condition as $key => $expected) {
            $actual = $supplier[$key] ?? null;

            if (is_bool($expected) || is_bool($actual)) {
                if ((bool) $expected !== (bool) $actual) {
                    return false;
                }

                continue;
            }

            if ((string) $this->scalar($expected) !== (string) $this->scalar($actual)) {
                return false;
            }
        }

        return true;
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
