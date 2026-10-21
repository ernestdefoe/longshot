<?php

namespace ErnestDefoe\Longshot\Console;

use ErnestDefoe\Longshot\Feed\EspnOdds;
use ErnestDefoe\Longshot\PicksBridge;
use Flarum\Console\AbstractCommand;

/**
 * Every 30 minutes: offer Picks' upcoming games and refresh their prices.
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(private PicksBridge $picks, private EspnOdds $odds)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('longshot:sync')->setDescription('Import upcoming games from Picks and refresh their odds from ESPN.');
    }

    protected function fire(): int
    {
        if (! $this->picks->available()) {
            $this->info('Picks is not enabled: nothing to import.');

            return 0;
        }

        $imported = $this->picks->import();
        $odds = $this->odds->run();

        $this->info(sprintf('Imported %d games; %d odds calls updated %d prices.', $imported, $odds['calls'], $odds['updated']));

        return 0;
    }
}
