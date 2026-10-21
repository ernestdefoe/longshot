<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * What each provider may be asked, and how it is doing.
 *
 * 🚨 A call is made only after its cost was added to this period's count in
 * one conditional UPDATE (`calls + cost <= budget`). Workers, retries and
 * overlapping runs all go through it, so no combination of them can spend
 * past a budget: an uncapped outbound call has taken a site down before.
 *
 * Health is a circuit breaker: three failures in a row (or a 429) pause the
 * provider for a while, and every caller skips it to the next in line.
 */
class Budget
{
    public const FAILURES_TO_PAUSE = 3;

    public function __construct(private ConnectionInterface $db, private Cache $cache, private Providers $providers)
    {
    }

    /** Spend $cost of a provider's budget, or refuse. */
    public function spend(string $provider, int $cost = 1): bool
    {
        $p = $this->providers->get($provider);
        if (! $p) {
            return false;
        }

        $budget = $this->providers->budget($provider);
        if ($budget <= 0 || $cost > $budget) {
            return false;
        }

        // A per-minute ceiling for providers that refuse bursts (TheSportsDB's free key).
        if ($perMinute = $p->perMinute()) {
            $key = 'longshot.calls.'.$provider.'.'.Carbon::now()->format('YmdHi');
            $this->cache->add($key, 0, 120);
            if ((int) $this->cache->increment($key, $cost) > $perMinute) {
                return false;
            }
        }

        $period = $this->period($provider);
        $grammar = $this->db->getQueryGrammar();
        $raise = fn () => $this->db->table('longshot_provider_calls')
            ->where('provider', $provider)->where('period', $period)
            ->where('calls', '<=', $budget - $cost)
            ->update(['calls' => $this->db->raw($grammar->wrap('calls').' + '.$cost)]);

        if ($raise()) {
            return true;
        }

        $this->db->table('longshot_provider_calls')->insertOrIgnore(['provider' => $provider, 'period' => $period, 'calls' => 0]);

        return (bool) $raise();
    }

    /** "2026-10-21" or "2026-10", per the provider's budget. */
    public function period(string $provider): string
    {
        $daily = $this->providers->get($provider)?->budgetPeriod() !== 'month';

        return Carbon::now()->utc()->format($daily ? 'Y-m-d' : 'Y-m');
    }

    public function used(string $provider): int
    {
        return (int) $this->db->table('longshot_provider_calls')
            ->where('provider', $provider)->where('period', $this->period($provider))->value('calls');
    }

    public function paused(string $provider): bool
    {
        $until = $this->cache->get('longshot.down.'.$provider);

        return $until && Carbon::parse($until)->isFuture();
    }

    public function succeeded(string $provider): void
    {
        $this->cache->forget('longshot.failures.'.$provider);
    }

    public function failed(string $provider, string $why, bool $pauseNow = false, int $minutes = 15): void
    {
        $this->cache->put('longshot.error.'.$provider, ['message' => mb_substr($why, 0, 300), 'at' => Carbon::now()->toIso8601String()], 86400 * 7);

        $this->cache->add('longshot.failures.'.$provider, 0, 3600);
        $failures = (int) $this->cache->increment('longshot.failures.'.$provider);

        if ($pauseNow || $failures >= self::FAILURES_TO_PAUSE) {
            $this->cache->put('longshot.down.'.$provider, Carbon::now()->addMinutes($minutes)->toIso8601String(), $minutes * 60);
            $this->cache->forget('longshot.failures.'.$provider);
        }
    }

    /** @return array{used: int, budget: int, period: string, paused: ?string, error: ?array{message: string, at: string}} */
    public function status(string $provider): array
    {
        $until = $this->cache->get('longshot.down.'.$provider);

        return [
            'used' => $this->used($provider),
            'budget' => $this->providers->budget($provider),
            'period' => $this->providers->get($provider)?->budgetPeriod() === 'month' ? 'month' : 'day',
            'paused' => $until && Carbon::parse($until)->isFuture() ? Carbon::parse($until)->toIso8601String() : null,
            'error' => $this->cache->get('longshot.error.'.$provider),
        ];
    }
}
