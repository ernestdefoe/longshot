<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;

interface Prices
{
    /**
     * Upcoming games with prices, to be matched to ours by sides and kickoff.
     *
     * @return list<Game>|null
     */
    public function prices(League $league, Carbon $from, Carbon $to): ?array;
}
