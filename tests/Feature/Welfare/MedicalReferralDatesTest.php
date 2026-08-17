<?php

declare(strict_types=1);

use App\Modules\Welfare\Livewire\Medical\Index;
use App\Modules\Welfare\Models\MedicalReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/MedicalTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * RecordReferral takes a `referredOn` and CloseReferral a `followedUpAt`, both
 * required parameters the panels used to hardcode to "today"/"now". A referral
 * written up the morning after the consultation, or a follow-up recorded a few
 * days late, was permanently mis-dated - and CloseReferral's guard comparing
 * the follow-up against `referred_on` could never trip.
 */

it('defaults the referral date to today when the panel opens', function (): void {
    $user = p10MedicalNurse();
    $consultation = p10MedicalConsultation($user, p10MedicalStudentId());

    Livewire::test(Index::class)
        ->call('startReferral', $consultation->getKey())
        ->assertSet('referOn', now()->toDateString());
});

it('records the referral on the date the panel collects', function (): void {
    $user = p10MedicalNurse();
    $consultation = p10MedicalConsultation($user, p10MedicalStudentId());

    Livewire::test(Index::class)
        ->call('startReferral', $consultation->getKey())
        ->set('referTo', 'Bamenda Regional Hospital')
        ->set('referReason', 'Persistent fever, needs bloodwork')
        ->set('referOn', now()->subDay()->toDateString())
        ->call('saveReferral')
        ->assertHasNoErrors();

    $referral = MedicalReferral::query()->firstOrFail();

    expect($referral->referred_on->toDateString())->toBe(now()->subDay()->toDateString());
});

it('defaults the follow-up date to today when the close panel opens', function (): void {
    $user = p10MedicalNurse();
    $consultation = p10MedicalConsultation($user, p10MedicalStudentId());

    $component = Livewire::test(Index::class)
        ->call('startReferral', $consultation->getKey())
        ->set('referTo', 'Bamenda Regional Hospital')
        ->set('referReason', 'Persistent fever, needs bloodwork')
        ->call('saveReferral');

    $referral = MedicalReferral::query()->firstOrFail();

    $component->call('startClose', $referral->getKey())
        ->assertSet('closeFollowedUpOn', now()->toDateString());
});

it('closes the referral on the follow-up date the panel collects', function (): void {
    $user = p10MedicalNurse();
    $consultation = p10MedicalConsultation($user, p10MedicalStudentId());

    $component = Livewire::test(Index::class)
        ->call('startReferral', $consultation->getKey())
        ->set('referTo', 'Bamenda Regional Hospital')
        ->set('referReason', 'Persistent fever, needs bloodwork')
        ->set('referOn', now()->subDays(3)->toDateString())
        ->call('saveReferral')
        ->assertHasNoErrors();

    $referral = MedicalReferral::query()->firstOrFail();

    $component->call('startClose', $referral->getKey())
        ->set('closeFollowedUpOn', now()->subDay()->toDateString())
        ->set('closeNotes', 'Seen at the regional hospital, discharged')
        ->call('confirmClose')
        ->assertHasNoErrors();

    $referral->refresh();

    expect($referral->followed_up_at)->not->toBeNull()
        ->and($referral->followed_up_at->toDateString())->toBe(now()->subDay()->toDateString());
});
