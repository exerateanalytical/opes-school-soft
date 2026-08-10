<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Domain\MessageChannel;
use App\Modules\Communication\Models\MessageTemplate;
use App\Modules\Communication\Support\MergeFields;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a bilingual message template.
 *
 * The 300005 migration says the declared `variables` exist "so the renderer
 * can validate a template at save instead of failing at send" - so that is
 * what happens here: every placeholder used in either body must be declared,
 * and the save is refused otherwise. A template that reaches the outbox has
 * already been proved renderable.
 */
final class SaveMessageTemplate
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $templateId, array $data, Actor $actor): MessageTemplate
    {
        Gate::authorize(Permission::CommunicationSend->value);

        return DB::transaction(function () use ($templateId, $data, $actor): MessageTemplate {
            $existing = null;

            if ($templateId !== null) {
                /** @var MessageTemplate $existing */
                $existing = MessageTemplate::query()->lockForUpdate()->findOrFail($templateId);
            }

            $attributes = $this->validated($data, $existing);

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $template = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $template = MessageTemplate::query()->create($attributes);
                $auditAction = AuditAction::Created;
            }

            $template->refresh();

            $this->audit->handle(
                action: $auditAction,
                module: 'Communication',
                auditableType: MessageTemplate::class,
                auditableId: (int) $template->getKey(),
                after: [
                    'code' => $template->code,
                    'channel' => $template->channel->value,
                    'is_active' => $template->is_active,
                    'variables' => $template->declaredVariables(),
                ],
                actor: $actor,
            );

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, ?MessageTemplate $existing): array
    {
        $value = static function (string $key) use ($data, $existing): mixed {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }

            return $existing?->getAttribute($key);
        };

        // `code` is case-sensitive at the database; do NOT normalise case.
        $code = trim((string) $value('code'));

        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => 'A template needs a code, e.g. FEE-REMINDER.',
            ]);
        }

        if (mb_strlen($code) > 40) {
            throw ValidationException::withMessages([
                'code' => 'The template code may not exceed 40 characters.',
            ]);
        }

        $clash = MessageTemplate::query()
            // The column's own collation (utf8mb4_0900_as_cs) is already
            // case-sensitive, so a plain comparison is the case-sensitive
            // one - no BINARY cast (deprecated in MySQL 8.x) needed.
            ->where('code', $code)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing?->getKey()))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'code' => 'A template already carries this code.',
            ]);
        }

        $channelRaw = $value('channel');
        $channel = $channelRaw instanceof MessageChannel
            ? $channelRaw
            : MessageChannel::tryFrom((string) $channelRaw);

        if ($channel === null) {
            throw ValidationException::withMessages([
                'channel' => 'Choose a delivery channel: SMS, e-mail, push or WhatsApp.',
            ]);
        }

        $name = trim((string) $value('name'));
        $nameFr = trim((string) $value('name_fr'));

        if ($name === '' || $nameFr === '') {
            throw ValidationException::withMessages([
                'name' => 'Both the English and French names are required - the school is bilingual.',
            ]);
        }

        $bodyEn = (string) $value('body_en');
        $bodyFr = (string) $value('body_fr');

        if (trim($bodyEn) === '' || trim($bodyFr) === '') {
            throw ValidationException::withMessages([
                'body_en' => 'Both language bodies are required; a missing one would anglicise half the parent body.',
            ]);
        }

        $subjectEn = $this->nullableString($value('subject_en'));
        $subjectFr = $this->nullableString($value('subject_fr'));

        if ($channel->usesSubjectLine() && ($subjectEn === null || $subjectFr === null)) {
            throw ValidationException::withMessages([
                'subject_en' => 'An e-mail template needs a subject line in both languages.',
            ]);
        }

        $declared = $this->declaredFrom($value('variables'));

        // Validate at SAVE, not at send (300005's own reasoning).
        $used = array_values(array_unique(array_merge(
            MergeFields::extract($bodyEn),
            MergeFields::extract($bodyFr),
            MergeFields::extract((string) $subjectEn),
            MergeFields::extract((string) $subjectFr),
        )));

        $undeclared = array_values(array_diff($used, $declared));

        if ($undeclared !== []) {
            throw ValidationException::withMessages([
                'variables' => 'These placeholders are used but not declared: {'
                    .implode('}, {', $undeclared).'}.',
            ]);
        }

        $isActive = $value('is_active');

        return [
            'code' => $code,
            'name' => $name,
            'name_fr' => $nameFr,
            'channel' => $channel->value,
            'subject_en' => $channel->usesSubjectLine() ? $subjectEn : null,
            'subject_fr' => $channel->usesSubjectLine() ? $subjectFr : null,
            'body_en' => $bodyEn,
            'body_fr' => $bodyFr,
            'variables' => $declared,
            'is_active' => $isActive === null ? true : (bool) $isActive,
        ];
    }

    private function nullableString(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * Accepts a list, or the comma/newline separated string a form field
     * gives you.
     *
     * @return list<string>
     */
    private function declaredFrom(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $names = [];

        foreach ($raw as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }

            $name = trim((string) $item, " \t\n\r{}");

            if ($name === '') {
                continue;
            }

            if (preg_match('/^[a-zA-Z0-9_.]+$/', $name) !== 1) {
                throw ValidationException::withMessages([
                    'variables' => "'{$name}' is not a usable placeholder name; use letters, digits and underscores.",
                ]);
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
