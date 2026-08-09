<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\PostingRule;
use App\Support\Audit\Actor;
use App\Support\Expression\Expression;
use App\Support\Expression\LabelTemplate;
use App\Support\Expression\Payload;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ValueError;

/**
 * docs/specs/02-accounting.md §11.1 - the posting engine's front door: an
 * event happened, here is its payload, land it in the ledger.
 *
 * Rule selection: candidates are ACTIVE rules for the event whose
 * `[effective_from, effective_to)` covers `date` and whose condition
 * evaluates true against the payload. The highest-priority SINGLE match
 * wins; a tie at the top priority FAILS LOUDLY - it never picks one.
 *
 * The winning rule is evaluated (EvaluatePostingRule) and posted through
 * the existing DraftJournalEntry / PostJournalEntry Actions - posting is
 * never re-implemented here - stamping `posting_rule_id` AND
 * `posting_rule_version` so an auditor can reconstruct exactly which rule
 * produced an entry three years later. The first successful post locks the
 * rule (`is_locked`), flipping it onto the immutable-versioning path.
 */
final class PostFromEvent
{
    public function __construct(
        private readonly EvaluatePostingRule $evaluator,
        private readonly DraftJournalEntry $draft,
        private readonly PostJournalEntry $post,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $valueDate  economic date when it differs from the
     *                                  accounting date - 02-accounting C4 /
     *                                  06-assets-stores §4.4: a catch-up
     *                                  entry lands in the first OPEN period
     *                                  but retains the original value date.
     */
    public function handle(string $event, array $payload, string $date, Actor $actor, ?string $reference = null, ?string $valueDate = null): JournalEntry
    {
        try {
            $postingEvent = PostingEvent::from($event);
        } catch (ValueError) {
            throw new DomainException("Unknown posting event '{$event}'; it is not in the §11.2 catalogue.");
        }

        $rule = $this->selectRule($postingEvent, $payload, Carbon::parse($date)->startOfDay());

        return DB::transaction(function () use ($rule, $payload, $date, $actor, $reference, $valueDate): JournalEntry {
            $lines = $this->evaluator->handle($rule, $payload);
            $label = LabelTemplate::render(
                $rule->label_expression,
                Payload::printables(Payload::flatten($payload)),
            );

            $entry = $this->draft->handle(
                journalId: $rule->journal_id,
                date: $date,
                valueDate: $valueDate,
                label: $label,
                reference: $reference,
                lines: $lines,
                actor: $actor,
            );

            // Stamp the provenance BEFORE posting - immutable from then on.
            $entry->forceFill([
                'posting_rule_id' => $rule->id,
                'posting_rule_version' => $rule->version,
                'source_type' => 'posting_event',
            ])->save();

            $posted = $this->post->handle((int) $entry->getKey(), $actor);

            // First post locks the rule: from here on, edits create a new
            // version rather than mutating what produced this entry (§11.1).
            if (! $rule->is_locked) {
                $rule->forceFill(['is_locked' => true])->save();
            }

            return $posted;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function selectRule(PostingEvent $event, array $payload, Carbon $date): PostingRule
    {
        $scalars = Payload::scalars(Payload::flatten($payload));

        /** @var list<PostingRule> $candidates */
        $candidates = PostingRule::query()
            ->where('event', $event->value)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get()
            ->all();

        /** @var list<PostingRule> $matching */
        $matching = [];

        foreach ($candidates as $candidate) {
            if (! $candidate->coversDate($date)) {
                continue;
            }

            if ($candidate->condition_expression !== null
                && ! Expression::parse($candidate->condition_expression)->truth($scalars)) {
                continue;
            }

            $matching[] = $candidate;
        }

        if ($matching === []) {
            throw new DomainException(
                "No active posting rule matches event '{$event->value}' on {$date->toDateString()}."
            );
        }

        $topPriority = $matching[0]->priority;
        $top = array_values(array_filter(
            $matching,
            static fn (PostingRule $rule): bool => $rule->priority === $topPriority,
        ));

        if (count($top) > 1) {
            $codes = implode(', ', array_map(
                static fn (PostingRule $rule): string => $rule->code.' v'.$rule->version,
                $top,
            ));

            throw new DomainException(sprintf(
                "Ambiguous posting rules for event '%s' on %s: %s all match at priority %d. Posting refuses to pick one; fix the rule configuration.",
                $event->value,
                $date->toDateString(),
                $codes,
                $topPriority,
            ));
        }

        return $top[0];
    }
}
