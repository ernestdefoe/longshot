<?php

namespace ErnestDefoe\Longshot\Feed;

interface Branding
{
    /**
     * Every team the provider knows in a league, with logos and colours.
     *
     * @return list<Side>|null
     */
    public function teams(League $league): ?array;

    /** The league's badge, as an image URL. */
    public function badge(League $league): ?string;
}
