<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Domain\MessageChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `message_templates` (2026_08_09_300005). Both language bodies are
 * mandatory by the migration: Cameroon is bilingual and the language a
 * message goes out in is the recipient's, decided at send time.
 *
 * `code` is case-SENSITIVE at the database (utf8mb4_0900_as_cs), so
 * 'FEE-REMINDER' and 'fee-reminder' are two different templates - the
 * lookups here must not lower-case it on the way in.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property MessageChannel $channel
 * @property string|null $subject_en
 * @property string|null $subject_fr
 * @property string $body_en
 * @property string $body_fr
 * @property list<string>|null $variables
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MessageTemplate extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'channel', 'subject_en', 'subject_fr',
        'body_en', 'body_fr', 'variables', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<OutboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(OutboxMessage::class, 'message_template_id');
    }

    /** The body for a language, falling back to English if 'fr' is empty. */
    public function bodyFor(string $language): string
    {
        $body = $language === 'fr' ? $this->body_fr : $this->body_en;

        return trim($body) === '' ? $this->body_en : $body;
    }

    public function subjectFor(string $language): ?string
    {
        $subject = $language === 'fr' ? $this->subject_fr : $this->subject_en;

        return $subject === null || trim($subject) === '' ? $this->subject_en : $subject;
    }

    public function nameFor(string $language): string
    {
        return $language === 'fr' && trim($this->name_fr) !== '' ? $this->name_fr : $this->name;
    }

    /**
     * The placeholder names this template declares, e.g. ['student_name'].
     *
     * @return list<string>
     */
    public function declaredVariables(): array
    {
        $declared = $this->variables ?? [];

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $declared),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
