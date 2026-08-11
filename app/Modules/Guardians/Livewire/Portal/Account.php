<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Actions\UpdateOwnContactDetails;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * `/portal/account` - 07-students.md 7.5 row 29, and inseparably row 30.
 *
 * The only place in the whole portal where a guardian writes about themselves.
 * The form offers exactly the fields UpdateOwnContactDetails allows, and the
 * authorization flags (`has_custody`, `receives_reports`, `receives_invoices`,
 * `is_fee_payer`) are ABSENT rather than disabled - row 30 grants them to
 * nobody, because a parent who could edit their own flags could grant
 * themselves every other row in the matrix.
 *
 * The Action enforces that independently, treating an attempt as a security
 * event and auditing it. This screen simply never makes one. Both are needed:
 * a Livewire component's public properties are settable from the browser, so a
 * screen that relied on its own form markup as the control would not have one.
 */
#[Layout('layouts.portal')]
final class Account extends Component
{
    public string $phone = '';

    public string $alternativePhone = '';

    public string $email = '';

    public string $addressLine = '';

    public string $city = '';

    public string $region = '';

    public string $occupation = '';

    public string $employer = '';

    public bool $notifySms = false;

    public bool $notifyEmail = false;

    public bool $notifyPush = false;

    public function mount(): void
    {
        app(GuardianPortalPolicy::class)
            ->authorizeForAnyChild(GuardianCapability::R29EditOwnContactDetails);

        $guardian = PortalContext::current()?->guardian;

        if ($guardian === null) {
            return;
        }

        $this->phone = (string) ($guardian->phone ?? '');
        $this->alternativePhone = (string) ($guardian->alternative_phone ?? '');
        $this->email = (string) ($guardian->email ?? '');
        $this->addressLine = (string) ($guardian->address_line ?? '');
        $this->city = (string) ($guardian->city ?? '');
        $this->region = (string) ($guardian->region ?? '');
        $this->occupation = (string) ($guardian->occupation ?? '');
        $this->employer = (string) ($guardian->employer ?? '');
        $this->notifySms = (bool) $guardian->notify_sms;
        $this->notifyEmail = (bool) $guardian->notify_email;
        $this->notifyPush = (bool) $guardian->notify_push;
    }

    public function save(): void
    {
        $this->validate([
            'phone' => ['required', 'string', 'max:32'],
            'alternativePhone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'employer' => ['nullable', 'string', 'max:160'],
        ]);

        $context = PortalContext::current();

        if ($context === null) {
            return;
        }

        // Re-authorized at WRITE time, not only on mount: a Livewire component
        // survives across wire requests, and a link can expire between the two.
        app(GuardianPortalPolicy::class)
            ->authorizeForAnyChild(GuardianCapability::R29EditOwnContactDetails);

        try {
            app(UpdateOwnContactDetails::class)->handle($context->guardian, [
                'phone' => $this->phone,
                'alternative_phone' => $this->alternativePhone === '' ? null : $this->alternativePhone,
                'email' => $this->email === '' ? null : $this->email,
                'address_line' => $this->addressLine === '' ? null : $this->addressLine,
                'city' => $this->city === '' ? null : $this->city,
                'region' => $this->region === '' ? null : $this->region,
                'occupation' => $this->occupation === '' ? null : $this->occupation,
                'employer' => $this->employer === '' ? null : $this->employer,
                'notify_sms' => $this->notifySms,
                'notify_email' => $this->notifyEmail,
                'notify_push' => $this->notifyPush,
            ], auth()->user()?->toAuditActor());
        } catch (ValidationException $exception) {
            $this->addError('phone', $exception->getMessage());

            return;
        }

        session()->flash('portal-status', __('opes.guardian_portal.account_saved'));
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.account', [
            'guardian' => PortalContext::current()?->guardian,
        ]);
    }
}
