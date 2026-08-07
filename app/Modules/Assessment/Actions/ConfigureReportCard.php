<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Assessment\Models\ReportCardConfig;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/01-assessment.md 13.1 and 13.5 - the report-card configurator.
 *
 * Two rules carry this Action, and both are corrections of v1.
 *
 * **13.1 - versioning is what makes reprint fidelity true.** v1 snapshotted the
 * NUMBERS and left layout, labels, branding and the enabled-block set in a
 * mutable config, so reprinting a January bulletin in June produced January's
 * numbers in June's layout under a logo the school had since changed. The rule
 * here is therefore: a version that no snapshot has used is edited IN PLACE; a
 * version that any snapshot references is FROZEN and a successor is created.
 * T13 fails if this Action ever mutates a referenced version.
 *
 * **13.5 - `marks_columns` is an ordered array of parameterised objects.** v1's
 * configurator held a list of enum keys and therefore could not express a
 * Cameroonian bulletin de trimestre, which prints per-sequence columns BESIDE
 * the term column. Two `period_score` entries distinguished only by
 * `period_ref` are the single thing a flat key list cannot do, so `period_ref`
 * is validated here rather than being accepted as free text that renders blank.
 */
final class ConfigureReportCard
{
    /**
     * `period_ref` grammar from 13.5: self | parent | year | child:<order_index>
     * | child:<code>. Anything else is a column that will silently print
     * nothing, which is the failure this validation exists to make loud.
     */
    private const PERIOD_REF_PATTERN = '/^(self|parent|year|child:[A-Za-z0-9_.\-]+)$/';

