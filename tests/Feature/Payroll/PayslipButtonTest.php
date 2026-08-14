<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Livewire\Show;
use App\Modules\Payroll\Models\PayrollItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/P11RunHelpers.php';

uses(RefreshDatabase::class);

/**
 * `/payroll/payslips/{id}/print` answers 422 for a cancelled item, and that
 * refusal is CORRECT - PrintPayslip checks payroll_items.is_cancelled and a
 * cancelled line has no payslip to issue. The bug is the run screen offering
 * the control anyway: the only guard was PrintPayslip::isIssuable($run),
 * which is a property of the RUN, so every item on an approved run got a
 * Print link including the cancelled ones. An operator who clicks it reads
 * the 422 as a platform fault.
 */
it('does not offer a payslip control on a cancelled payroll item', function (): void {
    $ready = p11runReady();
    p11runStaff(300_000, $ready['user']);

    actingAs($ready['user']);
    $run = app(CalculatePayrollRun::class)->handle('2031-01-01', RunType::Regular, $ready['user']->toAuditActor());

    $approver = p11runActor([App\Modules\Payroll\Domain\PayrollPermission::VIEW]);
    actingAs($approver);
    $run = app(ApprovePayrollRun::class)->handle((int) $run->getKey(), $approver->toAuditActor());

    $item = PayrollItem::query()->where('payroll_run_id', $run->getKey())->firstOrFail();

    // Sanity: while the item is live the run screen DOES offer the control,
    // so the assertion below is about cancellation and not about the screen
    // simply never rendering a link.
    Livewire::test(Show::class, ['run' => $run])
        ->assertSeeHtml('/payroll/payslips/'.$item->id.'/print');

    DB::table('payroll_items')->where('id', $item->id)->update(['is_cancelled' => true]);

    Livewire::test(Show::class, ['run' => $run])
        ->assertDontSeeHtml('/payroll/payslips/'.$item->id.'/print');
});
