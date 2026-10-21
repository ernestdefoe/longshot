<?php

namespace ErnestDefoe\Longshot\Listener;

use Flarum\Settings\Event\Saved;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A new key for a provider lifts the pause its old key earned: an admin who
 * just fixed a refused key should not wait an hour to see it work.
 */
class ProviderKeySaved
{
    public function __construct(private Cache $cache)
    {
    }

    public function handle(Saved $event): void
    {
        foreach (array_keys($event->settings) as $key) {
            if (preg_match('/^ernestdefoe-longshot\.provider_([a-z]+)_(key|enabled)$/', (string) $key, $m)) {
                $this->cache->forget('longshot.down.'.$m[1]);
                $this->cache->forget('longshot.failures.'.$m[1]);
                $this->cache->forget('longshot.error.'.$m[1]);
            }
        }
    }
}
