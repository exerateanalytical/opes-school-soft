<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Livewire\GlobalSearch;

use App\Modules\Reporting\Actions\Search\SearchThePlatform;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The shell header's search box. Present on every authenticated screen
 * (layouts/app.blade.php), the same way the notification bell is.
 */
final class Index extends Component
{
    public string $query = '';

    public bool $open = false;

    public function updatedQuery(): void
    {
        $this->open = mb_strlen(trim($this->query)) >= 2;
    }

    public function render(): View
    {
        $results = $this->open
            ? app(SearchThePlatform::class)->handle($this->query)
            : [];

        return view('livewire.reporting.global-search.index', ['results' => $results]);
    }
}
