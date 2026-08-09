<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\HostelGender;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Models\Hostel;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W2). Creates or updates a hostel building.
 *
 * The warden is referenced by bare user id, verified via DB::table -
 * never through the Identity Models beyond what Welfare already imports
 * (ModuleBoundaryTest). Deactivation is the archive path (00-core 10.5):
 * an inactive hostel keeps history but AllocateBed refuses new residents.
 */
final class SaveHostel
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $hostelId, array $data, Actor $actor): Hostel
    {
        Gate::authorize(HostelPermission::MANAGE);

        return DB::transaction(function () use ($hostelId, $data, $actor): Hostel {
            $existing = null;

            if ($hostelId !== null) {
                /** @var Hostel $existing */
                $existing = Hostel::query()->lockForUpdate()->findOrFail($hostelId);
            }

            $this->validate($data, $existing);

            if ($existing !== null) {
                $existing->fill($data)->save();
                $hostel = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $hostel = Hostel::query()->create($data);
                $auditAction = AuditAction::Created;
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Welfare',
                auditableType: Hostel::class,
                auditableId: (int) $hostel->getKey(),
                after: [
                    'code' => $hostel->code,
                    'name' => $hostel->name,
                    'gender' => $hostel->gender->value,
                    'is_active' => $hostel->is_active,
                ],
                actor: $actor,
            );

            return $hostel->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data, ?Hostel $existing): void
    {
        if ($existing === null) {
            foreach (['code', 'name'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        $field => 'A hostel requires a code and a name.',
                    ]);
                }
            }
        }

        $code = $data['code'] ?? null;

        if ($code !== null) {
            $clash = Hostel::query()
                ->where('code', (string) $code)
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing?->getKey()))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'code' => 'A hostel with this code already exists.',
                ]);
            }
        }

        $gender = $data['gender'] ?? null;

        if ($gender !== null && ! $gender instanceof HostelGender
            && HostelGender::tryFrom((string) $gender) === null) {
            throw ValidationException::withMessages([
                'gender' => 'Unknown hostel gender; expected boys, girls or mixed.',
            ]);
        }

        if (($data['warden_user_id'] ?? null) !== null) {
            $exists = DB::table('users')->where('id', (int) $data['warden_user_id'])->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'warden_user_id' => 'The referenced warden user does not exist.',
                ]);
            }
        }
    }
}
