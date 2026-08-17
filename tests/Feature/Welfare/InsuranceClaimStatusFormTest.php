<?php

declare(strict_types=1);

use App\Modules\Welfare\Domain\ClaimStatus;
use App\Modules\Welfare\Livewire\Insurance\Index;
use App\Modules\Welfare\Models\InsuranceClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/InsuranceTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * RecordClaim takes a ClaimStatus and documents Draft as the way to hold a
 * claim back before it goes to the insurer, but the record-claim form never
 * passed one - so every UI claim was Submitted, the Draft status filter could
 * never match a row, and the rail's Draft counter was permanently zero.
 */

it('records a claim as submitted by default', function (): void {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    Livewire::test(Index::class)
        ->call('toggleClaimForm')
        ->assertSet('claimStatus', 'submitted')
        ->set('claimPolicyId', (string) $policy->getKey())
        ->set('claimIncidentDate', '2026-10-04')
        ->set('claimDescription', 'Broken arm during games period')
        ->set('claimAmount', '75000')
        ->call('saveClaim')
        ->assertHasNoErrors();

    expect(InsuranceClaim::query()->firstOrFail()->status)->toBe(ClaimStatus::Submitted);
});

it('holds a claim back as a draft when the form says draft', function (): void {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    Livewire::test(Index::class)
        ->call('toggleClaimForm')
        ->set('claimPolicyId', (string) $policy->getKey())
        ->set('claimIncidentDate', '2026-10-04')
        ->set('claimDescription', 'Broken arm during games period')
        ->set('claimAmount', '75000')
        ->set('claimStatus', 'draft')
        ->call('saveClaim')
        ->assertHasNoErrors();

    expect(InsuranceClaim::query()->firstOrFail()->status)->toBe(ClaimStatus::Draft);
});

it('refuses a claim status the Action would not accept from this form', function (): void {
    $user = p10InsManager();
    $policy = p10InsPolicy($user);

    Livewire::test(Index::class)
        ->call('toggleClaimForm')
        ->set('claimPolicyId', (string) $policy->getKey())
        ->set('claimIncidentDate', '2026-10-04')
        ->set('claimDescription', 'Broken arm during games period')
        ->set('claimAmount', '75000')
        ->set('claimStatus', 'settled')
        ->call('saveClaim')
        ->assertHasErrors(['claimStatus']);

    expect(InsuranceClaim::query()->count())->toBe(0);
});
