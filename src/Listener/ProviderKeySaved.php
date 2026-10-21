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
            $provider = preg_match('/^ernestdefoe-longshot\.provider_([a-z]+)_(key|enabled)$/', (string) $key, $m) ? $m[1]
                // Picks' CFBD key is the one CollegeFootballData uses when Longshot has none.
                : ($key === 'ernestdefoe-picks.cfbd_api_key' ? 'cfbd' : null);

            if ($provider) {
                $this->cache->forget('longshot.down.'.$provider);
                $this->cache->forget('longshot.failures.'.$provider);
                $this->cache->forget('longshot.error.'.$provider);
            }
        }
    }
}
