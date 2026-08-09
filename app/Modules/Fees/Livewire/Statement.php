<?php

declare(strict_types=1);

namespace App\Modules\Fees\Livewire;

use App\Modules\Fees\Actions\StudentStatement;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Student fee statement at /finance/students/{student}/statement.
 *
 * Renders F4's StudentStatement Action - the fees-document-authoritative
 * running account - for the student's most recent live enrollment. The
 * Action is keyed by ENROLLMENT (the receivable is per enrollment year);
 * this screen is keyed by student because that is how a cashier navigates,
 * so it resolves student -> latest enrollment here, through the query
 * builder (ModuleBoundaryTest: a Fees class never touches Students\Models).
 *
 * Read-only, gated `fee.view` (the Action re-authorizes the same gate).
 */
#[Layout('layouts.app')]
final class Statement extends Component
{
    public int $studentId;

    public function mount(int $student): void
    {
        Gate::authorize(Permission::FeeView->value);

        $exists = DB::table('students')->where('id', $student)->exists();

        if (! $exists) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
    }

    /**
     * @return array{name: string, matricule: string, class: string}
     */
    private function studentHeader(): array
    {
        /** @var object{first_name: string, last_name: string, matricule: string}|null $row */
        $row = DB::table('students')
            ->where('id', $this->studentId)
            ->first(['first_name', 'last_name', 'matricule']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $className = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', $this->studentId)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        return [
            'name' => $row->first_name.' '.$row->last_name,
            'matricule' => $row->matricule,
            'class' => is_string($className) ? $className : '',
        ];
    }

    private function latestEnrollmentId(): ?int
    {
        $id = DB::table('enrollments')
            ->where('student_id', $this->studentId)
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function render(): mixed
    {
        $enrollmentId = $this->latestEnrollmentId();

        $statement = $enrollmentId === null
            ? collect()
            : app(StudentStatement::class)->handle($enrollmentId);

        /** @var list<array{date: string, description: string, reference: string, debit: int, credit: int, balance: int}> $lines */
        $lines = [];
        $closing = 0;

        foreach ($statement as $row) {
            $lines[] = [
                'date' => $row->date,
                'description' => $row->description,
                'reference' => $row->reference,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'balance' => $row->balance,
            ];
            $closing = $row->balance;
        }

        return view('livewire.fees.statement', [
            'student' => $this->studentHeader(),
            'lines' => $lines,
            'closingBalance' => $closing,
        ]);
    }
}
