<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Models\DocumentSigningKey;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md 17.1 - key rotation: retire the active signing
 * key and activate a fresh P-256 pair, in ONE transaction so there is never
 * a moment with two active keys or none.
 *
 * Retirement stops SIGNING, never verification: the retired row keeps its
 * public key forever, and VerifyDocumentQrToken looks keys up by the
 * token's `k` field with no is_active filter - a certificate printed under
 * key 1 still verifies after ten rotations.
 *
 * Gated on documents.template_manage: the rotation changes what every future
 * document carries, the same blast radius as editing a template.
 */
final class RotateDocumentSigningKey
{
    public function __construct(
        private readonly EnsureActiveSigningKey $keys,
        private readonly WriteAuditEntry $audit,
    ) {
    }

    public function handle(Actor $actor): DocumentSigningKey
    {
        Gate::authorize(Permission::DocumentsTemplateManage->value);

        return DB::transaction(function () use ($actor): DocumentSigningKey {
            /** @var DocumentSigningKey|null $current */
            $current = DocumentSigningKey::query()
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($current !== null) {
                $current->is_active = false;
                $current->retired_at = now();
                $current->save();
            }

            $fresh = $this->keys->generate($actor);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Reporting',
                auditableType: DocumentSigningKey::class,
                auditableId: (int) $fresh->getKey(),
                before: $current === null ? null : ['retired_key_id' => $current->key_id],
                after: ['key_id' => $fresh->key_id, 'algorithm' => $fresh->algorithm],
                actor: $actor,
            );

            return $fresh;
        });
    }
}
