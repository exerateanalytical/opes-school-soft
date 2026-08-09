<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The QR token's `i` field (docs/specs/10-documents.md 17.1): one durable
 * UUID per installation, minted lazily the first time the document platform
 * needs it and NEVER regenerated - tokens printed years ago must keep
 * naming the instance that issued them.
 *
 * Ungated on purpose: this is infrastructure identity, not data. It is
 * called from inside the signing and verification flows, both of which sit
 * behind their own authorization.
 */
final class ResolveInstanceUuid
{
    public function handle(): string
    {
        /** @var object{uuid: string}|null $row */
        $row = DB::table('document_instance')->where('id', 1)->first();

        if ($row !== null) {
            return $row->uuid;
        }

        return DB::transaction(function (): string {
            // Re-read under lock: two first-ever signings racing here must
            // agree on one UUID, not flip a coin per request.
            /** @var object{uuid: string}|null $locked */
            $locked = DB::table('document_instance')->where('id', 1)->lockForUpdate()->first();

            if ($locked !== null) {
                return $locked->uuid;
            }

            $uuid = (string) Str::uuid();
            $now = now();

            DB::table('document_instance')->insert([
                'id' => 1,
                'uuid' => $uuid,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $uuid;
        });
    }
}
