<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Actions\ReversePayrollRun;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollItemSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

require_once __DIR__.'/P11RunHelpers.php';

/*
 * docs/specs/05-hr-payroll.md 10 - THE SNAPSHOT IS AUTHORITATIVE. Written
 * once at approval, self-contained, and INSERT-only: BEFORE UPDATE/DELETE
 * triggers reject unconditionally, even for a reversal.
 */

if (! function_exists('p11snapApprovedRun')) {
    /**
     * @return array{run: App\Modules\Payroll\Models\PayrollRun, item: PayrollItem}
     */
    function p11snapApprovedRun(): array
    {
        $ready = p11runReady();
        $approver = p11runActor();
        p11runStaff(300_000, $ready['user']);

        actingAs($ready['user']);
        $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

        actingAs($approver);
        $run = app(ApprovePayrollRun::class)->handle((int) $run->getKey(), $approver->toAuditActor());

        $item = PayrollItem::query()->where('payroll_run_id', $run->getKey())->firstOrFail();

        return ['run' => $run, 'item' => $item];
    }
}

it('writes exactly one self-contained snapshot per item at approval, with a matching payload_hash', function (): void {
    ['item' => $item] = p11snapApprovedRun();

    $snapshot = PayrollItemSnapshot::query()->where('payroll_item_id', $item->id)->firstOrFail();

    expect($snapshot->snapshot_version)->toBe(1)
        ->and($snapshot->payload_hash)->toBe(hash('sha256', $snapshot->payload));

    $payload = $snapshot->decodedPayload();

    expect($payload)->toHaveKeys(['employer', 'employee', 'period', 'totals', 'lines', 'components'])
        ->and($payload['totals']['net'])->toBe($item->net)
        ->and($payload['totals']['gross'])->toBe($item->gross)
        ->and($payload['lines'])->not->toBeEmpty();

    // Every rate row's AMOUNT COLUMNS are copied, not just the FK - the
    // payload stays readable even if the rate table is migrated (10.2).
    $statutoryLine = collect((array) $payload['lines'])->firstWhere('statutory_rate_id', '!=', null);
    expect($statutoryLine)->not->toBeNull()
        ->and($statutoryLine['statutory_rate']['employer_rate_bp'] ?? $statutoryLine['statutory_rate']['employee_rate_bp'])
        ->not->toBeNull();
});

it('the snapshot stays authoritative even after the live row is mutated downstream', function (): void {
    ['item' => $item] = p11snapApprovedRun();

    $snapshot = PayrollItemSnapshot::query()->where('payroll_item_id', $item->id)->firstOrFail();
    $originalTaxableBase = $snapshot->decodedPayload()['totals']['taxable_base'];

    expect($originalTaxableBase)->toBe($item->taxable_base);

    // Mutate the LIVE row directly - simulating drift, corruption, or a
    // later bug - on a column the `ck_pi_net_identity` CHECK does not
    // constrain. A correct payslip re-render reads the snapshot, never the
    // live row, so it must be unaffected (05-hr-payroll 10, fixing C7).
    DB::table('payroll_items')->where('id', $item->id)->update(['taxable_base' => $item->taxable_base + 999_999]);

    $snapshot->refresh();
    $item->refresh();

    expect($snapshot->decodedPayload()['totals']['taxable_base'])->toBe($originalTaxableBase)
        ->and($snapshot->decodedPayload()['totals']['taxable_base'])->not->toBe($item->taxable_base);
});

it('is INSERT-only: an UPDATE is rejected by the trigger, unconditionally', function (): void {
    ['item' => $item] = p11snapApprovedRun();

    $snapshot = PayrollItemSnapshot::query()->where('payroll_item_id', $item->id)->firstOrFail();

    expect(fn () => DB::table('payroll_item_snapshots')
        ->where('id', $snapshot->id)
        ->update(['payload_hash' => str_repeat('0', 64)]))
        ->toThrow(QueryException::class, 'INSERT-only');
});

it('is INSERT-only: a DELETE is rejected by the trigger, unconditionally', function (): void {
    ['item' => $item] = p11snapApprovedRun();

    $snapshot = PayrollItemSnapshot::query()->where('payroll_item_id', $item->id)->firstOrFail();

    expect(fn () => DB::table('payroll_item_snapshots')->where('id', $snapshot->id)->delete())
        ->toThrow(QueryException::class, 'INSERT-only');
});

it('a reversal cancels the run but the snapshot remains readable, byte-identical, forever', function (): void {
    ['run' => $run, 'item' => $item] = p11snapApprovedRun();

    $snapshot = PayrollItemSnapshot::query()->where('payroll_item_id', $item->id)->firstOrFail();
    $originalPayload = $snapshot->payload;
    $originalHash = $snapshot->payload_hash;

    $reverser = p11runActor();
    actingAs($reverser);

    app(ReversePayrollRun::class)->handle(
        (int) $run->getKey(),
        'Reversal test fixture - snapshot immutability (05-hr-payroll 8.7).',
        $reverser->toAuditActor(),
    );

    $run->refresh();
    expect($run->status->value)->toBe('cancelled');

    // The snapshot itself: untouched, still readable, still hashes the same.
    $snapshot->refresh();
    expect($snapshot->payload)->toBe($originalPayload)
        ->and($snapshot->payload_hash)->toBe($originalHash)
        ->and(PayrollItemSnapshot::query()->where('payroll_item_id', $item->id)->count())->toBe(1);
});
