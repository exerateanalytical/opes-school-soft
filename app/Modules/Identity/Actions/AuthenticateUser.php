<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The single place a session is established.
 *
 * Both outcomes are audited. A failed login is the more interesting of the two
 * for an auditor - a run of them is what an intrusion attempt looks like - and
 * neither may ever record the attempted password (00-core 14).
 */
final class AuthenticateUser
{
    /**
     * A valid argon2id hash of a value nobody will guess. Checked when the
     * account does not exist so a missing email and a wrong password take
     * the same time, and timing cannot distinguish them either.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$YWFhYWFhYWFhYWFhYWFhYQ$JIYyDT7bfyEjP5T3AlNvXNVdKzRDgFFtIPaJPqZoQlI';

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(string $email, string $password, bool $remember = false): bool
    {
        $user = User::query()->where('email', $email)->first();

        if (! $this->verify($user, $password, $email)) {
            return false;
        }

        // Narrowing for PHPStan: verify() returns false for a null user, so
        // reaching here proves the row exists.
        if ($user === null) {
            return false;
        }

        Auth::login($user, $remember);
        session()->regenerate();

        $this->audit->handle(
            action: AuditAction::Login,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            actor: $user->toAuditActor(),
        );

        return true;
    }

    /**
     * Does this credential hold - and if not, why, for the auditor only.
     *
     * Extracted so the stateless mobile token endpoint
     * (docs/specs/2026-08-11-guardian-mobile-api-v1.md §2.2) checks a password
     * through the SAME code the session flow uses: same dummy-hash timing
     * defence, same "suspended counts as failure", same audit row. What it
     * deliberately does not do is establish a session - that is handle()'s job
     * and the API has no session to establish.
     *
     * $identifierForAudit is what the caller typed (an email, or a phone that
     * resolved to this user); the audit records the attempt, never the
     * password (00-core §14).
     */
    public function verify(?User $user, string $password, string $identifierForAudit): bool
    {
        $passwordOk = Hash::check($password, $user->password ?? self::DUMMY_HASH);

        if ($user === null || ! $passwordOk || $user->isSuspended()) {
            $this->audit->handle(
                action: AuditAction::LoginFailed,
                module: 'Identity',
                after: ['email' => $identifierForAudit, 'reason' => $this->reason($user, $passwordOk)],
                actor: $user?->toAuditActor(),
            );

            return false;
        }

        return true;
    }

    private function reason(?User $user, bool $passwordOk): string
    {
        if ($user === null) {
            return 'unknown_email';
        }

        return $passwordOk ? 'suspended' : 'wrong_password';
    }
}