    /**
     * @param  array<string, mixed>  $payload  the full rendered shape: blocks, branding, marks_columns
     * @return array{config: ReportCardConfig, version_id: int, version_no: int, created_new_version: bool}
     */
    public function handle(
        ?int $frameworkId,
        string $code,
        array $payload,
        string $name = 'Report Card',
        string $nameFr = 'Bulletin',
    ): array {
        Gate::authorize(Permission::AssessmentConfigure->value);

        $code = trim($code);

        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => 'A report card configuration needs a code.',
            ]);
        }

        $this->validatePayload($payload);

        $actor = $this->currentActor();

        return DB::transaction(function () use ($frameworkId, $code, $payload, $name, $nameFr, $actor): array {
            $config = ReportCardConfig::query()
                ->where('framework_id', '=', $frameworkId)
                ->where('code', '=', $code)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                $config = new ReportCardConfig([
                    'framework_id' => $frameworkId,
                    'code' => $code,
                    'name' => $name,
                    'name_fr' => $nameFr,
                    'is_active' => true,
                    'created_by' => $actor->id,
                ]);
                $config->save();
            }

            $configId = (int) $config->getKey();

            // FOR UPDATE on the head version: two administrators saving the
            // configurator at the same instant would otherwise both read
            // version_no = 3 and both try to insert version 4, and the loser
            // gets a UNIQUE violation instead of a version 5 carrying its own
            // edit.
            $current = DB::table('report_card_config_versions')
                ->where('config_id', '=', $configId)
                ->orderByDesc('version_no')
                ->lockForUpdate()
                ->first();

            $hash = ReportCardSnapshot::hashOf($payload);
            $canonical = ReportCardSnapshot::canonicalJson($payload);

            if ($current === null) {
                $versionId = $this->insertVersion($configId, 1, $canonical, $hash, $actor);

                $this->audit($actor, $configId, null, ['version_no' => 1, 'payload_hash' => $hash]);

                return [
                    'config' => $config,
                    'version_id' => $versionId,
                    'version_no' => 1,
                    'created_new_version' => true,
                ];
            }

            $currentId = (int) $current->id;
            $currentNo = (int) $current->version_no;
            $isFrozen = $current->frozen_at !== null;

            $isReferenced = $isFrozen || ReportCardSnapshot::query()
                ->where('report_card_config_version_id', '=', $currentId)
                ->exists();

            if (! $isReferenced) {
                // 13.1: "Editing a config that has never been used mutates the
                // current version in place."
                DB::table('report_card_config_versions')
                    ->where('id', '=', $currentId)
                    ->update([
                        'payload' => $canonical,
                        'payload_hash' => $hash,
                        'updated_at' => now(),
                    ]);

                $this->audit(
                    $actor,
                    $configId,
                    ['version_no' => $currentNo, 'payload_hash' => (string) $current->payload_hash],
                    ['version_no' => $currentNo, 'payload_hash' => $hash],
                );

                return [
                    'config' => $config,
                    'version_id' => $currentId,
                    'version_no' => $currentNo,
                    'created_new_version' => false,
                ];
            }

            // 13.1: referenced => the old row becomes immutable and a successor
            // carries the edit. Freeze BEFORE inserting: if the transaction
            // fails between the two, an unfrozen-but-referenced version is the
            // state that lets a later edit rewrite an issued card.
            if (! $isFrozen) {
                DB::table('report_card_config_versions')
                    ->where('id', '=', $currentId)
                    ->whereNull('frozen_at')
                    ->update(['frozen_at' => now(), 'updated_at' => now()]);
            }

            $versionId = $this->insertVersion($configId, $currentNo + 1, $canonical, $hash, $actor);

            $this->audit(
                $actor,
                $configId,
                ['version_no' => $currentNo, 'frozen' => true],
                ['version_no' => $currentNo + 1, 'payload_hash' => $hash],
            );

            return [
                'config' => $config,
                'version_id' => $versionId,
                'version_no' => $currentNo + 1,
                'created_new_version' => true,
            ];
        });
    }

    private function insertVersion(
        int $configId,
        int $versionNo,
        string $canonicalPayload,
        string $hash,
        Actor $actor,
    ): int {
        return (int) DB::table('report_card_config_versions')->insertGetId([
            'config_id' => $configId,
            'version_no' => $versionNo,
            'payload' => $canonicalPayload,
            'payload_hash' => $hash,
            'frozen_at' => null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload): void
    {
        $columns = $payload['marks_columns'] ?? null;

        if (! is_array($columns) || $columns === []) {
            throw ValidationException::withMessages([
                'marks_columns' => 'A report card configuration must declare at least one marks column (01-assessment 13.5).',
            ]);
        }

        if (array_is_list($columns) === false) {
            throw ValidationException::withMessages([
                'marks_columns' => 'marks_columns is an ORDERED array; a keyed map loses the column order the card prints in.',
            ]);
        }

        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                throw ValidationException::withMessages([
                    "marks_columns.{$index}" => 'Each marks column is an object, not a bare key (01-assessment 13.5).',
                ]);
            }

            $key = $column['key'] ?? null;

            if (! is_string($key) || ! in_array($key, ReportCardConfig::COLUMN_KEYS, true)) {
                throw ValidationException::withMessages([
                    "marks_columns.{$index}.key" => sprintf(
                        'Unknown column key %s. A key outside 13.5\'s set renders a blank column nobody notices until a parent does.',
                        is_string($key) ? $key : gettype($key),
                    ),
                ]);
            }

            if ($key === 'period_score') {
                $ref = $column['period_ref'] ?? null;

                if (! is_string($ref) || preg_match(self::PERIOD_REF_PATTERN, $ref) !== 1) {
                    throw ValidationException::withMessages([
                        "marks_columns.{$index}.period_ref" => 'A period_score column needs a period_ref of self, parent, '
                            .'year, child:<order_index> or child:<code> - that reference is what lets a term column and its '
                            .'own sequence columns coexist (01-assessment 13.5).',
                    ]);
                }
            }

            if ($key === 'component_score' && ! is_string($column['component_ref'] ?? null)) {
                throw ValidationException::withMessages([
                    "marks_columns.{$index}.component_ref" => 'A component_score column needs a component_ref naming the component code.',
                ]);
            }
        }

        $blocks = $payload['blocks'] ?? [];

        if (! is_array($blocks)) {
            throw ValidationException::withMessages([
                'blocks' => 'blocks is a map of block key to its setting (01-assessment 13.7).',
            ]);
        }

        foreach (array_keys($blocks) as $block) {
            if (! is_string($block) || ! in_array($block, ReportCardConfig::BLOCK_KEYS, true)) {
                throw ValidationException::withMessages([
                    'blocks' => sprintf(
                        'Unknown report card block %s (01-assessment 13.7).',
                        is_string($block) ? $block : gettype($block),
                    ),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Actor $actor, int $configId, ?array $before, array $after): void
    {
        app(WriteAuditEntry::class)->handle(
            action: $before === null ? AuditAction::Created : AuditAction::Updated,
            module: 'Assessment',
            auditableType: ReportCardConfig::class,
            auditableId: $configId,
            before: $before,
            after: $after,
            actor: $actor,
        );
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
