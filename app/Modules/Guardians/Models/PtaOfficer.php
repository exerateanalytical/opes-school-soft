<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A standing PTA role - President, Secretary, Treasurer - held by a
 * guardian. `term_ends_on` NULL means currently serving.
 *
 * No relation to Guardian: the module boundary test forbids importing
 * another module's Models, the same rule GuardianMeeting already follows.
 *
 * @property int $id
 * @property int $guardian_id
 * @property string $office
 */
final class PtaOfficer extends Model
{
    protected $table = 'pta_officers';

    /** @var list<string> */
    protected $fillable = ['guardian_id', 'office', 'term_starts_on', 'term_ends_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['term_starts_on' => 'date', 'term_ends_on' => 'date'];
    }
}
