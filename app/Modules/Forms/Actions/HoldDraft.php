<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Domain\DraftStatus;
use App\Modules\Forms\Models\FormDraft;
use DomainException;

/**
 * The "hold this and come back later" act - the POS-style hold-order this
 * whole subsystem is modelled on. Parking a half-finished admission means
 * it stops being invisible autosave and starts being a first-class item on
 * the operator's "unfinished work" list.
 */
final class HoldDraft
{
    public function handle(int $draftId, int $userId, ?string $label = null): FormDraft
    {
        /** @var FormDraft $draft */
        $draft = FormDraft::query()->findOrFail($draftId);

        if ($draft->user_id !== $userId) {
            throw new DomainException('This draft does not belong to you.');
        }

        $draft->forceFill([
            'status' => DraftStatus::Held->value,
            'held_at' => now(),
            'hold_label' => $label,
        ])->save();

        return $draft->refresh();
    }
}
