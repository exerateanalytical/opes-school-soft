<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\PostingRule;

/**
 * docs/specs/02-accounting.md §11.1 - the ambiguity check. For each event it
 * enumerates ACTIVE rules sharing a priority whose `[effective_from,
 * effective_to)` ranges overlap: two such rules can both match one posting
 * date at the same priority, which would make PostFromEvent's "highest
 * single priority wins" undecidable.
 *
 * Run at rule save (inside SavePostingRule's transaction, so an ambiguous
 * configuration is rejected before commit) and standalone by the nightly
 * check - never discovered at posting time in front of a parent at the cash
 * desk.
 */
final class ValidatePostingRules
{
    /**
     * @return list<array{event: string, priority: int, rule_ids: list<int>, codes: list<string>}>
     */
    public function handle(?string $event = null): array
    {
        $query = PostingRule::query()->where('is_active', true);

        if ($event !== null) {
            $query->where('event', $event);
        }

        /** @var list<PostingRule> $rules */
        $rules = $query->orderBy('event')->orderBy('priority')->orderBy('id')->get()->all();

        /** @var array<string, list<PostingRule>> $groups */
        $groups = [];

        foreach ($rules as $rule) {
            $groups[$rule->event.'|'.$rule->priority][] = $rule;
        }

        $conflicts = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $overlapping = [];

            foreach ($group as $i => $a) {
                foreach (array_slice($group, $i + 1) as $b) {
                    if (self::rangesOverlap($a, $b)) {
                        $overlapping[$a->id] = $a;
                        $overlapping[$b->id] = $b;
                    }
                }
            }

            if ($overlapping !== []) {
                $first = $group[0];
                $conflicts[] = [
                    'event' => $first->event,
                    'priority' => $first->priority,
                    'rule_ids' => array_values(array_map(
                        static fn (PostingRule $rule): int => $rule->id,
                        $overlapping,
                    )),
                    'codes' => array_values(array_map(
                        static fn (PostingRule $rule): string => $rule->code.' v'.$rule->version,
                        $overlapping,
                    )),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * `[from, to)` intervals, `to = null` meaning open-ended.
     */
    private static function rangesOverlap(PostingRule $a, PostingRule $b): bool
    {
        $aOpenEnded = $a->effective_to === null;
        $bOpenEnded = $b->effective_to === null;

        $aStartsBeforeBEnds = $bOpenEnded || $a->effective_from->lt($b->effective_to);
        $bStartsBeforeAEnds = $aOpenEnded || $b->effective_from->lt($a->effective_to);

        return $aStartsBeforeBEnds && $bStartsBeforeAEnds;
    }
}
