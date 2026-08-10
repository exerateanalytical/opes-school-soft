<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Models\FormDraft;
use DomainException;

/**
 * Reads a draft back out for the form to repopulate itself from. Read-only:
 * resuming does not clear `held` or touch `updated_at` on its own - a form
 * that loads a draft and is then closed without saving should not have
 * silently un-held it.
 */
final class ResumeDraft
{
    public function handle(int $draftId, int $userId): FormDraft
    {
        /** @var FormDraft $draft */
        $draft = FormDraft::query()->findOrFail($draftId);

        if ($draft->user_id !== $userId) {
            throw new DomainException('This draft does not belong to you.');
        }

        return $draft;
    }

    /**
     * The lookup a form actually calls on mount: "is there a live draft for
     * ME, this FORM, and this SUBJECT (or none, for a brand-new record)".
     */
    public function findFor(int $userId, string $formKey, ?string $subjectType = null, ?int $subjectId = null): ?FormDraft
    {
        return FormDraft::query()
            ->where('user_id', $userId)
            ->where('form_key', $formKey)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first();
    }
}
