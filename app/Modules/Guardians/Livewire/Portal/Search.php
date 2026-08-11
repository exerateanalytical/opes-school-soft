<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Support\Portal\GuardianSearch;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * `/portal/search` - the same search the mobile app has.
 *
 * The screen holds a query string and renders a list. Every decision about
 * WHAT may be searched belongs to GuardianSearch, which the API's controller
 * also calls - deliberately, because a second implementation of search
 * scoping would be a hole no test of the first could catch.
 */
#[Layout('layouts.portal')]
final class Search extends Component
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    public function render(): mixed
    {
        $term = trim($this->query);
        $tooShort = $term !== '' && mb_strlen($term) < GuardianSearch::MIN_LENGTH;

        return view('livewire.guardians.portal.search', [
            'term' => $term,
            'tooShort' => $tooShort,
            'results' => $term === '' || $tooShort ? [] : app(GuardianSearch::class)->search($term),
        ]);
    }
}
