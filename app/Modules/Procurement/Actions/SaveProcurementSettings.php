<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Role;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\ApprovalThreshold;
use App\Modules\Procurement\Models\ProcurementSettings;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4 - write the procurement policy
 * singleton and (optionally) replace the ordered approval-threshold bands.
 *
 * Bands are validated as a set before any write: sequences unique,
 * min <= max, and every `required_role` a real Identity Role value - a
 * threshold naming a role nobody can hold would make every large PO
 * unapprovable and nobody would know why.
 */
final class SaveProcurementSettings
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<array{min_amount: int, max_amount: int|null, required_role: string, sequence: int}>|null  $thresholds  null = leave bands untouched
     */
    public function handle(array $settings, Actor $actor, ?array $thresholds = null): ProcurementSettings
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        return DB::transaction(function () use ($settings, $actor, $thresholds): ProcurementSettings {
            /** @var ProcurementSettings $row */
            $row = ProcurementSettings::query()->firstOrNew(['id' => ProcurementSettings::SINGLETON_ID]);
            $row->id = ProcurementSettings::SINGLETON_ID;
            $row->fill($settings);
            $row->save();

            if ($thresholds !== null) {
                $this->replaceThresholds($thresholds);
            }

            $this->audit->handle(
                action: AuditAction::SettingChanged,
                module: 'Procurement',
                auditableType: ProcurementSettings::class,
                auditableId: ProcurementSettings::SINGLETON_ID,
                after: $settings + ['thresholds_replaced' => $thresholds !== null],
                actor: $actor,
            );

            return $row;
        });
    }

    /**
     * @param  list<array{min_amount: int, max_amount: int|null, required_role: string, sequence: int}>  $thresholds
     */
    private function replaceThresholds(array $thresholds): void
    {
        $sequences = [];

        foreach ($thresholds as $band) {
            if (Role::tryFrom($band['required_role']) === null) {
                throw ValidationException::withMessages([
                    'thresholds' => sprintf('[%s] is not a role anyone can hold.', $band['required_role']),
                ]);
            }

            if ($band['max_amount'] !== null && $band['max_amount'] < $band['min_amount']) {
                throw ValidationException::withMessages([
                    'thresholds' => 'A threshold band cannot end below its own floor.',
                ]);
            }

            if (in_array($band['sequence'], $sequences, true)) {
                throw ValidationException::withMessages([
                    'thresholds' => 'Threshold sequences must be unique - the FIRST matching band decides.',
                ]);
            }

            $sequences[] = $band['sequence'];
        }

        ApprovalThreshold::query()->delete();

        foreach ($thresholds as $band) {
            ApprovalThreshold::query()->create($band);
        }
    }
}
