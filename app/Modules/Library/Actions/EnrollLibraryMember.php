<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\MemberStatus;
use App\Modules\Library\Domain\MemberType;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\MembershipClass;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.3 - membership.
 *
 * Keying invariant: a STUDENT joins through an enrollment (the year
 * scope), and `student_id` is DERIVED from that enrollment via DB::table
 * - never accepted from the caller - so `student_id =
 * enrollment.student_id` holds by construction. Staff join through
 * staff_members; externals carry name + contact (the identity CHECK in
 * the schema is the last line of defence).
 */
final class EnrollLibraryMember
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     member_type: string,
     *     membership_class_id: int,
     *     academic_year_id: int,
     *     joined_on: string,
     *     enrollment_id?: int|null,
     *     staff_member_id?: int|null,
     *     external_name?: string|null,
     *     external_contact?: string|null,
     *     expires_on?: string|null,
     *     idempotency_key?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryMember
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var LibraryMember|null $existing */
            $existing = LibraryMember::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $type = MemberType::from($data['member_type']);

        return DB::transaction(function () use ($data, $actor, $type, $idempotencyKey): LibraryMember {
            $membershipClass = MembershipClass::query()->find($data['membership_class_id']);

            if ($membershipClass === null || $membershipClass->is_archived) {
                throw new DomainException('An active membership class is required.');
            }

            [$studentId, $staffMemberId, $enrollmentId] = $this->resolveIdentity($type, $data);

            $memberNo = sprintf(
                'LM/%s/%05d',
                Carbon::parse($data['joined_on'])->format('Y'),
                $this->sequence->allocate('library.member_no'),
            );

            /** @var LibraryMember $member */
            $member = LibraryMember::query()->create([
                'member_no' => $memberNo,
                'member_type' => $type,
                'student_id' => $studentId,
                'staff_member_id' => $staffMemberId,
                'enrollment_id' => $enrollmentId,
                'academic_year_id' => $data['academic_year_id'],
                'membership_class_id' => (int) $membershipClass->getKey(),
                'status' => MemberStatus::Active,
                'joined_on' => $data['joined_on'],
                'expires_on' => $data['expires_on'] ?? null,
                'external_name' => $type === MemberType::External ? ($data['external_name'] ?? null) : null,
                'external_contact' => $type === MemberType::External ? ($data['external_contact'] ?? null) : null,
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Library',
                auditableType: LibraryMember::class,
                auditableId: (int) $member->getKey(),
                after: [
                    'member_no' => $memberNo,
                    'member_type' => $type->value,
                    'student_id' => $studentId,
                    'staff_member_id' => $staffMemberId,
                ],
                actor: $actor,
            );

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: int|null, 2: int|null} [student_id, staff_member_id, enrollment_id]
     */
    private function resolveIdentity(MemberType $type, array $data): array
    {
        if ($type === MemberType::Student) {
            $enrollmentId = $data['enrollment_id'] ?? null;

            if ($enrollmentId === null) {
                throw new DomainException(
                    'A student membership keys on the enrollment (07-students C3); enrollment_id is required.'
                );
            }

            /** @var object{id: int|string, student_id: int|string, status: string, academic_year_id: int|string}|null $enrollment */
            $enrollment = DB::table('enrollments')
                ->where('id', (int) $enrollmentId)
                ->first(['id', 'student_id', 'status', 'academic_year_id']);

            if ($enrollment === null) {
                throw new DomainException("Enrollment {$enrollmentId} does not exist.");
            }

            if ($enrollment->status !== 'active') {
                throw new DomainException(
                    "Enrollment {$enrollmentId} is {$enrollment->status}; only an active enrollment can join the library."
                );
            }

            if ((int) $enrollment->academic_year_id !== (int) $data['academic_year_id']) {
                throw new DomainException(
                    'The membership year must match the enrollment\'s academic year.'
                );
            }

            // The §10.3 invariant, by construction.
            return [(int) $enrollment->student_id, null, (int) $enrollment->id];
        }

        if ($type === MemberType::Staff) {
            $staffMemberId = $data['staff_member_id'] ?? null;

            if ($staffMemberId === null) {
                throw new DomainException('A staff membership requires staff_member_id.');
            }

            /** @var object{id: int|string, status: string}|null $staff */
            $staff = DB::table('staff_members')
                ->where('id', (int) $staffMemberId)
                ->first(['id', 'status']);

            if ($staff === null) {
                throw new DomainException("Staff member {$staffMemberId} does not exist.");
            }

            if ($staff->status !== 'active') {
                throw new DomainException("Staff member {$staffMemberId} is not active.");
            }

            return [null, (int) $staff->id, null];
        }

        foreach (['external_name', 'external_contact'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new DomainException(
                    'An external member requires a name and a contact (§10.3 CHECK).'
                );
            }
        }

        return [null, null, null];
    }
}
