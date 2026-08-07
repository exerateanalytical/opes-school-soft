<?php

declare(strict_types=1);

use App\Support\Clock\BusinessDate;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('returns the Douala date, not the UTC date', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 23:30:00', 'UTC'));

    expect(BusinessDate::today())->toBe('2026-08-08');
});

it('agrees with UTC during the rest of the day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 14:00:00', 'UTC'));

    expect(BusinessDate::today())->toBe('2026-08-07');
});

it('converts an arbitrary instant to a business date', function () {
    expect(BusinessDate::from(Carbon::parse('2026-12-31 23:45:00', 'UTC')))->toBe('2027-01-01');
});

it('exposes the timezone it uses', function () {
    expect(BusinessDate::timezone())->toBe('Africa/Douala');
});

it('returns a Carbon start of day in the business timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 23:30:00', 'UTC'));

    $start = BusinessDate::startOfToday();

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-08-08 00:00:00');
    expect($start->timezone->getName())->toBe('Africa/Douala');
});

it('returns an end of day in the business timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 23:30:00', 'UTC'));

    expect(BusinessDate::endOfToday()->format('Y-m-d H:i:s'))->toBe('2026-08-08 23:59:59');
});
