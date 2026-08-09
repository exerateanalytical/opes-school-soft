<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Support\Clock\BusinessDate;
use App\Support\Fiscal\FiscalIdentityGate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §10.3 - prints the Student Account Statement.
 *
 * LIVE, not snapshot (unlike PrintReceipt/PrintInvoice): §10.3 states it
 * plainly - "live with a printed as_of date" - reprinting after a payment
 * or adjustment is expected and correct, and every render prints the
 * "Generated on" footer RenderDocument's live path already stamps.
 */
final class PrintStatement
{
    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $studentId, ?string $asOf = null, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::FeeView->value);

        FiscalIdentityGate::assertCompleteForMoneyDocuments();

        /** @var object{first_name: string, last_name: string, matricule: string}|null $student */
        $student = DB::table('students')->where('id', $studentId)->first(['first_name', 'last_name', 'matricule']);

        if ($student === null) {
            throw new DomainException("Student {$studentId} does not exist.");
        }

        $classGroup = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', $studentId)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        $enrollmentId = DB::table('enrollments')
            ->where('student_id', $studentId)
            ->orderByDesc('academic_year_id')
            ->orderByDesc('id')
            ->value('id');

        $asOf ??= BusinessDate::today();

        $lines = $enrollmentId === null
            ? collect()
            : app(StudentStatement::class)->handle((int) $enrollmentId, $asOf);

        $rows = [];
        $closing = 0;

        foreach ($lines as $line) {
            $rows[] = [
                'date' => $line->date,
                'reference' => $line->reference,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $line->balance,
            ];
            $closing = $line->balance;
        }

        $chrome = $this->render->captureSchoolChrome(includeStateHeader: false);

        $payload = [
            'school' => $chrome,
            'statement' => [
                'student_name' => $student->first_name.' '.$student->last_name,
                'student_matricule' => $student->matricule,
                'class_group' => is_string($classGroup) ? $classGroup : '',
                'as_of' => $asOf,
                'lines' => $rows,
                'closing_balance' => $closing,
            ],
        ];

        return $this->render->handle(
            templateCode: 'FEE-STATEMENT',
            subjectType: 'Student',
            subjectId: $studentId,
            subjectLabel: 'Statement for '.$student->first_name.' '.$student->last_name.' as of '.$asOf,
            language: $language,
            data: $payload,
        );
    }
}
