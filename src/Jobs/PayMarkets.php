<?php

namespace ErnestDefoe\Longshot\Jobs;

use ErnestDefoe\Longshot\Settlement;
use Flarum\Queue\AbstractJob;

/** Pays out the bets and matches on markets that just got their result. */
class PayMarkets extends AbstractJob
{
    /** @param list<int> $marketIds */
    public function __construct(public array $marketIds)
    {
    }

    public function handle(Settlement $settlement): void
    {
        $settlement->payMarkets($this->marketIds);
    }
}
