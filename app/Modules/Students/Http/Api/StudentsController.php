<?php

declare(strict_types=1);

namespace App\Modules\Students\Http\Api;

use App\Modules\Students\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only v1 students adapter (docs/plans/phase-12-13.md 12.4).
 *
 * 00-core 6.1: REST and Livewire are both thin adapters over the same module
 * internals - this controller only filters, paginates and presents. The
 * route carries `auth:sanctum` + `can:students.view` + `abilities:students.view`
 * (routes/api.php), so by the time execution reaches here the caller has
 * proven both the user permission and the token grant; nothing is re-decided
 * in this class.
 *
 * The presented field list is deliberately curated: the encrypted sensitive
 * columns (national ID, religion, blood group, genotype) and medical data
 * never leave through this adapter. An integration that needs them is a
 * different, deliberate decision - not a field added here.
 */
final class StudentsController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $students = Student::query()
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(function ($inner) use ($search): void {
                    $inner->where('matricule', 'like', '%'.$search.'%')
                        ->orWhere('admission_no', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (Student $student): array => $this->present($student),
                $students->items(),
            ),
            'meta' => [
                'page' => $students->currentPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'last_page' => $students->lastPage(),
            ],
        ]);
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json(['data' => $this->present($student)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Student $student): array
    {
        return [
            'id' => $student->id,
            'matricule' => $student->matricule,
            'matricule_is_official' => $student->matricule_is_official,
            'admission_no' => $student->admission_no,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'last_name' => $student->last_name,
            'preferred_name' => $student->preferred_name,
            'gender' => $student->gender->value,
            'date_of_birth' => $student->date_of_birth->toDateString(),
            'nationality' => $student->nationality,
            'status' => $student->status->value,
            'phone' => $student->phone,
            'email' => $student->email,
            'city' => $student->city,
            'region' => $student->region,
            'first_admission_date' => $student->first_admission_date?->toDateString(),
            'left_on' => $student->left_on?->toDateString(),
            'is_archived' => $student->is_archived,
        ];
    }
}
