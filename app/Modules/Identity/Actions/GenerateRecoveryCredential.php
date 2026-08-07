<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Models\RecoveryCredential;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class GenerateRecoveryCredential
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * Returns the PLAINTEXT code. It is never stored and never returned again -
     * the caller must show it to the operator once, for the recovery sheet.
     */
    public function handle(User $generatedBy): string
    {
        $code = RecoveryCode::generate();

        DB::transaction(function () use ($code, $generatedBy): void {
            // Single-active: generating a new credential revokes the old one,
            // so a code written down last year cannot still open the door.
            RecoveryCredential::query()
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            RecoveryCredential::query()->create([
                'code_hash' => Hash::make($code->normalised()),
                'generated_by' => $generatedBy->getKey(),
                'expires_at' => now()->addMonths(12),
            ]);

            // No before/after payload: the code must never reach the audit log.
            $this->audit->handle(
                action: AuditAction::RecoveryGenerated,
                module: 'Identity',
                actor: $generatedBy->toAuditActor(),
            );
        });

        return $code->formatted();
    }
}
