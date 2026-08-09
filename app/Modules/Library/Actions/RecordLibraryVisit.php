<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Library\Models\LibraryVisit;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.9 - the turnstile. member NULL = walk-in guest.
 * Deliberately light: a visit is a tally mark, not a transaction.
 */
final class RecordLibraryVisit
{
    /**
     * @param array{
     *     library_member_id?: int|null,
     *     member_no?: string|null,
     *     visited_on: string,
     *     visited_at_time?: string|null,
     *     purpose?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryVisit
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        $memberId = $data['library_member_id'] ?? null;

        if ($memberId === null && ($data['member_no'] ?? null) !== null) {
            $memberId = LibraryMember::query()
                ->where('member_no', (string) $data['member_no'])
                ->value('id');

            if ($memberId === null) {
                throw new DomainException('No member carries that card number.');
            }
        }

        return LibraryVisit::query()->create([
            'library_member_id' => $memberId === null ? null : (int) $memberId,
            'visited_on' => $data['visited_on'],
            'visited_at_time' => $data['visited_at_time'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'recorded_by' => $actor->id,
        ]);
    }
}
