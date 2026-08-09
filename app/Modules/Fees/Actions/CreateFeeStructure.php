<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\BoardingScope;
use App\Modules\Fees\Domain\EnrollmentStatusScope;
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 04-fees.md §2.5. Creates a structure (status `draft`, version 1) with its
 * lines. The sentinel discipline lives here: class_level_id / stream_id /
 * term_id are NOT NULL with 0 meaning "any"/"annual", so the RESTRICT
 * semantics a real FK would give are enforced in this Action instead -
 * every non-zero id must exist, and level/stream must belong to the
 * structure's section (Academics data read via query builder, never model
 * imports - ModuleBoundaryTest).
 */
final class CreateFeeStructure
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{fee_item_id: int, amount: int, term_id?: int, service_period_start?: string|null, service_period_end?: string|null, is_optional?: bool, display_order?: int}>  $lines
     */
    public function handle(
        int $academicYearId,
        int $schoolSectionId,
        string $name,
        string $effectiveFrom,
        int $classLevelId = FeeStructure::ANY,
        int $streamId = FeeStructure::ANY,
        EnrollmentStatusScope $enrollmentStatusScope = EnrollmentStatusScope::Any,
        BoardingScope $boardingScope = BoardingScope::Any,
        ?string $effectiveTo = null,
        array $lines = [],
        ?Actor $actor = null,
    ): FeeStructure {
        Gate::authorize(Permission::FeeConfigure->value);

        $this->assertScope($schoolSectionId, $classLevelId, $streamId);

        if ($effectiveTo !== null
            && ! CarbonImmutable::parse($effectiveTo)->greaterThan(CarbonImmutable::parse($effectiveFrom))) {
            throw new DomainException('effective_to is exclusive and must be after effective_from.');
        }

        $this->assertLines($lines);

        return DB::transaction(function () use (
            $academicYearId, $schoolSectionId, $name, $effectiveFrom, $classLevelId,
            $streamId, $enrollmentStatusScope, $boardingScope, $effectiveTo, $lines, $actor
        ): FeeStructure {
            $structure = FeeStructure::query()->create([
                'academic_year_id' => $academicYearId,
                'school_section_id' => $schoolSectionId,
                'class_level_id' => $classLevelId,
                'stream_id' => $streamId,
                'enrollment_status_scope' => $enrollmentStatusScope,
                'boarding_scope' => $boardingScope,
                'name' => $name,
                'status' => FeeStructureStatus::Draft,
                'version' => 1,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);

            foreach ($lines as $index => $line) {
                $structure->lines()->create([
                    'fee_item_id' => $line['fee_item_id'],
                    'amount' => $line['amount'],
                    'term_id' => $line['term_id'] ?? FeeStructureLine::ANNUAL,
                    'service_period_start' => $line['service_period_start'] ?? null,
                    'service_period_end' => $line['service_period_end'] ?? null,
                    'is_optional' => $line['is_optional'] ?? false,
                    'display_order' => $line['display_order'] ?? $index,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: FeeStructure::class,
                auditableId: (int) $structure->getKey(),
                after: [
                    'name' => $name,
                    'academic_year_id' => $academicYearId,
                    'school_section_id' => $schoolSectionId,
                    'class_level_id' => $classLevelId,
                    'stream_id' => $streamId,
                    'line_count' => count($lines),
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $structure;
        });
    }

    private function assertScope(int $schoolSectionId, int $classLevelId, int $streamId): void
    {
        if ($classLevelId !== FeeStructure::ANY) {
            $sectionOfLevel = DB::table('class_levels')->where('id', $classLevelId)->value('school_section_id');

            if ($sectionOfLevel === null) {
                throw new DomainException('The class level does not exist.');
            }

            if ((int) $sectionOfLevel !== $schoolSectionId) {
                throw new DomainException('The class level does not belong to the structure\'s school section.');
            }
        }

        if ($streamId !== FeeStructure::ANY) {
            $sectionOfStream = DB::table('streams')->where('id', $streamId)->value('school_section_id');

            if ($sectionOfStream === null) {
                throw new DomainException('The stream does not exist.');
            }

            if ((int) $sectionOfStream !== $schoolSectionId) {
                throw new DomainException('The stream does not belong to the structure\'s school section.');
            }
        }
    }

    /**
     * @param  list<array{fee_item_id: int, amount: int, term_id?: int, service_period_start?: string|null, service_period_end?: string|null, is_optional?: bool, display_order?: int}>  $lines
     */
    private function assertLines(array $lines): void
    {
        foreach ($lines as $line) {
            if ($line['amount'] < 0) {
                throw new DomainException('A fee structure line amount cannot be negative.');
            }

            $item = FeeItem::query()->find($line['fee_item_id']);

            if ($item === null) {
                throw new DomainException('A fee structure line references a fee item that does not exist.');
            }

            if ($item->is_archived) {
                throw new DomainException(sprintf('Fee item %s is archived and cannot be billed.', $item->code));
            }

            $termId = $line['term_id'] ?? FeeStructureLine::ANNUAL;

            if ($termId !== FeeStructureLine::ANNUAL
                && ! DB::table('assessment_periods')->where('id', $termId)->exists()) {
                throw new DomainException('A fee structure line references a term that does not exist.');
            }

            $start = $line['service_period_start'] ?? null;
            $end = $line['service_period_end'] ?? null;

            if (($start === null) !== ($end === null)) {
                throw new DomainException('A service period needs both its start and end dates.');
            }
        }
    }
}
