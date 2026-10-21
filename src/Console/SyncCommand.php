<?php

namespace ErnestDefoe\Longshot\Console;

use ErnestDefoe\Longshot\Feed\EspnOdds;
use ErnestDefoe\Longshot\Feed\Sync;
use ErnestDefoe\Longshot\Jobs\SyncLeague;
use ErnestDefoe\Longshot\PicksBridge;
use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Queue\Queue;

/**
 * Every 30 minutes: Picks' upcoming games offered and priced, and a queued
 * sync for each league the forum follows (each job throttles itself, so
 * most runs ask nobody anything).
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(private PicksBridge $picks, private EspnOdds $odds, private Sync $sync, private Queue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('longshot:sync')->setDescription('Import upcoming games (from Picks and the data providers), refresh their odds and branding.');
    }

    protected function fire(): int
    {
        if ($this->picks->available()) {
            $imported = $this->picks->import();
            $odds = $this->odds->run();
            $this->info(sprintf('Picks: imported %d games; %d odds calls updated %d prices.', $imported, $odds['calls'], $odds['updated']));
        }

        foreach ($this->sync->followed() as $league) {
            $this->queue->push(new SyncLeague($league->key));
            $this->info('Queued '.$league->name.'.');
        }

        return 0;
    }
}
