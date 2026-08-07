<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 7.1 + 7.7.
 *
 * Runs duplicate detection and SURFACES the candidates without blocking, which
 * is the whole point of 7.7: "A match presents 'Link to existing guardian Bela
 * Merceline?' rather than silently merging." The decision belongs to the
 * operator, who can see both records; an Action that auto-merged would be
 * creating a data-protection incident on a name collision, and an Action that
 * refused would leave a real second guardian unenterable.
 *
 * The ONE exception is tier 1. `guardians.id_number_blind_index` is UNIQUE
 * (7.1), so a second guardian bearing the same national ID cannot be stored at
 * all - the constraint is not this Action's to overrule. Rather than let that
 * surface as a driver exception, tier 1 is turned into a legible domain error
 * naming the existing guardian, which is the same "link to the existing one"
 * conversation 7.7 asks for, just with the answer forced. Tiers 2 and 3
 * (shared phone, same name + DOB) do NOT block: they are genuinely ambiguous,
 * and a household phone shared by two parents is the normal case, not a
 * duplicate.
 */
final class CreateGuardian
{
    public const SEQUENCE_SERIES = 'guardian_no';

    /**
     * @param  array<string, mixed>  $attributes  the 7.1 column set, minus
     *                                            guardian_no and the blind index, which are derived here
     * @return array{guardian: Guardian, duplicate_tier: string|null, duplicates: list<Guardian>}
     *
     * An array shape rather than a result class: this module owns exactly the
     * files the phase plan assigns it, a second class in this file would not
     * autoload under PSR-4, and PHPStan checks an array shape at level 8 just
     * as strictly as it checks an object.
     */
    public function handle(array $attributes, ?Actor $actor = null): array
    {
        Gate::authorize(Permission::GuardiansManage->value);

        $actor ??= $this->currentActor();

        return DB::transaction(function () use ($attributes, $actor): array {
            $idNumber = $this->stringOrNull($attributes['id_number'] ?? null);

            // Normalised BEFORE storage, not just before comparison: 7.7's
            // tier 2 is an exact match on the phone column, and a column that
            // holds four spellings of one handset can never match exactly.
            $phone = Guardian::normalisePhone($this->stringOrNull($attributes['phone'] ?? null));

            if ($phone === null) {
                throw ValidationException::withMessages([
                    'phone' => 'A guardian must have a usable telephone number.',
                ]);
            }

            $alternativePhone = Guardian::normalisePhone(
                $this->stringOrNull($attributes['alternative_phone'] ?? null)
            );

            $duplicates = app(FindDuplicateGuardians::class)->handle(
                idNumber: $idNumber,
                phone: $phone,
                lastName: $this->stringOrNull($attributes['last_name'] ?? null),
                firstName: $this->stringOrNull($attributes['first_name'] ?? null),
                dateOfBirth: $this->stringOrNull($attributes['date_of_birth'] ?? null),
            );

            if ($duplicates['tier'] === FindDuplicateGuardians::TIER_ID_NUMBER) {
                $existing = $duplicates['candidates'][0];

                throw ValidationException::withMessages([
                    'id_number' => sprintf(
                        'This identity document is already on file for %s (%s). Link to the existing guardian instead.',
                        $existing->fullName(),
                        $existing->guardian_no,
                    ),
                ]);
            }

            $guardianNo = $this->stringOrNull($attributes['guardian_no'] ?? null)
                ?? $this->allocateGuardianNo();

            $payload = $attributes;
            unset($payload['id_number_blind_index']);

            $payload['guardian_no'] = $guardianNo;
            $payload['phone'] = $phone;
            $payload['alternative_phone'] = $alternativePhone;
            $payload['id_number'] = $idNumber;
            $payload['id_number_blind_index'] = Guardian::blindIndexFor($idNumber);

            try {
                $guardian = Guardian::query()->create($payload);
            } catch (UniqueConstraintViolationException) {
                // Reached when a concurrent caller inserted the same ID number
                // between the detection query above and this insert. The
                // detection pass is advisory; the index is the truth.
                throw ValidationException::withMessages([
                    'id_number' => 'This guardian already exists. Reload and link to the existing record.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Guardians',
                auditableType: Guardian::class,
                auditableId: (int) $guardian->getKey(),
                after: [
                    'guardian_no' => $guardian->guardian_no,
                    'first_name' => $guardian->first_name,
                    'last_name' => $guardian->last_name,
                    'phone' => $guardian->phone,
                    // The ID NUMBER is deliberately absent from the audit
                    // payload. Audit rows are exportable (00-core 14) and are
                    // not encrypted at rest; writing the plaintext of a column
                    // the schema goes to the trouble of encrypting would undo
                    // that protection in the one table nobody ever prunes.
                    'has_id_number' => $idNumber !== null,
                ],
                actor: $actor,
            );

            return [
                'guardian' => $guardian,
                'duplicate_tier' => $duplicates['tier'],
                'duplicates' => $duplicates['candidates'],
            ];
        });
    }

    private function allocateGuardianNo(): string
    {
        // Inside the caller's transaction, per SequenceAllocator's contract -
        // handle() already opened one. Gaps are permitted for this series
        // (00-core 12): a rolled-back guardian creation must not stall the
        // counter, and nobody audits guardian numbers for contiguity.
        $next = app(SequenceAllocator::class)->allocate(self::SEQUENCE_SERIES);

        return 'GRD-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
