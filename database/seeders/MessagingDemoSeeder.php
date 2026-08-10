<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Communication\Actions\Messaging\PostMessage;
use App\Modules\Communication\Actions\Messaging\StartThread;
use App\Modules\Communication\Models\MessageThread;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * A couple of realistic teacher <-> guardian conversations, so the
 * messenger demos with real content rather than an empty inbox.
 *
 * Idempotent by title: a thread with the same title for the same demo pair
 * is not recreated.
 */
final class MessagingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::query()->where('email', 'demo.teacher@opeschool.test')->first();
        $registrar = User::query()->where('email', 'demo.registrar@opeschool.test')->first();
        $guardian = User::query()->where('email', 'demo.guardian1@opeschool.test')->first();

        if ($teacher === null || $guardian === null) {
            $this->command?->warn('MessagingDemoSeeder: demo teacher or guardian missing; skipping.');

            return;
        }

        $this->conversation(
            (int) $guardian->getKey(),
            (int) $teacher->getKey(),
            'Question about the mid-term results',
            [
                [$guardian, 'Good afternoon, could I get an update on my child\'s progress this term?'],
                [$teacher, 'Good afternoon. Overall progress is good - I will share the detailed mark sheet after the conseil de classe.'],
                [$guardian, 'Thank you, I appreciate it.'],
            ],
        );

        if ($registrar !== null) {
            $this->conversation(
                (int) $teacher->getKey(),
                (int) $registrar->getKey(),
                'Class list correction needed',
                [
                    [$teacher, 'Two students on my class list have the wrong stream recorded. Could you check?'],
                    [$registrar, 'Sending you the enrolment records now for confirmation.'],
                ],
            );
        }
    }

    /**
     * @param  list<array{0: User, 1: string}>  $exchange
     */
    private function conversation(int $starterId, int $otherId, string $title, array $exchange): void
    {
        $exists = MessageThread::query()->where('title', $title)->exists();

        if ($exists) {
            $this->command?->info("Thread \"{$title}\" already exists; skipping.");

            return;
        }

        [, $firstBody] = $exchange[0];

        $thread = app(StartThread::class)->handle($starterId, $title, [$otherId], $firstBody);

        foreach (array_slice($exchange, 1) as [$sender, $body]) {
            app(PostMessage::class)->handle((int) $thread->getKey(), (int) $sender->getKey(), $body);
        }

        $this->command?->info("Seeded thread \"{$title}\" with ".count($exchange).' message(s).');
    }
}
