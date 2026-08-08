<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Models\MarkApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The approval batch header (01-assessment 7.3). Its three scope columns are
 * required: a header with no scope is not a batch, and MarkFactory::scenario()
 * is the intended source of consistent ids.
 *
 * @extends Factory<MarkApproval>
 */
final class MarkApprovalFactory extends Factory
{
    /** @var class-string<MarkApproval> */
    protected $model = MarkApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => MarkApproval::STATUS_OPEN,
            'last_decision' => null,
            'mark_count' => 0,
            'version' => 1,
        ];
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => ['status' => MarkApproval::STATUS_SUBMITTED]);
    }
}
