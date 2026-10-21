<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\PicksBridge;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the leagues a forum follows up to date, end to end: games imported,
 * priced until kickoff, closed when they start, settled from final scores,
 * and branded with logos, colours and the league's badge.
 *
 * Each job asks its providers in order (keyless first) and moves on when one
 * fails, is paused or has spent its budget. Each is throttled per league, so
 * running the sync more often than needed costs nothing:
 *
 *   schedule (whole horizon)   every 6 hours
 *   prices (next two days)     every 30 minutes
 *   results                    every 5 minutes, only while a game is due one
 *   branding                   every 7 days
 *
 * Leagues Picks already plays are left to Picks for fixtures and scores (its
 * games are imported by PicksBridge); they are still branded.
 */
class Sync
{
    public function __construct(
        private Leagues $leagues,
        private Providers $providers,
        private Importer $importer,
        private Pricer $pricer,
        private Teams $teams,
        private Logos $logos,
        private Config $config,
        private PicksBridge $picks,
        private Cache $cache,
        private LoggerInterface $log,
    ) {
    }

    /**
     * Followed leagues plus the leagues Picks plays (branded only).
     *
     * @return list<League>
     */
    public function leaguesToSync(): array
    {
        $keys = array_unique(array_merge($this->config->followedLeagues(), $this->picks->leagues()));

        return array_values(array_filter(array_map(fn ($key) => $this->leagues->get($key), $keys)));
    }

    /** @return list<League> */
    public function followed(): array
    {
        return array_values(array_filter(array_map(fn ($key) => $this->leagues->get($key), $this->config->followedLeagues())));
    }

