<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Operations\Actions\AssertEntitlement;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates an academic year, enforcing the CONTIGUOUS-GAPLESS invariant of
 * 00-core 8: year N+1 must start exactly the day after year N ends, so every
 * calendar date resolves to exactly one academic year. The first year is
 * exempt (there is nothing to be contiguous with).
 */
final class CreateAcademicYear
{
    /**
     * Raw string, not App\Modules\Identity\Domain\Permission::AcademicsManage:
     * the Identity Permission enum does not carry an Academics case yet, and
     * Identity's files are not this phase's to edit. When Identity adds
     * `AcademicsManage = 'academics.manage'`, swap this for the enum value -
     * the string is chosen to match the module.action convention exactly.
     */
    public const PERMISSION = 'academics.manage';

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(string $code, string $name, string $startsOn, string $endsOn, Actor $actor): AcademicYear
    {
        Gate::authorize(self::PERMISSION);

        // Entitlement gate (08-operations §4.4): creating an academic year is
        // one of the four annual operations blocked when expired-enforced.
        app(AssertEntitlement::class)->handle('academics.create_year');

        $starts = Carbon::parse($startsOn)->startOfDay();
        $ends = Carbon::parse($endsOn)->startOfDay();

        if ($ends->lessThanOrEqualTo($starts)) {
            throw new DomainException(sprintf(
                'An academic year must end after it starts: got %s to %s.',
                $starts->toDateString(),
                $ends->toDateString(),
            ));
        }

        return DB::transaction(function () use ($code, $name, $starts, $ends, $actor): AcademicYear {
            // Lock the latest year so two concurrent creations cannot both
            // read the same tail and both pass the contiguity check: the
            // second transaction blocks here until the first commits, then
            // sees the new tail and is rejected.
            $latest = AcademicYear::query()
                ->orderByDesc('ends_on')
                ->lockForUpdate()
                ->first();

            if ($latest !== null) {
                $expected = $latest->ends_on->copy()->addDay();

                if (! $starts->isSameDay($expected)) {
                    throw new DomainException(sprintf(
                        'Academic years must be contiguous and gapless: the next year must start on %s, the day after %s ends (%s). Got %s.',
                        $expected->toDateString(),
                        $latest->code,
                        $latest->ends_on->toDateString(),
                        $starts->toDateString(),
                    ));
                }
            }

            $year = AcademicYear::query()->create([
                'code' => $code,
                'name' => $name,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends->toDateString(),
                'is_current' => false,
                'status' => AcademicYearStatus::Planned,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Academics',
                auditableType: AcademicYear::class,
                auditableId: (int) $year->getKey(),
                after: [
                    'code' => $code,
                    'name' => $name,
                    'starts_on' => $starts->toDateString(),
                    'ends_on' => $ends->toDateString(),
                ],
                actor: $actor,
            );

            return $year;
        });
    }
}
