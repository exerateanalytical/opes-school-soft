<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Models\WithholdingProfile;
use App\Modules\Tax\Models\WithholdingProfileRule;
use App\Modules\Tax\Models\WithholdingRule;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §6.2 - a WithholdingProfile groups
 * ordered rules for assignment to a supplier. The rules list is replaced
 * wholesale on each save (delete + insert inside the transaction): the
 * ordered set IS the configuration, and partial edits invite sequence
 * collisions the UNIQUE(profile_id, sequence) would then reject anyway.
 */
final class ConfigureWithholdingProfile
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{code?:string,name?:string,name_fr?:string,is_active?:bool}  $attributes
     * @param  list<array{withholding_rule_id:int,sequence:int}>  $rules
     */
    public function handle(?int $profileId, array $attributes, array $rules, Actor $actor): WithholdingProfile
    {
        Gate::authorize(self::PERMISSION);

        $sequences = array_column($rules, 'sequence');

        if (count($sequences) !== count(array_unique($sequences))) {
            throw new DomainException('Profile rule sequences must be unique.');
        }

        $ruleIds = array_column($rules, 'withholding_rule_id');

        if (count($ruleIds) !== count(array_unique($ruleIds))) {
            throw new DomainException('A rule may appear only once in a profile.');
        }

        return DB::transaction(function () use ($profileId, $attributes, $rules, $ruleIds, $actor): WithholdingProfile {
            $found = WithholdingRule::query()->whereIn('id', $ruleIds)->count();

            if ($found !== count($ruleIds)) {
                throw new DomainException('Every profile rule must reference an existing withholding rule.');
            }

            if ($profileId === null) {
                foreach (['code', 'name', 'name_fr'] as $required) {
                    if (! isset($attributes[$required])) {
                        throw new DomainException(sprintf('A new withholding profile requires %s.', $required));
                    }
                }

                $profile = WithholdingProfile::query()->create($attributes);
                $auditAction = AuditAction::Created;
                $before = null;
            } else {
                /** @var WithholdingProfile $profile */
                $profile = WithholdingProfile::query()->lockForUpdate()->findOrFail($profileId);
                $before = $profile->only(array_keys($attributes));
                $profile->fill($attributes)->save();
                $auditAction = AuditAction::Updated;

                WithholdingProfileRule::query()
                    ->where('withholding_profile_id', $profile->getKey())
                    ->delete();
            }

            foreach ($rules as $rule) {
                WithholdingProfileRule::query()->create([
                    'withholding_profile_id' => $profile->getKey(),
                    'withholding_rule_id' => $rule['withholding_rule_id'],
                    'sequence' => $rule['sequence'],
                ]);
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Tax',
                auditableType: WithholdingProfile::class,
                auditableId: (int) $profile->getKey(),
                before: $before,
                after: [...$attributes, 'rules' => $rules],
                actor: $actor,
            );

            return $profile->refresh();
        });
    }
}
