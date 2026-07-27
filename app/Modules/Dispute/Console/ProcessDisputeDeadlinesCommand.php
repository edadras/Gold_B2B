<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Console;

use App\Modules\Dispute\Application\DeadlineProcessor;
use Illuminate\Console\Command;

/**
 * Runs the §13.6 clocks. Intended to be scheduled every few minutes.
 *
 * `--dry-run` reports what would be escalated without moving anything, which is
 * what an operator wants before a maintenance window.
 */
final class ProcessDisputeDeadlinesCommand extends Command
{
    protected $signature = 'dispute:process-deadlines
        {--dry-run : List the cases that would escalate without escalating them}';

    protected $description = 'Escalate disputes whose reply or negotiation deadline has expired';

    public function handle(DeadlineProcessor $processor): int
    {
        if ((bool) $this->option('dry-run')) {
            return $this->report($processor);
        }

        $result = $processor->sweep();

        $this->info(sprintf(
            'Escalated %d case(s): %d with no reply, %d with no agreement.',
            $result->total(),
            $result->noReplyEscalated,
            $result->negotiationEscalated,
        ));

        foreach ($result->failures as $failure) {
            $this->error('Could not escalate '.$failure);
        }

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function report(DeadlineProcessor $processor): int
    {
        $approaching = $processor->replyDeadlineApproaching();

        $this->line(sprintf('%d case(s) within 4 hours of their reply deadline:', count($approaching)));

        foreach ($approaching as $dispute) {
            $this->line(sprintf(
                '  %s — %s — due %s',
                $dispute->case_number,
                $dispute->dispute_type,
                (string) $dispute->reply_deadline_at,
            ));
        }

        return self::SUCCESS;
    }
}
