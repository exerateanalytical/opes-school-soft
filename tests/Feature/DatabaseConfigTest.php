<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('runs tests against MySQL 8, not SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    /** @var object{version: string} $row */
    $row = DB::selectOne('select version() as version');

    expect($row->version)->not->toContain('MariaDB');
    expect((int) explode('.', $row->version)[0])->toBeGreaterThanOrEqual(8);
});

it('uses the test database, never the development one', function () {
    /** @var object{db: string} $row */
    $row = DB::selectOne('select database() as db');

    expect($row->db)->toBe('opeschool_test');
});

it('has the MySQL 8 collation the spec requires', function () {
    /** @var object{collation: string} $row */
    $row = DB::selectOne('select @@collation_database as collation');

    expect($row->collation)->toBe('utf8mb4_0900_ai_ci');
});
