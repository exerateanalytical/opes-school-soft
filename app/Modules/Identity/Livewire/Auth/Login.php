<?php

declare(strict_types=1);

namespace App\Modules\Identity\Livewire\Auth;

use App\Modules\Identity\Actions\AuthenticateUser;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
final class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(AuthenticateUser $authenticate): mixed
    {
        $this->validate();

        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('opes.auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! $authenticate->handle($this->email, $this->password, $this->remember)) {
            RateLimiter::hit($key, 300);
            $this->reset('password');

            // ONE message for every failure mode. Distinguishing them would
            // tell an attacker which emails are real accounts.
            throw ValidationException::withMessages(['email' => __('opes.auth.failed')]);
        }

        RateLimiter::clear($key);

        return $this->redirect('/dashboard', navigate: true);
    }

    private function throttleKey(): string
    {
        return 'login:'.strtolower(trim($this->email)).'|'.request()->ip();
    }

    public function render(): mixed
    {
        return view('livewire.auth.login');
    }
}
