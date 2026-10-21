<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;

interface Schedules
{
    /**
     * Games starting between two times (results included for finished ones).
     *
     * @return list<Game>|null null when the provider could not answer
     */
    public function games(League $league, Carbon $from, Carbon $to): ?array;
}
