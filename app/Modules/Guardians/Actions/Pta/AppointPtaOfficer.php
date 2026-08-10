<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions\Pta;

use App\Modules\Guardians\Models\PtaOfficer;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Appoints a guardian to a standing PTA office (President, Secretary,
 * Treasurer). Closes any predecessor's term rather than leaving two
 * officers claiming the same office at once.
 */
final class AppointPtaOfficer
{
    public function handle(int $guardianId, string $office, string $termStartsOn): PtaOfficer
    {
        Gate::authorize(Permission::GuardiansManage->value);

        if (trim($office) === '') {
            throw new DomainException('An office name is required.');
        }

        return DB::transaction(function () use ($guardianId, $office, $termStartsOn): PtaOfficer {
            PtaOfficer::query()
                ->where('office', $office)
                ->whereNull('term_ends_on')
                ->update(['term_ends_on' => $termStartsOn]);

            return PtaOfficer::query()->create([
                'guardian_id' => $guardianId,
                'office' => $office,
                'term_starts_on' => $termStartsOn,
                'term_ends_on' => null,
            ]);
        });
    }
}
