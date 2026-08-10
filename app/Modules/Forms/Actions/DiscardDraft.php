<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Models\FormDraft;
use DomainException;

/**
 * Deletes a draft - called either when the user explicitly discards it, or
 * by the form itself the moment the real record is successfully saved (a
 * completed form has nothing left to resume).
 */
final class DiscardDraft
{
    public function handle(int $draftId, int $userId): void
    {
        /** @var FormDraft $draft */
        $draft = FormDraft::query()->findOrFail($draftId);

        if ($draft->user_id !== $userId) {
            throw new DomainException('This draft does not belong to you.');
        }

        $draft->delete();
    }

    public function discardFor(int $userId, string $formKey, ?string $subjectType = null, ?int $subjectId = null): void
    {
        FormDraft::query()
            ->where('user_id', $userId)
            ->where('form_key', $formKey)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->delete();
    }
}
