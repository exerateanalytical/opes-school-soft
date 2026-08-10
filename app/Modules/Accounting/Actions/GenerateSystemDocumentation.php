<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\SystemDocumentationSnapshot;
use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * docs/specs/02-accounting.md §14.4 - "documentation du système comptable".
 *
 * Reads current configuration and generates a dated PDF: the chart of
 * accounts, journals and their default accounts, every ACTIVE posting rule
 * with its version/condition/lines/effective dates, analytic axes, sequence
 * formats, period-locking configuration, the roles holding accounting
 * permissions, and the software/schema version. Regenerating never
 * overwrites - it supersedes, the same pattern GenerateStatutoryBook uses
 * and for the same reason: the document cannot drift because nobody writes
 * it by hand, and each version stays exactly as it was when generated.
 */
final class GenerateSystemDocumentation
{
    public function __construct(private readonly ReadSetting $settings) {}

    public function handle(): SystemDocumentationSnapshot
    {
        Gate::authorize(Permission::LedgerView->value);

        $user = Auth::user();

        if ($user === null) {
            throw new DomainException('Generating the system documentation is an audited act; it needs a user.');
        }

        $generatedAt = now();

        $schemaVersion = (string) DB::table('migrations')->orderByDesc('id')->value('migration');
        $softwareVersion = (string) config('app.version', 'dev');

        $pdf = Pdf::loadView('reports.system-documentation', [
            'schoolName' => (string) ($this->settings->handle('school.name') ?? 'School'),
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'generatedBy' => (string) $user->name,
            'softwareVersion' => $softwareVersion,
            'schemaVersion' => $schemaVersion,
            'accounts' => DB::table('chart_of_accounts')->orderBy('code')->get(['code', 'name', 'account_class', 'is_postable']),
            'journals' => DB::table('journals')->orderBy('code')->get(['code', 'name', 'default_debit_account_id', 'default_credit_account_id', 'requires_maker_checker', 'piece_no_format']),
            'postingRules' => DB::table('posting_rules')
                ->where('is_active', true)
                ->orderBy('code')
                ->orderByDesc('version')
                ->get(['code', 'version', 'event', 'journal_id', 'condition_expression', 'is_locked', 'effective_from', 'effective_to']),
            'postingRuleLines' => DB::table('posting_rule_lines')
                ->orderBy('posting_rule_id')->orderBy('sequence')
                ->get(['posting_rule_id', 'sequence', 'account_source', 'account_code', 'account_path', 'sign', 'amount_expression']),
            'analyticAxes' => DB::table('analytic_axes')->orderBy('display_order')->get(['code', 'name', 'is_mandatory', 'applies_to_classes']),
            'periods' => DB::table('accounting_periods')
                ->orderByDesc('period_month')
                ->limit(24)
                ->get(['period_month', 'status', 'soft_locked_at', 'hard_locked_at']),
            'sequences' => DB::table('sequences')->orderBy('series')->get(['series', 'next_value']),
            'accountingRoles' => DB::table('role_has_permissions as rhp')
                ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->join('roles as r', 'r.id', '=', 'rhp.role_id')
                ->where('p.name', 'like', 'ledger.%')
                ->orWhere('p.name', 'like', 'accounting.%')
                ->distinct()
                ->orderBy('r.name')
                ->pluck('r.name'),
        ])->setPaper('a4', 'portrait');

        $binary = $pdf->output();
        $sha256 = hash('sha256', $binary);

        $path = sprintf('system-documentation/%s.pdf', $generatedAt->format('YmdHis'));
        Storage::disk('local')->put($path, $binary);

        return DB::transaction(function () use ($generatedAt, $user, $softwareVersion, $schemaVersion, $path, $sha256): SystemDocumentationSnapshot {
            $previous = SystemDocumentationSnapshot::query()->orderByDesc('id')->lockForUpdate()->first();

            return SystemDocumentationSnapshot::query()->create([
                'generated_at' => $generatedAt,
                'generated_by' => (int) $user->getKey(),
                'software_version' => $softwareVersion,
                'schema_version' => $schemaVersion,
                'file_path' => $path,
                'sha256' => $sha256,
                'supersedes_id' => $previous?->getKey(),
            ]);
        });
    }
}
