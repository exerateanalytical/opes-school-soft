<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Students;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Guardians\Models\StudentGuardian;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The Guardians tab of the student profile (07-students 11.2), rendered from
 * inside the Guardians module.
 *
 * It exists as its own component for a boundary reason, not a UI one. The tab
 * has to show whether each link is valid (7.3) and what it grants (7.5), and
 * both answers live on Guardians\Models\StudentGuardian.
 * tests/Architecture/ModuleBoundaryTest.php forbids the Students module from
 * importing that class, and 7.5 forbids anything but GuardianScopeMatrix from
 * re-deriving the answer. A nested component satisfies both: the student
 * screen mounts it by NAME (<livewire:students.guardians-panel/>), so no
 * Students file ever names a Guardians class, and the matrix is called on its
 * home ground.
 *
 * READ-ONLY. Linking, unlinking and any flag change go through
 * SetGuardianAuthorization (7.6), which closes the current row and inserts a
 * successor - a write path with its own permission (`guardians.manage`) and
 * its own session-revocation side effects. Offering an edit control here that
 * did none of that would be worse than offering none.
 */
final class GuardiansPanel extends Component
{
    public int $studentId;

    public function mount(int $studentId): void
    {
        // The panel is mounted from a screen that already gated on this, but a
        // Livewire component is independently addressable over the wire, so it
        // checks for itself (00-core 6.2).
        Gate::authorize(Permission::StudentsView->value);

        $this->studentId = $studentId;
    }

    /**
     * Bounded (00-core 6.2 rule 8) and ordered so that the current primary
     * guardian is first - that is the one an operator is looking for.
     *
     * The guardian is eager-loaded because authorizationFlags() resolves
     * `Guardian.status` per link, and 7.5 makes that a conjunctive gate on
     * every row; without the eager load a child with six guardians costs six
     * extra queries.
     *
     * @return Collection<int, StudentGuardian>
     */
    private function links(): Collection
    {
        return StudentGuardian::query()
            ->with('guardian')
            ->where('student_id', '=', $this->studentId)
            ->orderByDesc('is_primary')
            ->orderByDesc('valid_from')
            ->limit(50)
            ->get();
    }

    public function render(): mixed
    {
        return view('livewire.students.guardians-panel', [
            'links' => $this->links(),
        ]);
    }
}
