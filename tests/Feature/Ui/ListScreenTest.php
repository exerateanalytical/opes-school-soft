<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @return LengthAwarePaginator<int, int>
 */
function fakePaginator(int $total = 30, int $perPage = 25, int $page = 1): LengthAwarePaginator
{
    $items = collect(range(1, $total))->forPage($page, $perPage)->values();

    return new LengthAwarePaginator($items, $total, $perPage, $page, [
        'path' => '/things',
    ]);
}

it('renders the title and breadcrumb', function () {
    $html = view('components.list-screen', [
        'title' => 'User Management',
        'breadcrumb' => ['Dashboard', 'Users'],
        'paginator' => fakePaginator(),
        'slot' => '<tr><td>row</td></tr>',
    ])->render();

    expect($html)->toContain('User Management')->toContain('Dashboard');
});

it('reports the range and total', function () {
    $html = view('components.list-screen', [
        'title' => 'Users',
        'paginator' => fakePaginator(total: 30, perPage: 25, page: 1),
        'slot' => '<tr><td>row</td></tr>',
    ])->render();

    expect($html)->toContain('1')->toContain('25')->toContain('30');
});

it('shows an empty state instead of a table with no rows', function () {
    // An empty table with headers tells the operator nothing about whether the
    // filter is wrong or the data is genuinely absent.
    $html = view('components.list-screen', [
        'title' => 'Users',
        'paginator' => fakePaginator(total: 0),
        'emptyMessage' => 'No users match these filters.',
        'slot' => '',
    ])->render();

    expect($html)->toContain('No users match these filters.');
});

it('keeps wide content inside its own scroll container', function () {
    // 09-ui section 10: the page body must never scroll horizontally.
    $html = view('components.list-screen', [
        'title' => 'Users',
        'paginator' => fakePaginator(),
        'slot' => '<tr><td>row</td></tr>',
    ])->render();

    expect($html)->toContain('overflow-x-auto');
});

it('renders the reset control whenever filters are present', function () {
    $html = view('components.list-screen', [
        'title' => 'Users',
        'paginator' => fakePaginator(),
        'filters' => '<input name="search">',
        'slot' => '<tr><td>row</td></tr>',
    ])->render();

    expect(strtolower($html))->toContain('reset');
});

it('refuses a bare collection so an unbounded query cannot reach a view', function () {
    // 00-core 6.2 rule 8. The contract is enforced by the component itself so
    // that no caller can violate it by accident.
    $thrown = null;

    try {
        view('components.list-screen', [
            'title' => 'Users',
            'paginator' => collect([1, 2, 3]),
            'slot' => '',
        ])->render();
    } catch (Throwable $e) {
        // Blade wraps anything a view throws in a ViewException.
        $thrown = $e->getPrevious() ?? $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);
    expect($thrown?->getMessage())->toContain('LengthAwarePaginator');
});
