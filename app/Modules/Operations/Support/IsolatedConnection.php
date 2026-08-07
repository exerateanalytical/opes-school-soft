<?php

declare(strict_types=1);

namespace App\Modules\Operations\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * A second, independent connection to the same MySQL server.
 *
 * Two operations in this module must NOT run on the application's connection:
 *
 * 1. Reading the state a backup captured. `mysqldump` connects as its own
 *    client and therefore sees only COMMITTED data. A manifest computed on the
 *    application's connection sees that connection's uncommitted writes too, so
 *    it can describe rows the dump does not contain - and the restore drill
 *    would then report a row-count mismatch that does not exist on disk.
 *
 * 2. `CREATE DATABASE` / `DROP DATABASE`. MySQL gives DDL an implicit commit,
 *    so running the restore drill's scratch-schema DDL on the application's
 *    connection would silently commit whatever transaction the caller had open.
 *
 * Both are solved the same way: a separate PDO, hence a separate MySQL session.
 */
final class IsolatedConnection
{
    /**
     * @param  string  $name  A connection name reserved for this purpose.
     */
    public static function resolve(string $name): Connection
    {
        config(["database.connections.{$name}" => config('database.connections.mysql')]);

        // Purge first: a stale instance from an earlier call would hand back
        // the same PDO, and with it the same session.
        DB::purge($name);

        /** @var Connection $connection */
        $connection = DB::connection($name);

        return $connection;
    }

    public static function release(string $name): void
    {
        DB::purge($name);
    }
}
