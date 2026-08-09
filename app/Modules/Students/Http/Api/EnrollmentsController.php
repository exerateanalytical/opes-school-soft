<?php

declare(strict_types=1);

namespace App\Modules\Students\Http\Api;

use App\Modules\Students\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only v1 enrollments adapter (docs/plans/phase-12-13.md 12.4).
 *
 * Gated in routes/api.php by `can:students.view` + `abilities:students.view`:
 * an enrollment is the student record's year-by-year spine (07-students 4),
 * so it is readable by exactly whoever may read the student.
 */
final class EnrollmentsController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $enrollments = Enrollment::query()
            ->when($request->filled('student_id'), function ($query) use ($request): void {
                $query->where('student_id', $request->integer('student_id'));
            })
            ->when($request->filled('academic_year_id'), function ($query) use ($request): void {
                $query->where('academic_year_id', $request->integer('academic_year_id'));
            })
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (Enrollment $enrollment): array => $this->present($enrollment),
                $enrollments->items(),
            ),
            'meta' => [
                'page' => $enrollments->currentPage(),
                'per_page' => $enrollments->perPage(),
                'total' => $enrollments->total(),
                'last_page' => $enrollments->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'academic_year_id' => $enrollment->academic_year_id,
            'class_level_id' => $enrollment->class_level_id,
            'stream_id' => $enrollment->stream_id,
            'school_section_id' => $enrollment->school_section_id,
            'status' => $enrollment->status->value,
            'is_repeat' => $enrollment->is_repeat,
            'enrollment_type' => $enrollment->enrollment_type->value,
            'enrolled_on' => $enrollment->enrolled_on->toDateString(),
            'left_on' => $enrollment->left_on?->toDateString(),
            'boarding_status' => $enrollment->boarding_status,
        ];
    }
}
