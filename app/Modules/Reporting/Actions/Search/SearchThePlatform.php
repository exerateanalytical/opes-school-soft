<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions\Search;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The shell header's global search. Was `disabled`, on purpose, with a
 * tooltip explaining why - "a search box that quietly swallows every
 * query is worse than no search box at all." This is the real
 * implementation that comment was waiting for.
 *
 * Every source is gated behind the SAME permission its own nav item and
 * index screen use - a teacher's query must never surface a supplier or an
 * invoice just because the string happened to match, regardless of how the
 * UI presents it. Five results per source, five sources: a fixed, small
 * fan-out of cheap indexed LIKE queries rather than one expensive
 * cross-table UNION, so a query never costs more than five small lookups.
 *
 * `%`/`_` in the operator's own input are escaped before going into LIKE -
 * a literal percent sign in someone's name must search for a percent sign,
 * not silently become a wildcard.
 */
final class SearchThePlatform
{
    private const PER_SOURCE_LIMIT = 5;

    /**
     * @return list<array{group: string, label: string, sublabel: string, url: string}>
     */
    public function handle(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $like = '%'.addcslashes($query, '%_\\').'%';

        $results = [];

        if (Gate::allows(Permission::StudentsView->value)) {
            $results = array_merge($results, $this->students($like));
        }

        if (Gate::allows(Permission::StaffView->value)) {
            $results = array_merge($results, $this->staff($like));
        }

        if (Gate::allows(Permission::GuardiansManage->value)) {
            $results = array_merge($results, $this->guardians($like));
        }

        if (Gate::allows(Permission::ProcurementView->value)) {
            $results = array_merge($results, $this->suppliers($like));
        }

        if (Gate::allows(Permission::FeeView->value)) {
            $results = array_merge($results, $this->invoices($like));
        }

        return $results;
    }

    /**
     * @return list<array{group: string, label: string, sublabel: string, url: string}>
     */
    private function students(string $like): array
    {
        return DB::table('students')
            ->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('matricule', 'like', $like);
            })
            ->orderBy('last_name')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'first_name', 'last_name', 'matricule'])
            ->map(fn ($s): array => [
                'group' => 'students',
                'label' => trim($s->first_name.' '.$s->last_name),
                'sublabel' => (string) $s->matricule,
                'url' => '/students/'.$s->id,
            ])
            ->all();
    }

    /**
     * @return list<array{group: string, label: string, sublabel: string, url: string}>
     */
    private function staff(string $like): array
    {
        return DB::table('staff_members')
            ->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('staff_no', 'like', $like);
            })
            ->orderBy('last_name')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'first_name', 'last_name', 'staff_no'])
            ->map(fn ($s): array => [
                'group' => 'staff',
                'label' => trim($s->first_name.' '.$s->last_name),
                'sublabel' => (string) $s->staff_no,
                // No per-staff detail screen exists; the index is the real
                // destination, not a fabricated link to a page that 404s.
                'url' => '/staff',
            ])
            ->all();
    }

    /**
     * @return list<array{group: string, label: string, sublabel: string, url: string}>
     */
    private function guardians(string $like): array
    {
        return DB::table('guardians')
            ->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('guardian_no', 'like', $like);
            })
            ->orderBy('last_name')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'first_name', 'last_name', 'guardian_no'])
            ->map(fn ($g): array => [
                'group' => 'guardians',
                'label' => trim($g->first_name.' '.$g->last_name),
                'sublabel' => (string) $g->guardian_no,
                'url' => '/guardians/'.$g->id,
            ])
            ->all();
    }

    /**
     * @return list<array{group: string, label: string, sublabel: string, url: string}>
     */
    private function suppliers(string $like): array
    {
        return DB::table('suppliers')
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('code', 'like', $like);
            })
            ->orderBy('name')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'name', 'code'])
            ->map(fn ($s): array => [
                'group' => 'suppliers',
                'label' => (string) $s->name,
                'sublabel' => (string) $s->code,
                'url' => '/procurement/suppliers/'.$s->id,
            ])
            ->all();
    }

    /**
     * @return list<array{group: string, label: string, sublabel: string, url: string}>
     */
    private function invoices(string $like): array
    {
        return DB::table('invoices')
            ->where('invoice_no', 'like', $like)
            ->orderByDesc('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'invoice_no'])
            ->map(fn ($i): array => [
                'group' => 'invoices',
                'label' => (string) $i->invoice_no,
                'sublabel' => '',
                // No per-invoice detail screen exists (only print/API); the
                // index is the real destination.
                'url' => '/finance/invoices',
            ])
            ->all();
    }
}
