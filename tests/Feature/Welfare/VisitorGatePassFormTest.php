<?php

declare(strict_types=1);

use App\Modules\Welfare\Livewire\Visitors\Index;
use App\Modules\Welfare\Models\VisitorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/VisitorTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * CheckInVisitor and CheckOutVisitor have both always accepted an optional
 * gate pass number and written it to `visitor_logs.gate_pass_no`, but neither
 * the check-in form nor the one-click check-out ever collected one - so the
 * column was permanently NULL for every row created through the screen.
 */

it('saves the gate pass number entered on the check-in form', function (): void {
    p10VisitorFrontDesk();

    Livewire::test(Index::class)
        ->set('showForm', true)
        ->set('formName', 'Ngwa Franklin')
        ->set('formPhone', '677000000')
        ->set('formPurpose', 'Fee payment enquiry')
        ->set('formHostType', 'office')
        ->set('formBadge', 'V-01')
        ->set('formGatePass', 'GP-2026-001')
        ->call('saveCheckIn')
        ->assertHasNoErrors();

    expect(VisitorLog::query()->firstOrFail()->gate_pass_no)->toBe('GP-2026-001');
});

it('leaves the gate pass null when the field is left blank', function (): void {
    p10VisitorFrontDesk();

    Livewire::test(Index::class)
        ->set('showForm', true)
        ->set('formName', 'Ngwa Franklin')
        ->set('formPhone', '677000000')
        ->set('formPurpose', 'Fee payment enquiry')
        ->set('formHostType', 'office')
        ->set('formBadge', 'V-02')
        ->call('saveCheckIn')
        ->assertHasNoErrors();

    expect(VisitorLog::query()->firstOrFail()->gate_pass_no)->toBeNull();
});

it('records a gate pass issued at the barrier on the way out', function (): void {
    $user = p10VisitorFrontDesk();

    $log = p10VisitorCheckIn($user, badge: 'V-03');

    Livewire::test(Index::class)
        ->set('checkoutGatePass', [$log->getKey() => 'GP-EXIT-9'])
        ->call('checkOut', $log->getKey())
        ->assertHasNoErrors();

    $log->refresh();

    expect($log->gate_pass_no)->toBe('GP-EXIT-9')
        ->and($log->checked_out_at)->not->toBeNull();
});

it('keeps the check-in gate pass when none is typed at check-out', function (): void {
    $user = p10VisitorFrontDesk();

    $log = app(App\Modules\Welfare\Actions\CheckInVisitor::class)->handle(
        'Ngwa Franklin',
        '677000000',
        null,
        'Fee payment enquiry',
        App\Modules\Welfare\Domain\VisitorHostType::Office,
        null,
        'V-04',
        Illuminate\Support\Carbon::now(),
        p10VisitorActor($user),
        'GP-ENTRY-1',
    );

    Livewire::test(Index::class)
        ->call('checkOut', $log->getKey())
        ->assertHasNoErrors();

    expect($log->refresh()->gate_pass_no)->toBe('GP-ENTRY-1');
});
