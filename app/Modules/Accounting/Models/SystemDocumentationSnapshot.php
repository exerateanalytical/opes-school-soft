<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One generation of the AUDCIF §14.4 "documentation du système comptable".
 *
 * @property int $id
 * @property string $sha256
 * @property int|null $supersedes_id
 */
final class SystemDocumentationSnapshot extends Model
{
    protected $table = 'system_documentation_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'generated_at', 'generated_by', 'software_version', 'schema_version',
        'file_path', 'sha256', 'supersedes_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }
}