    /**
     * One league's scheduled work. $force runs every job now (staff's "sync now").
     *
     * @return array<string, mixed>
     */
    public function league(League $league, bool $force = false): array
    {
        $report = ['league' => $league->key, 'schedule' => null, 'prices' => null, 'branding' => null];
        $byPicks = in_array($league->key, $this->picks->leagues(), true);

        try {
            // Picks plays the league now, but feed games are still on the board:
            // keep their kickoffs current (no new games added).
            if ($byPicks && Event::query()->where('league_key', $league->key)->where('status', Event::OPEN)
                ->whereNotIn('source', ['manual', 'picks'])->exists() && $this->due($league, 'near', 30, $force)) {
                $report['schedule'] = $this->schedule($league, Carbon::now(), Carbon::now()->addDays(2), false);
            }

            if (! $byPicks && $this->due($league, 'schedule', 360, $force)) {
                $report['schedule'] = $this->schedule($league, Carbon::now()->subDay(), Carbon::now()->addDays($this->config->horizonDays()));
                // The full sync covers the next two days as well.
                $this->due($league, 'near', 30, true);
            } elseif (! $byPicks && $this->due($league, 'near', 30, $force)) {
                // Between full syncs, the next two days: fresher prices and kickoffs.
                $report['schedule'] = $this->schedule($league, Carbon::now(), Carbon::now()->addDays(2));
            }

            $report['prices'] = $this->prices($league, $force);

            if ($this->due($league, 'branding', 60 * 24 * 7, $force)) {
                $report['branding'] = $this->brand($league);
            }
        } catch (Throwable $e) {
            $this->log->error('[longshot] syncing '.$league->key.' failed: '.$e->getMessage());
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    /** Throttle: true at most once per $minutes per league and job. */
    private function due(League $league, string $job, int $minutes, bool $force): bool
    {
        $key = 'longshot.sync.'.$league->key.'.'.$job;

        if ($force) {
            $this->cache->put($key, 1, $minutes * 60);

            return true;
        }

        return $this->cache->add($key, 1, $minutes * 60);
    }

    /** @return array<string, mixed>|null */
    private function schedule(League $league, Carbon $from, Carbon $to, bool $add = true): ?array
    {
        foreach ($this->providers->for(Schedules::class, $league) as $i => $provider) {
            $games = $provider->games($league, $from, $to);

            if ($games !== null) {
                // 🚨 Only the league's first provider adds games. A backup
                // answering while it is down updates and settles the games it
                // already knows, but adds none: its names differ ("Bayern
                // München"), and a game it added would sit on the board twice.
                return ['provider' => $provider->key()] + $this->importer->apply($league, $games, $add && $i === 0);
            }
        }

        return null;
    }

    /**
     * Prices for open games that the schedule's own feed left unpriced, from
     * the price-only providers, matched to our games by teams and kickoff.
     *
     * @return array<string, int>
     */
    private function prices(League $league, bool $force = false): array
    {
        $now = Carbon::now();
        $done = [];

        $open = fn () => Event::query()->where('league_key', $league->key)->where('status', Event::OPEN)
            ->where('locked', false)->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addDays(7))
            ->get()->filter(fn (Event $e) => ! $this->pricer->priced($e->id));

        foreach ($this->providers->for(Prices::class, $league) as $provider) {
            // Each on its own clock (The Odds API every 6 hours, CFBD daily…):
            // a paid budget lasts the month. Staff's "sync now" never forces a
            // provider that spends a key's quota.
            if (! $this->due($league, 'prices-'.$provider->key(), $provider->priceEvery(), $force && ! $provider->needsKey())) {
                continue;
            }

            $waiting = $open();
            if ($waiting->isEmpty()) {
                break;
            }

            $games = $provider->prices($league, $now, $waiting->max('starts_at')->copy()->addHour());
            if ($games === null) {
                continue;
            }

            $done[$provider->key()] = 0;
            foreach ($waiting as $event) {
                $game = $this->match($event, $games, $league);
                if ($game && $game->prices) {
                    $done[$provider->key()] += (int) ($this->pricer->apply($event->id, $game->prices, $game->bookmaker) > 0);
                }
            }
        }

        return $done;
    }

    /**
     * The game that is this event: by the provider's own id when the event
     * came from it, else by both sides' teams and a kickoff within six hours.
     *
     * @param list<Game> $games
     */
    public function match(Event $event, array $games, League $league): ?Game
    {
        foreach ($games as $game) {
            if ($event->source === $game->provider && $event->external_id === $game->id) {
                return $game;
            }
        }

        foreach ($games as $game) {
            if ($game->home->name === '' || abs($game->startsAt->diffInMinutes($event->starts_at, true)) > 360) {
                continue;
            }

            $home = ($game->home->id ? $this->teams->byId($league->key, $game->provider, $game->home->id) : null) ?? $this->teams->find($league->key, $game->home->name);
            $away = ($game->away->id ? $this->teams->byId($league->key, $game->provider, $game->away->id) : null) ?? $this->teams->find($league->key, $game->away->name);

            if ($home && $away && $home->id === $event->home_team_id && $away->id === $event->away_team_id) {
                return $game;
            }
        }

        return null;
    }

    /**
     * Results for games due one. Called every minute by the settlement run;
     * asks each league at most every 5 minutes, and only while it has a game
     * under way or awaiting its score.
     *
     * @return int games settled
     */
    public function results(bool $force = false): int
    {
        $settled = 0;
        $now = Carbon::now();

        foreach ($this->followed() as $league) {
            // Picks' own games are settled from Picks; feed games are settled
            // here whoever plays the league now. Locked games are staff's.
            $due = Event::query()->where('league_key', $league->key)->where('status', Event::OPEN)
                ->whereNotIn('source', ['manual', 'picks'])->where('locked', false)
                ->where('starts_at', '<=', $now)->where('starts_at', '>', $now->copy()->subDays(4))
                ->orderBy('starts_at')->get()
                // Postponed or cancelled: not asked about again for six hours.
                ->reject(fn (Event $e) => $this->cache->has('longshot.off.'.$e->id));

            if ($due->isEmpty() || ! $this->due($league, 'results', 5, $force)) {
                continue;
            }

            try {
                // Each game's result from the provider it came from, asking
                // only about the days that have a game due one.
                foreach ($due->groupBy('source') as $source => $events) {
                    $provider = $this->providers->get((string) $source);
                    if (! $provider instanceof Schedules || ! $this->providers->usable($provider->key())) {
                        continue;
                    }

                    $days = $events->map(fn (Event $e) => $e->starts_at->copy()->setTimezone('America/New_York')->format('Y-m-d'))->unique()->take(4);
                    foreach ($days as $day) {
                        $from = Carbon::parse($day.' 00:00', 'America/New_York')->utc();
                        $games = $provider->games($league, $from, $from->copy()->addHours(23));
                        $settled += (int) ($games === null ? 0 : $this->importer->apply($league, $games, false)['settled']);
                    }
                }

                // Games ending hours ago with no score yet (soccer after extra
                // time, a feed gone quiet): the results providers, hourly.
                $stale = $due->filter(fn (Event $e) => $e->starts_at->lt($now->copy()->subHours(5)) && $e->fresh()?->status === Event::OPEN);
                if ($stale->isNotEmpty() && $this->due($league, 'results-fallback', 60, false)) {
                    $settled += $this->fallbackResults($league, $stale->values()->all());
                }
            } catch (Throwable $e) {
                $this->log->error('[longshot] results for '.$league->key.' failed: '.$e->getMessage());
            }
        }

        return $settled;
    }

    /** @param list<Event> $events */
    private function fallbackResults(League $league, array $events): int
    {
        $settled = 0;

        // Six tries an event (hourly), then it is left for staff or the
        // auto-void: one stuck game must not spend a provider's whole quota.
        $events = array_values(array_filter($events, function (Event $e) {
            $key = 'longshot.fallback.'.$e->id;
            $this->cache->add($key, 0, 86400 * 4);

            return (int) $this->cache->increment($key) <= 6;
        }));

        if (! $events) {
            return 0;
        }

        foreach ($this->providers->for(Results::class, $league) as $provider) {
            $games = $provider->results($league, 3);
            if (! $games) {
                continue;
            }

            foreach ($events as $event) {
                $game = $this->match($event->fresh() ?? $event, $games, $league);
                if (! $game || ! $game->isFinal()) {
                    continue;
                }
                if ($event->fresh()?->status === Event::OPEN) {
                    $this->importer->settle($event, $game);
                    $settled++;
                }
            }
        }

        return $settled;
    }

    /** @return array<string, int|string|null> */
    private function brand(League $league): array
    {
        $filled = 0;
        $badge = null;

        foreach ($this->providers->for(Branding::class, $league) as $provider) {
            $teams = $provider->teams($league);
            foreach ((array) $teams as $side) {
                // Only teams the book already has, matched exactly (by the
                // provider's id, then name): a list is no reason to add teams.
                $team = $side->id ? $this->teams->byId($league->key, $provider->key(), $side->id) : null;
                foreach ($team ? [] : array_merge([$side->name], $side->aliases) as $name) {
                    if ($team = $this->teams->find($league->key, $name)) {
                        break;
                    }
                }
                if ($team) {
                    $this->teams->learn($team, $side, $provider->key());
                    $filled++;
                }
            }

            $badge ??= $provider->badge($league);
        }

        // Logos themselves are fetched by the scheduled run, a few at a time.
        return ['teams' => $filled, 'badge' => $this->logos->badge($league->key, $badge)];
    }
}
