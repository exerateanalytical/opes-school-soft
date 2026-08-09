<?php

declare(strict_types=1);

use App\Modules\Library\Actions\AddBookCopies;
use App\Modules\Library\Actions\RecordBookAcquisition;
use App\Modules\Library\Actions\RegisterBook;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\Book;
use App\Modules\Library\Models\BookCategory;
use App\Modules\Library\Models\ShelfLocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LibraryTestHelpers.php';

uses(RefreshDatabase::class);

it('registers a title and derives availability from copies, never a stored counter', function (): void {
    $user = phase9LibLibrarian();
    $catalog = phase9LibCatalog($user, copies: 3);

    /** @var Book $book */
    $book = $catalog['book'];

    expect($book->availableCopies())->toBe(3)
        ->and($catalog['copies'])->toHaveCount(3)
        ->and($catalog['copies'][0]->accession_no)->toMatch('/^ACC\d{6}$/')
        ->and($catalog['copies'][0]->barcode)->toBe($catalog['copies'][0]->accession_no);

    // Accession numbers are sequence-allocated and distinct.
    $accessions = array_map(static fn ($c): string => $c->accession_no, $catalog['copies']);
    expect(array_unique($accessions))->toHaveCount(3);

    // Availability follows copy status, not a counter.
    $catalog['copies'][0]->forceFill(['status' => 'lost'])->save();
    expect($book->availableCopies())->toBe(2);
});

it('refuses a book without title or author and rejects archived categories', function (): void {
    $user = phase9LibLibrarian();
    $category = BookCategory::factory()->create();

    expect(fn () => app(RegisterBook::class)->handle(null, [
        'title' => '  ',
        'author' => 'Someone',
        'book_category_id' => (int) $category->getKey(),
    ], phase9LibActor($user)))->toThrow(Illuminate\Validation\ValidationException::class);

    $archived = BookCategory::factory()->create(['is_archived' => true]);

    expect(fn () => app(RegisterBook::class)->handle(null, [
        'title' => 'A title',
        'author' => 'Someone',
        'book_category_id' => (int) $archived->getKey(),
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'active book category');
});

it('enforces ISBN uniqueness at the database', function (): void {
    $user = phase9LibLibrarian();
    $catalog = phase9LibCatalog($user, copies: 0);

    expect(fn () => Book::query()->create([
        'isbn' => $catalog['book']->isbn,
        'title' => 'Duplicate ISBN',
        'author' => 'X',
        'book_category_id' => $catalog['book']->book_category_id,
    ]))->toThrow(QueryException::class);
});

it('denies catalogue management without library.manage', function (): void {
    $user = phase9LibUser(LibraryPermission::VIEW);
    $category = BookCategory::factory()->create();

    expect(fn () => app(RegisterBook::class)->handle(null, [
        'title' => 'Forbidden',
        'author' => 'X',
        'book_category_id' => (int) $category->getKey(),
    ], phase9LibActor($user)))->toThrow(AuthorizationException::class);
});

it('records an expensed acquisition batch: copies stamped, NOTHING posted, replay idempotent', function (): void {
    $user = phase9LibLibrarian();
    $catalog = phase9LibCatalog($user, copies: 0);
    $shelf = ShelfLocation::factory()->create();

    $data = [
        'reference' => 'ACQ/2031/0001',
        'acquired_on' => '2031-02-01',
        'source' => 'purchase',
        'lines' => [
            [
                'book_id' => (int) $catalog['book']->getKey(),
                'shelf_location_id' => (int) $shelf->getKey(),
                'count' => 10,
                'unit_cost' => 4_500,
            ],
        ],
        'idempotency_key' => 'p9f4-acq-1',
    ];

    $entriesBefore = (int) DB::table('journal_entries')->count();

    $acquisition = app(RecordBookAcquisition::class)->handle($data, phase9LibActor($user));

    expect($acquisition->total_cost)->toBe(45_000)
        ->and($acquisition->copy_count)->toBe(10)
        ->and($acquisition->journal_entry_id)->toBeNull()
        ->and($acquisition->asset_id)->toBeNull()
        ->and($acquisition->copies()->count())->toBe(10)
        // §10.8 expensed: the batch posts NOTHING here.
        ->and((int) DB::table('journal_entries')->count())->toBe($entriesBefore);

    // Idempotent replay returns the same batch, stamps nothing twice.
    $replay = app(RecordBookAcquisition::class)->handle($data, phase9LibActor($user));

    expect((int) $replay->getKey())->toBe((int) $acquisition->getKey())
        ->and($acquisition->copies()->count())->toBe(10);
});

it('hard-gates the capitalised policy on V17 rather than guessing an account', function (): void {
    $user = phase9LibLibrarian();
    $catalog = phase9LibCatalog($user, copies: 0);
    $shelf = ShelfLocation::factory()->create();

    DB::table('settings')->insert([
        'key' => 'library.capitalisation_policy',
        'value' => json_encode('capitalised'),
        'value_type' => 'string',
        'setting_class' => 'general',
        'scope' => 'global',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(RecordBookAcquisition::class)->handle([
        'reference' => 'ACQ/2031/0002',
        'acquired_on' => '2031-02-01',
        'source' => 'donation',
        'lines' => [
            [
                'book_id' => (int) $catalog['book']->getKey(),
                'shelf_location_id' => (int) $shelf->getKey(),
                'count' => 1,
            ],
        ],
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'V17');
});
