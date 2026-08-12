<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The entry screens that sit BEFORE a session exists:
 *
 *   splash      mobile/splash-screen.png
 *   welcome     mobile/welcome-onboarding.png
 *   reset       mobile/forgot-password-reset.png
 *   otp         mobile/verify-your-account-otp.png
 *
 * These were skipped for most of this build on the reasoning that "the portal
 * uses the platform's own auth", which was my call to make and the wrong one -
 * the designs include them, so the portal should.
 *
 * Two of them are honest about not existing. There is no password-reset email
 * (the login screen says so already: "This system does not send password
 * emails") and no 2FA (spec §1 non-goals). Rather than build forms that post
 * nowhere, each says what to do instead - ask the office - because a reset form
 * that silently does nothing is worse than a sentence telling the truth.
 *
 * Guest-only: `layouts.guest` is the platform's own pre-auth shell, so these
 * do not drag the portal chrome in front of someone who is not signed in.
 */
#[Layout('layouts.guest')]
final class Entry extends Component
{
    public string $view = 'welcome';

    /** Which onboarding panel is showing. */
    public int $step = 0;

    public function mount(string $view = 'welcome'): void
    {
        $this->view = in_array($view, ['splash', 'welcome', 'reset', 'otp'], true) ? $view : 'welcome';
    }

    public function next(): void
    {
        $this->step = min(2, $this->step + 1);
    }

    public function goTo(int $step): void
    {
        $this->step = max(0, min(2, $step));
    }

    public function render(): mixed
    {
        return view('livewire.guardians.portal.entry');
    }
}
