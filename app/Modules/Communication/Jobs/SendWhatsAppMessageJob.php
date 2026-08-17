<?php

declare(strict_types=1);

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Actions\SendWhatsAppMessage;
use App\Modules\Communication\Support\WhatsApp\WhatsAppNotConfiguredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one WhatsApp message off the request thread.
 *
 * The point is the school's uplink, which in Cameroon is not assumed to be
 * good: a Meta call that takes 15 seconds to time out must not be 15 seconds
 * a teacher spends staring at a spinner after saving a mark. Nothing that
 * happens to this job can fail the action that scheduled it.
 *
 * Scalar constructor arguments only, per RebuildAttendanceSummaryJob - a
 * serialised model would be re-read at run time and could disagree with what
 * the operator saw.
 *
 * NOT idempotent, and cannot be: Meta has no idempotency key on this
 * endpoint, so a retry after an ambiguous timeout could genuinely deliver
 * twice. Hence `$tries = 1`. A duplicate fee reminder is a nuisance, but a
 * duplicate "your child has been suspended" is a phone call from an angry
 * parent, and the delivery log makes a NON-delivery visible and re-sendable
 * by hand. Retrying blind is the worse default, so retries are the outbox's
 * decision (DispatchOutbox's attempt counter), where a human can see them.
 */
final class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<string>  $parameters  template body variables, ignored for text
     */
    public function __construct(
        public readonly string $to,
        public readonly ?string $body = null,
        public readonly ?string $templateName = null,
        public readonly array $parameters = [],
        public readonly ?string $templateLanguage = null,
        public readonly ?int $guardianId = null,
        public readonly ?int $outboxMessageId = null,
    ) {}

    public function handle(SendWhatsAppMessage $send): void
    {
        try {
            if ($this->templateName !== null) {
                $send->template(
                    to: $this->to,
                    templateName: $this->templateName,
                    parameters: $this->parameters,
                    language: $this->templateLanguage,
                    guardianId: $this->guardianId,
                    outboxMessageId: $this->outboxMessageId,
                );

                return;
            }

            $send->text(
                to: $this->to,
                body: (string) $this->body,
                guardianId: $this->guardianId,
                outboxMessageId: $this->outboxMessageId,
            );
        } catch (WhatsAppNotConfiguredException) {
            // Already recorded in the delivery log and the application log by
            // the Action. Re-throwing would mark the job failed and pile up
            // one failed_jobs row per parent on an instance whose only
            // problem is that nobody has pasted a token in yet - burying the
            // real failures. The refusal is not lost; it is just not an
            // exception any more.
        }
    }
}
