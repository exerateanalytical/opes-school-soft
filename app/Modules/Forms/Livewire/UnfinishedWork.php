<?php

declare(strict_types=1);

namespace App\Modules\Forms\Livewire;

use App\Modules\Forms\Actions\DiscardDraft;
use App\Modules\Forms\Domain\DraftStatus;
use App\Modules\Forms\Models\FormDraft;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "My unfinished work" - every item the operator has held (started an
 * admission, held it to attend to someone else, hasn't come back yet).
 *
 * No generic resume-anything button: resuming means re-opening the SPECIFIC
 * form that created the draft, which only that form's own route/component
 * knows how to do. `formLabel()` maps `form_key` to a human label and a URL
 * the operator can click through to; a form_key with no mapping still
 * lists (never hidden), just without a working resume link, so a held
 * draft can never silently vanish from this screen.
 */
final class UnfinishedWork extends Component
{
    /**
     * @var array<string, array{label: string, url: string}>
     */
    private const FORM_ROUTES = [
        'admissions.wizard' => ['label' => 'Admission', 'url' => '/admissions'],
        'assessment.homework.create' => ['label' => 'Homework assignment', 'url' => '/homework'],
        'guardians.meetings.schedule' => ['label' => 'Guardian meeting', 'url' => '/guardians/meetings'],
    ];

    public function discard(int $draftId): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        app(DiscardDraft::class)->handle($draftId, (int) $userId);
    }

    public function render(): View
    {
        $userId = Auth::id();

        $held = $userId === null
            ? collect()
            : FormDraft::query()
                ->where('user_id', $userId)
                ->where('status', DraftStatus::Held->value)
                ->orderByDesc('held_at')
                ->get()
                ->map(fn (FormDraft $draft): array => [
                    'id' => $draft->getKey(),
                    'label' => $draft->hold_label ?? (self::FORM_ROUTES[$draft->form_key]['label'] ?? $draft->form_key),
                    'url' => self::FORM_ROUTES[$draft->form_key]['url'] ?? null,
                    'held_at' => $draft->held_at,
                ]);

        return view('livewire.forms.unfinished-work', ['held' => $held]);
    }
}
