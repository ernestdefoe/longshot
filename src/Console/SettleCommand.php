<?php

namespace ErnestDefoe\Longshot\Console;

use ErnestDefoe\Longshot\Settlement;
use Flarum\Console\AbstractCommand;

/**
 * Every minute: results from Picks, offers closed at kickoff, stale games
 * voided, and anything an interrupted run left half done. Costs a handful of
 * indexed queries that return nothing on a quiet minute.
 */
class SettleCommand extends AbstractCommand
{
    public function __construct(private Settlement $settlement)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('longshot:settle')->setDescription('Settle finished games, close offers at kickoff and finish any interrupted settlement.');
    }

    protected function fire(): int
    {
        $done = $this->settlement->sweep();

        $this->info(sprintf('Scored %d, closed %d offers, voided %d, recovered %d.', $done['scored'], $done['closed'], $done['voided'], $done['recovered']));

        return 0;
    }
}
