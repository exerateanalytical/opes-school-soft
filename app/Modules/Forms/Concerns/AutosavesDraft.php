<?php

declare(strict_types=1);

namespace App\Modules\Forms\Concerns;

use App\Modules\Forms\Actions\DiscardDraft;
use App\Modules\Forms\Actions\HoldDraft;
use App\Modules\Forms\Actions\ResumeDraft;
use App\Modules\Forms\Actions\SaveDraft;
use App\Modules\Forms\Domain\DraftStatus;
use App\Modules\Forms\Models\FormDraft;
use Illuminate\Support\Facades\Auth;

/**
 * The autosave + hold/resume behaviour behind every popup form.
 *
 * Deliberately NOT wired through Livewire's `mount{Trait}`/`updated{Trait}`
 * magic-hook convention: that convention's survival across Livewire major
 * versions is not something this codebase can verify without a live
 * browser test, and a silently-not-firing autosave is a much worse failure
 * mode than one extra explicit line per component. A host component calls
 * these methods itself:
 *
 *   public function mount(): void
 *   {
 *       $this->initializeAutosave();          // repopulates from a live draft, if any
 *   }
 *
 *   public function updated($property): void
 *   {
 *       $this->autosave();                    // called on every wire:model.live change
 *   }
 *
 *   public function save(): void
 *   {
 *       // ... create/update the real record ...
 *       $this->clearDraft();                  // a completed form has nothing left to resume
 *   }
 *
 * The host component must implement `formKey(): string` and
 * `draftableState(): array` (the public properties to persist) and may
 * override `draftSubjectType()`/`draftSubjectId()` for an edit form.
 */
trait AutosavesDraft
{
    public ?int $currentDraftId = null;

    public bool $resumedFromDraft = false;

    public string $lastAutosavedAt = '';

    abstract public function formKey(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function draftableState(): array;

    protected function draftSubjectType(): ?string
    {
        return null;
    }

    protected function draftSubjectId(): ?int
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyDraftPayload(array $payload): void
    {
        foreach ($payload as $property => $value) {
            if (property_exists($this, $property)) {
                $this->{$property} = $value;
            }
        }
    }

    public function initializeAutosave(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $existing = app(ResumeDraft::class)->findFor(
            (int) $userId,
            $this->formKey(),
            $this->draftSubjectType(),
            $this->draftSubjectId(),
        );

        if ($existing === null) {
            return;
        }

        $this->applyDraftPayload($existing->payload);
        $this->currentDraftId = (int) $existing->getKey();
        $this->resumedFromDraft = true;
    }

    public function autosave(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $draft = app(SaveDraft::class)->handle(
            (int) $userId,
            $this->formKey(),
            $this->draftableState(),
            $this->draftSubjectType(),
            $this->draftSubjectId(),
        );

        $this->currentDraftId = (int) $draft->getKey();
        $this->lastAutosavedAt = now()->format('H:i:s');
    }

    public function holdCurrentDraft(?string $label = null): void
    {
        $userId = Auth::id();

        if ($userId === null || $this->currentDraftId === null) {
            $this->autosave();
        }

        if ($this->currentDraftId === null) {
            return;
        }

        app(HoldDraft::class)->handle((int) $this->currentDraftId, (int) $userId, $label);
    }

    public function clearDraft(): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        if ($this->currentDraftId !== null) {
            app(DiscardDraft::class)->handle((int) $this->currentDraftId, (int) $userId);
        } else {
            app(DiscardDraft::class)->discardFor(
                (int) $userId,
                $this->formKey(),
                $this->draftSubjectType(),
                $this->draftSubjectId(),
            );
        }

        $this->currentDraftId = null;
        $this->resumedFromDraft = false;
    }

    protected function isCurrentDraftHeld(): bool
    {
        if ($this->currentDraftId === null) {
            return false;
        }

        $draft = FormDraft::query()->find($this->currentDraftId);

        return $draft !== null && $draft->status === DraftStatus::Held;
    }
}
