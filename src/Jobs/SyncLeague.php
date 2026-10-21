<?php

namespace ErnestDefoe\Longshot\Jobs;

use ErnestDefoe\Longshot\Feed\Leagues;
use ErnestDefoe\Longshot\Feed\Sync;
use Flarum\Queue\AbstractJob;

/**
 * One followed league's sync, on the queue: a slow provider holds up a
 * worker, never a page view or the scheduler. Its calls are capped by the
 * providers' budgets whichever process runs it.
 */
class SyncLeague extends AbstractJob
{
    public function __construct(public string $league)
    {
    }

    public function handle(Sync $sync, Leagues $leagues): void
    {
        if ($league = $leagues->get($this->league)) {
            $sync->league($league);
        }
    }
}
