<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Domain\DipeLayoutUnconfigured;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\DipeLayout;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The e-DIPE magnetic export (docs/specs/05-hr-payroll.md 11.4).
 *
 * DISABLED BY DESIGN until the DipeLayout definition is populated from the
 * verified CNPS document: exporting through a guessed byte layout
 * mis-records pension quarters, and that harm surfaces decades later, to
 * the employee, irreversibly.
 *
 * Reads `PayrollItemSnapshot` EXCLUSIVELY (10.2: the snapshot is
 * authoritative) and refuses any snapshot lacking `days_worked` - a zero
 * here is a mis-recorded quarter, not a default.
 */
final class ExportDipe
{
    /**
     * @return list<string> one fixed-width record per employee
     */
    public function handle(string $payrollMonth): array
    {
        Gate::authorize(PayrollPermission::DECLARATION_FILE);

        /** @var DipeLayout|null $layout */
        $layout = DipeLayout::query()->where('code', DipeLayout::MAGNETIC_CODE)->first();

        if ($layout === null || ! $layout->is_active || $layout->fields === null || $layout->record_length === null) {
            throw new DipeLayoutUnconfigured(
                'DIPE export is disabled: the byte-level layout (cnps.cm/images/pdf/dipe.pdf) is NEEDS '
                .'VERIFICATION and ships unpopulated (05-hr-payroll 11.4). Populate and activate the '
                ."'".DipeLayout::MAGNETIC_CODE."' DipeLayout from the verified document to enable it."
            );
        }

        $month = Carbon::parse($payrollMonth)->startOfMonth()->toDateString();

        // Snapshot-only reads: items of the month's approved+ runs, joined to
        // their immutable snapshots.
        $snapshots = DB::table('payroll_item_snapshots')
            ->join('payroll_items', 'payroll_items.id', '=', 'payroll_item_snapshots.payroll_item_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->where('payroll_runs.payroll_month', $month)
            ->whereIn('payroll_runs.status', ['approved', 'paid', 'closed'])
            ->orderBy('payroll_items.id')
            ->get(['payroll_item_snapshots.payload', 'payroll_items.id as item_id']);

        $records = [];

        foreach ($snapshots as $row) {
            /** @var array<string, mixed>|null $payload */
            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload)) {
                throw new DomainException("Snapshot of payroll item #{$row->item_id} carries an unreadable payload; DIPE export refuses.");
            }

            if (data_get($payload, 'days_worked') === null) {
                throw new DomainException(
                    "Snapshot of payroll item #{$row->item_id} lacks days_worked; exporting a zero would "
                    .'mis-record CNPS pension quarters, so the export fails instead (05-hr-payroll 11.4).'
                );
            }

            $records[] = $this->renderRecord($layout, $payload);
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderRecord(DipeLayout $layout, array $payload): string
    {
        $record = str_repeat(' ', (int) $layout->record_length);

        /** @var list<array<string, mixed>> $fields */
        $fields = $layout->fields ?? [];

        foreach ($fields as $field) {
            $value = (string) data_get($payload, (string) $field['source'], '');
            $length = (int) $field['length'];
            $pad = (string) ($field['padding'] ?? ' ');
            $align = (string) ($field['alignment'] ?? 'left');

            $value = mb_substr($value, 0, $length);
            $value = $align === 'right'
                ? str_pad($value, $length, $pad, STR_PAD_LEFT)
                : str_pad($value, $length, $pad, STR_PAD_RIGHT);

            // 1-based offset per the layout definition.
            $offset = (int) $field['offset'] - 1;
            $record = substr_replace($record, $value, $offset, $length);
        }

        return $record;
    }
}
