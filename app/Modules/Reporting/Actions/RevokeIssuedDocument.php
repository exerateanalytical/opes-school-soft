<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Models\IssuedDocument;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/10-documents.md 17.2 / 19 - `documents.revoke`: the issued
 * paper is out in the world, so the row is never deleted; its status flips
 * to `revoked` with actor and reason, the verification token verifies as
 * REVOKED immediately, and every later render of it carries the ANNULE/VOID
 * overlay (RenderDocument reads the status inside its transaction).
 */
final class RevokeIssuedDocument
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $issuedDocumentId, string $reason): IssuedDocument
    {
        Gate::authorize(Permission::DocumentsRevoke->value);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Revoking an issued document requires a reason; an unexplained revocation on a '
                    .'permanent record is worse than none (10-documents 17.2).',
            ]);
        }

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use ($issuedDocumentId, $reason, $actor): IssuedDocument {
            /** @var IssuedDocument $document */
            $document = IssuedDocument::query()->lockForUpdate()->findOrFail($issuedDocumentId);

            if ($document->status !== IssuedDocument::STATUS_VALID) {
                throw new DomainException(sprintf(
                    'Issued document %s is already %s; only a valid document can be revoked.',
                    (string) ($document->serial ?? $document->getKey()),
                    $document->status,
                ));
            }

            $document->status = IssuedDocument::STATUS_REVOKED;
            $document->revoked_by = $actor->id;
            $document->revoked_at = Carbon::now();
            $document->revoked_reason = $reason;
            $document->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Reporting',
                auditableType: IssuedDocument::class,
                auditableId: (int) $document->getKey(),
                after: [
                    'status' => IssuedDocument::STATUS_REVOKED,
                    'serial' => $document->serial,
                    'revoked_reason' => $reason,
                ],
                actor: $actor,
            );

            return $document;
        });
    }
}
