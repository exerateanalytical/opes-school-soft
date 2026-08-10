<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Domain\DraftStatus;
use App\Modules\Forms\Models\FormDraft;

/**
 * The autosave write: upserts on (user, form_key, subject) so every
 * keystroke-debounce overwrites the SAME row rather than growing a table of
 * throwaway snapshots. Never touches a row already `held` - autosave must
 * not silently downgrade a deliberate hold back to a quiet draft, or the
 * user's "I parked this on purpose" signal would be erased by their own
 * typing the next time they open it.
 */
final class SaveDraft
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        int $userId,
        string $formKey,
        array $payload,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): FormDraft {
        $existing = FormDraft::query()
            ->where('user_id', $userId)
            ->where('form_key', $formKey)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first();

        if ($existing !== null && $existing->status === DraftStatus::Held) {
            $existing->forceFill(['payload' => $payload])->save();

            return $existing;
        }

        return FormDraft::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'form_key' => $formKey,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ],
            ['payload' => $payload, 'status' => DraftStatus::Draft->value],
        );
    }
}
