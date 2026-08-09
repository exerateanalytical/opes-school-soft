<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\ProrataRounding;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Modules\Tax\Models\TaxSettings;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §12 items 8 & 15 - record the
 * accountant's decisions on the two blocking tax-engine switches. Saving a
 * value IS confirming it (the screen presents them as decisions, not
 * defaults), so confirmed_by/at are stamped on every save. Both ship NULL;
 * nothing here ever writes a default.
 */
final class ConfigureTaxSettings
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{withholding_recognition?: string|null, prorata_rounding?: string|null}  $attributes
     */
    public function handle(array $attributes, Actor $actor): TaxSettings
    {
        Gate::authorize(self::PERMISSION);

        if (array_key_exists('withholding_recognition', $attributes)
            && $attributes['withholding_recognition'] !== null
            && WithholdingRecognition::tryFrom((string) $attributes['withholding_recognition']) === null) {
            throw new DomainException('withholding_recognition must be on_invoice or on_payment.');
        }

        if (array_key_exists('prorata_rounding', $attributes)
            && $attributes['prorata_rounding'] !== null
            && ProrataRounding::tryFrom((string) $attributes['prorata_rounding']) === null) {
            throw new DomainException('prorata_rounding must be exact_bp or up_to_whole_percent.');
        }

        return DB::transaction(function () use ($attributes, $actor): TaxSettings {
            /** @var TaxSettings|null $settings */
            $settings = TaxSettings::query()->lockForUpdate()->find(TaxSettings::SINGLETON_ID);

            $before = $settings?->only(array_keys($attributes));

            $stamp = ['confirmed_by' => $actor->id, 'confirmed_at' => now()];

            if ($settings === null) {
                // The PK is not fillable and not auto-incrementing: assign
                // the singleton id explicitly.
                $settings = new TaxSettings([...$attributes, ...$stamp]);
                $settings->setAttribute('id', TaxSettings::SINGLETON_ID);
                $settings->save();
            } else {
                $settings->fill([...$attributes, ...$stamp])->save();
            }

            $this->audit->handle(
                action: AuditAction::SettingChanged,
                module: 'Tax',
                auditableType: TaxSettings::class,
                auditableId: (int) $settings->getKey(),
                before: $before,
                after: $attributes,
                actor: $actor,
            );

            return $settings->refresh();
        });
    }
}
