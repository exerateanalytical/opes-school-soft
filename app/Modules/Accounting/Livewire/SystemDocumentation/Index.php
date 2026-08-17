<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\SystemDocumentation;

use App\Modules\Accounting\Actions\GenerateSystemDocumentation;
use App\Modules\Accounting\Models\SystemDocumentationSnapshot;
use Illuminate\View\View;
use Livewire\Component;

/**
 * AUDCIF §14.4: the generated "documentation du système comptable".
 * Read-and-generate only, same as the statutory books screen - a
 * correction means generating a NEW snapshot that supersedes the last one.
 */
final class Index extends Component
{
    /**
     * How many hex characters of the sha256 the screen shows. ONE constant,
     * because the generate() banner and the table column show a prefix of the
     * SAME hash - two different lengths read to an operator as two different
     * hashes.
     */
    public const HASH_PREFIX = 16;

    /** Newest snapshots listed. The table states this cap on screen. */
    public const LIST_LIMIT = 20;

    public string $message = '';

    public string $error = '';

    public function generate(): void
    {
        $this->message = '';
        $this->error = '';

        try {
            $snapshot = app(GenerateSystemDocumentation::class)->handle();
            $this->message = sprintf(
                '%s — sha256 %s',
                __('opes.system_doc_screen.generated'),
                substr($snapshot->sha256, 0, self::HASH_PREFIX),
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.accounting.system-documentation.index', [
            'snapshots' => SystemDocumentationSnapshot::query()->orderByDesc('id')->limit(self::LIST_LIMIT)->get(),
            'snapshotTotal' => SystemDocumentationSnapshot::query()->count(),
            'listLimit' => self::LIST_LIMIT,
            'hashPrefix' => self::HASH_PREFIX,
        ]);
    }
}
