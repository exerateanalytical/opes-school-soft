<?php

declare(strict_types=1);

use App\Support\Sequence\SequenceAllocator;
use App\Support\Sequence\SequenceOutsideTransactionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// tests/Pest.php binds Tests\TestCase to the Feature suite only, so this file
// declares it: the allocator's entire contract is about database locking and
// cannot be exercised against a mock without testing the mock instead.
//
// RefreshDatabase is deliberately NOT used, and its absence is the whole
// design of this file. RefreshDatabase wraps every test in an open
// transaction, which would break the two assertions that matter most:
//   - "refuses to allocate outside a transaction" could never see
//     transactionLevel() = 0; and
//   - the concurrency test needs a SECOND connection to block on a committed
//     row, which it cannot see from inside another connection's uncommitted
//     transaction.
// So these tests commit for real and clean up the one table they touch.
uses(Tests\TestCase::class);

beforeEach(function (): void {
    // Standalone runs (--filter, or this file first in the suite) may reach
    // here before any RefreshDatabase file has built the schema.
    if (! Schema::hasTable('sequences')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::table('sequences')->delete();
});

it('starts a new series at 1 and creates its row on first use', function () {
    $first = DB::transaction(fn (): int => app(SequenceAllocator::class)->allocate('matricule.2026.SEC1'));

    expect($first)->toBe(1);
    expect(DB::table('sequences')->where('series', 'matricule.2026.SEC1')->value('next_value'))->toEqual(2);
});

it('hands out consecutive numbers within a series', function () {
    $allocator = app(SequenceAllocator::class);

    $numbers = DB::transaction(static function () use ($allocator): array {
        return [
            $allocator->allocate('admission_no.2026'),
            $allocator->allocate('admission_no.2026'),
            $allocator->allocate('admission_no.2026'),
        ];
    });

    expect($numbers)->toBe([1, 2, 3]);
});

it('keeps series independent of one another', function () {
    // 00-core 12: uniqueness scope is stated per series, in the key itself.
    // A 2027 matricule must not continue 2026's counter, and a section's
    // counter must not continue another section's.
    $allocator = app(SequenceAllocator::class);

    [$a, $b, $c] = DB::transaction(static fn (): array => [
        $allocator->allocate('matricule.2026.SEC1'),
        $allocator->allocate('matricule.2027.SEC1'),
        $allocator->allocate('matricule.2026.SEC2'),
    ]);

    expect([$a, $b, $c])->toBe([1, 1, 1]);
});

it('reserves a block and returns the first number of it', function () {
    $allocator = app(SequenceAllocator::class);

    [$first, $next] = DB::transaction(static fn (): array => [
        $allocator->allocate('bulk.import', 50),
        $allocator->allocate('bulk.import'),
    ]);

    expect($first)->toBe(1);
    // The caller owns 1..50, so the following single allocation must be 51 -
    // an import that reserved a block and then found the next caller inside
    // it would produce duplicate numbers.
    expect($next)->toBe(51);
});

it('consumes no number when the transaction rolls back', function () {
    // Spec-mandated (00-core 12): the gapless obligation on
    // JournalEntry.piece_no is only real if a rolled-back posting leaves the
    // counter untouched. This is the test the spec names.
    $allocator = app(SequenceAllocator::class);

    $before = DB::transaction(fn (): int => $allocator->allocate('piece_no.2026.JV'));
    expect($before)->toBe(1);

    try {
        DB::transaction(static function () use ($allocator): void {
            $allocator->allocate('piece_no.2026.JV');
            $allocator->allocate('piece_no.2026.JV');

            throw new RuntimeException('posting failed after the number was taken');
        });
    } catch (RuntimeException) {
        // expected
    }

    // 2 and 3 were handed out and then rolled back with everything else, so
    // the next real posting must still get 2. Under a max()+1 scheme or an
    // out-of-transaction counter it would get 4 and the series would have a
    // hole that OHADA AUDCIF Art. 19 does not permit.
    $after = DB::transaction(fn (): int => $allocator->allocate('piece_no.2026.JV'));
    expect($after)->toBe(2);
});

it('refuses to allocate outside a transaction', function () {
    // Allocating with no open transaction releases the row lock the moment the
    // UPDATE returns, so two callers can read the same next_value - and the
    // number is burned even if the work rolls back. A loud failure beats a
    // duplicate matricule discovered a term later.
    expect(DB::transactionLevel())->toBe(0);

    app(SequenceAllocator::class)->allocate('matricule.2026.SEC1');
})->throws(SequenceOutsideTransactionException::class);

it('rejects a non-positive count', function () {
    DB::transaction(fn (): int => app(SequenceAllocator::class)->allocate('matricule.2026.SEC1', 0));
})->throws(InvalidArgumentException::class);

it('serialises concurrent allocators on the sequence row', function () {
    // Two real connections, not two calls on one. Connection A takes the row
    // lock and holds it; B's allocate() must block rather than read the same
    // next_value. Proven by giving B a short innodb_lock_wait_timeout and
    // asserting it TIMES OUT while A holds the lock - a lock that was not
    // taken would let B return instantly with the same number.
    $allocator = app(SequenceAllocator::class);

    DB::transaction(fn (): int => $allocator->allocate('matricule.2026.SEC9'));

    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();

    try {
        $mine = $allocator->allocate('matricule.2026.SEC9');
        expect($mine)->toBe(2);

        $blocked = false;

        try {
            DB::connection('second')->table('sequences')
                ->where('series', '=', 'matricule.2026.SEC9')
                ->lockForUpdate()
                ->first();
        } catch (Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }

        expect($blocked)->toBeTrue('a second connection read the sequence row while it was locked');
    } finally {
        DB::rollBack();
        DB::purge('second');
    }
});

it('previews the next number without consuming it', function () {
    // 07-students 6.2: the admission wizard shows the next admission number as
    // a read-only "(Auto)" field. A previewed number that is never submitted
    // must not be burned.
    $allocator = app(SequenceAllocator::class);

    expect($allocator->peek('admission_no.2026'))->toBe(1);
    expect($allocator->peek('admission_no.2026'))->toBe(1);

    expect(DB::transaction(fn (): int => $allocator->allocate('admission_no.2026')))->toBe(1);
    expect($allocator->peek('admission_no.2026'))->toBe(2);
});
