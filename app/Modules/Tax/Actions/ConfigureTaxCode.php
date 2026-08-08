<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\TaxType;
use App\Modules\Tax\Models\TaxCode;
use App\Support\Audit\Actor;
use App\Support\Rate\Rate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §5.3 - create or reconfigure a TaxCode
 * VERSION. Gated on `ledger.configure`: tax codes shape what the ledger
 * records, the same split ConfigureJournal / ConfigureAnalyticAxis draw.
 *
 * Append-only discipline: `code`, `rate_bp` and `effective_from` are
 * immutable in place. A rate change is two calls - close the current row
 * (set `effective_to`) and create a successor - because editing the rate
 * silently rewrites the tax of every historical invoice that snapshotted it.
 *
 * Nothing is seeded (00-core §16): every row passes through here, audited,
 * so the accountant's configuration is the only source of tax rates.
 */
final class ConfigureTaxCode
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{code?:string,name?:string,name_fr?:string,tax_type?:string,rate_bp?:int,direction?:string,effective_from?:string,effective_to?:string|null,is_exempt?:bool,is_zero_rated?:bool,exemption_legal_ref?:string|null,exemption_condition?:string|null,collected_account_id?:int|null,deductible_account_id?:int|null,non_deductible_expense_account_id?:int|null,affects_prorata_numerator?:bool,affects_prorata_denominator?:bool,is_active?:bool}  $attributes
     */
    public function handle(?int $taxCodeId, array $attributes, Actor $actor): TaxCode
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($taxCodeId, $attributes, $actor): TaxCode {
            if ($taxCodeId === null) {
                return $this->create($attributes, $actor);
            }

            return $this->update($taxCodeId, $attributes, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes, Actor $actor): TaxCode
    {
        foreach (['code', 'name', 'name_fr', 'tax_type', 'rate_bp', 'direction', 'effective_from'] as $required) {
            if (! isset($attributes[$required])) {
                throw new DomainException(sprintf('A new tax code requires %s.', $required));
            }
        }

        $this->assertValid($attributes);

        // Overlap check per code over [effective_from, effective_to):
        // exactly one version of a code may be in force on any date, or the
        // engine cannot pick a rate deterministically. Lock the code's rows
        // so two concurrent configures cannot both pass the check.
        $siblings = TaxCode::query()
            ->where('code', $attributes['code'])
            ->lockForUpdate()
            ->get();

        $from = (string) $attributes['effective_from'];
        /** @var string|null $to */
        $to = $attributes['effective_to'] ?? null;

        foreach ($siblings as $sibling) {
            $siblingFrom = $sibling->effective_from->toDateString();
            $siblingTo = $sibling->effective_to?->toDateString();

            $overlaps = ($siblingTo === null || $from < $siblingTo)
                && ($to === null || $siblingFrom < $to);

            if ($overlaps) {
                throw new DomainException(sprintf(
                    'Tax code %s already has a version in force over part of [%s, %s); close it first.',
                    $sibling->code,
                    $from,
                    $to ?? 'open',
                ));
            }
        }

        $taxCode = TaxCode::query()->create($attributes);

        $this->audit->handle(
            action: AuditAction::Created,
            module: 'Tax',
            auditableType: TaxCode::class,
            auditableId: (int) $taxCode->getKey(),
            after: $attributes,
            actor: $actor,
        );

        return $taxCode->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(int $taxCodeId, array $attributes, Actor $actor): TaxCode
    {
        /** @var TaxCode $taxCode */
        $taxCode = TaxCode::query()->lockForUpdate()->findOrFail($taxCodeId);

        // Append-only once created: these three define what every snapshot
        // meant. Changing any of them in place rewrites history.
        foreach (['code', 'rate_bp', 'effective_from'] as $frozen) {
            if (array_key_exists($frozen, $attributes)
                && $this->normalise($attributes[$frozen]) !== $this->normalise($taxCode->getAttribute($frozen))) {
                throw new DomainException(sprintf(
                    '%s is immutable on tax code %s; close this version and configure a successor.',
                    $frozen,
                    $taxCode->code,
                ));
            }
        }

        $this->assertValid([...$taxCode->only($taxCode->getFillable()), ...$attributes]);

        $before = $taxCode->only(array_keys($attributes));

        $taxCode->fill($attributes)->save();

        $this->audit->handle(
            action: AuditAction::Updated,
            module: 'Tax',
            auditableType: TaxCode::class,
            auditableId: (int) $taxCode->getKey(),
            before: $before,
            after: $attributes,
            actor: $actor,
        );

        return $taxCode->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(array $attributes): void
    {
        if (isset($attributes['tax_type']) && is_string($attributes['tax_type'])
            && TaxType::tryFrom($attributes['tax_type']) === null) {
            throw new DomainException(sprintf('Unknown tax type %s.', $attributes['tax_type']));
        }

        if (isset($attributes['direction'])
            && ! in_array($attributes['direction'], ['output', 'input', 'both'], true)) {
            throw new DomainException('direction must be output, input or both.');
        }

        if (isset($attributes['rate_bp'])) {
            // Rate::ofBasisPoints rejects negatives; the value is stored in
            // Rate's scale (100 000 bp = 100%) so it round-trips exactly.
            Rate::ofBasisPoints((int) $attributes['rate_bp']);
        }

        $isExempt = (bool) ($attributes['is_exempt'] ?? false);
        $isZeroRated = (bool) ($attributes['is_zero_rated'] ?? false);

        if ($isExempt && $isZeroRated) {
            // Distinct states: zero-rated supplies grant input deduction,
            // exempt supplies do not - conflating them corrupts the prorata.
            throw new DomainException('A tax code cannot be both exempt and zero-rated.');
        }

        if ($isExempt) {
            $legalRef = $attributes['exemption_legal_ref'] ?? null;

            if (! is_string($legalRef) || trim($legalRef) === '') {
                // §5.3: ships empty, mandatory before use - an exemption
                // without its CGI article cannot be defended in an audit.
                throw new DomainException('An exempt tax code requires exemption_legal_ref.');
            }

            if (($attributes['rate_bp'] ?? 0) !== 0) {
                throw new DomainException('An exempt tax code must carry rate_bp = 0.');
            }
        }
    }

    private function normalise(mixed $value): string
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->toDateString();
        }

        return (string) (is_scalar($value) ? $value : '');
    }
}
