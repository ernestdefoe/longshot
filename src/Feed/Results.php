<?php

namespace ErnestDefoe\Longshot\Feed;

interface Results
{
    /**
     * Games of the last few days with their final scores.
     *
     * @return list<Game>|null
     */
    public function results(League $league, int $days): ?array;
}
