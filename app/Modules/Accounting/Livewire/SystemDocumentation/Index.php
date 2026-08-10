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
                substr($snapshot->sha256, 0, 12),
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.accounting.system-documentation.index', [
            'snapshots' => SystemDocumentationSnapshot::query()->orderByDesc('id')->limit(20)->get(),
        ]);
    }
}
