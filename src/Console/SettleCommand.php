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
    public function __construct(private Settlement $settlement, private \ErnestDefoe\Longshot\Feed\Sync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('longshot:settle')->setDescription('Settle finished games, close offers at kickoff and finish any interrupted settlement.');
    }

    protected function fire(): int
    {
        // Results for followed leagues' games due one (each league at most every 5 minutes).
        $fromFeeds = $this->sync->results();
        $done = $this->settlement->sweep();
        $done['scored'] += $fromFeeds;

        $this->info(sprintf('Scored %d, closed %d offers, voided %d, recovered %d.', $done['scored'], $done['closed'], $done['voided'], $done['recovered']));

        return 0;
    }
}
