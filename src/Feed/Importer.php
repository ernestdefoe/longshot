<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Model\Team;
use ErnestDefoe\Longshot\Settlement;
use Illuminate\Database\ConnectionInterface;

/**
 * Turns a provider's games into the book's events, and keeps them current.
 *
 * - A game first seen, scheduled and inside the horizon, becomes an event
 *   with a moneyline (a draw where the sport has one), a spread and a total.
 * - A known game moves when its kickoff moves, closes when it is under way,
 *   and is settled when it has a final score.
 * - 🚨 Staff win: a locked event keeps its sides, kickoff and status; a hidden
 *   one is never shown or brought back; a market taken off the feed keeps its
 *   price. Prices stop at kickoff.
 */
class Importer
{
    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Teams $teams,
        private Pricer $pricer,
        private Logos $logos,
        private Settlement $settlement,
        private \Illuminate\Contracts\Cache\Repository $cache,
    ) {
    }

    /**
     * @param list<Game> $games
     * @return array{added: int, updated: int, settled: int, priced: int}
     */
    public function apply(League $league, array $games, bool $add = true): array
    {
        $done = ['added' => 0, 'updated' => 0, 'settled' => 0, 'priced' => 0];
        if (! $games) {
            return $done;
        }

        $byProvider = [];
        foreach ($games as $game) {
            $byProvider[$game->provider][] = $game;
        }

        $now = Carbon::now();
        $horizon = $now->copy()->addDays($this->config->horizonDays());

        foreach ($byProvider as $provider => $list) {
            $known = Event::query()->where('source', $provider)
                ->whereIn('external_id', array_map(fn (Game $g) => $g->id, $list))
                ->get()->keyBy('external_id');

            foreach ($list as $game) {
                $event = $known->get($game->id);

                if (! $event) {
                    if ($add && $game->status === Game::SCHEDULED && $game->startsAt->gt($now) && $game->startsAt->lte($horizon) && ! $this->duplicate($league, $game)) {
                        $event = $this->create($league, $game);
                        $done['added']++;
                    } else {
                        continue;
                    }
                } elseif ($event->status === Event::OPEN && ! $event->locked) {
                    $done['updated'] += (int) $this->update($event, $game, $now);
                }

                // Settled, void or hidden by staff: nothing more to do.
                if ($event->status !== Event::OPEN) {
                    continue;
                }

                // Prices until kickoff, never after; and not on a locked game,
                // whose sides staff may have corrected.
                if ($game->prices && ! $event->locked && $event->starts_at->gt($now) && $game->status === Game::SCHEDULED) {
                    $done['priced'] += (int) ($this->pricer->apply($event->id, $game->prices, $game->bookmaker) > 0);
                }

                // Postponed or cancelled: stop asking about it for six hours.
                if ($game->status === Game::OFF) {
                    $this->cache->put('longshot.off.'.$event->id, 1, 6 * 3600);
                }

                // A final score settles it once it has started. 🚨 Not a locked
                // one: staff may have corrected its sides, and the feed's home
                // score would be paid to the wrong team. Staff score those.
                if ($game->isFinal() && $event->starts_at->lte($now) && ! $event->locked) {
                    $this->settle($event, $game);
                    $done['settled']++;
                }
            }
        }

        return $done;
    }

    /**
     * Whether another provider already brought this game in: the same two
     * teams within six hours. A failover provider must not double the board.
     */
    private function duplicate(League $league, Game $game): bool
    {
        $home = ($game->home->id ? $this->teams->byId($league->key, $game->provider, $game->home->id) : null) ?? $this->teams->find($league->key, $game->home->name);
        $away = ($game->away->id ? $this->teams->byId($league->key, $game->provider, $game->away->id) : null) ?? $this->teams->find($league->key, $game->away->name);

        // Either way round: a neutral-site game may be listed with sides swapped.
        return $home && $away && Event::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('home_team_id', $home->id)->where('away_team_id', $away->id))
                ->orWhere(fn ($q) => $q->where('home_team_id', $away->id)->where('away_team_id', $home->id)))
            ->whereBetween('starts_at', [$game->startsAt->copy()->subHours(6), $game->startsAt->copy()->addHours(6)])
            ->exists();
    }

    /** Score a game between two sides from a provider's final. */
    public function settle(Event $event, Game $game): void
    {
        // Locked is read now, not from the copy loaded at the start of the run.
        if ($event->isMatchup() && $game->isFinal() && ! $event->fresh()?->locked) {
            $this->settlement->score($event, (int) $game->homeScore, (int) $game->awayScore);
        }
    }

    private function create(League $league, Game $game): Event
    {
        return $this->db->transaction(function () use ($league, $game) {
            $home = $this->teams->resolve($league->key, $game->home, $game->provider);
            $away = $this->teams->resolve($league->key, $game->away, $game->provider);

            $event = new Event();
            $event->league = $league->name;
            $event->league_key = $league->key;
            $event->source = $game->provider;
            $event->external_id = mb_substr($game->id, 0, 64);
            $event->home_team_id = $home->id;
            $event->away_team_id = $away->id;
            $event->home_name = $home->name;
            $event->away_name = $away->name;
            $event->home_logo = $this->logos->url($home->logo_path);
            $event->away_logo = $this->logos->url($away->logo_path);
            $event->home_color = $home->color;
            $event->away_color = $away->color;
            $event->starts_at = $game->startsAt;
            $event->status = Event::OPEN;
            $event->save();

            foreach ([Market::MONEYLINE, Market::SPREAD, Market::TOTAL] as $sort => $type) {
                $market = new Market();
                $market->event_id = $event->id;
                $market->type = $type;
                $market->feed = true;
                $market->status = Market::OPEN;
                $market->sort = $sort;
                $market->save();

                $keys = $type === Market::MONEYLINE ? ($league->draw ? ['away', 'home', 'draw'] : ['away', 'home']) : Market::KEYS[$type];
                Selection::query()->insert(array_map(fn ($key, $i) => ['market_id' => $market->id, 'key' => $key, 'sort' => $i], $keys, array_keys($keys)));
            }

            return $event;
        });
    }

    /** Kickoff moved, or the game is under way before its kickoff. */
    private function update(Event $event, Game $game, Carbon $now): bool
    {
        $starts = $game->startsAt;

        // Under way (or finished) earlier than we thought: close it now.
        if ($game->status !== Game::SCHEDULED && $game->status !== Game::OFF && $event->starts_at->gt($now)) {
            $starts = $starts->lt($now) ? $starts : $now->copy();
        }

        // A kickoff that moved before the game started simply moves; one in
        // the past only moves if the game has not started (postponed).
        if ($starts->equalTo($event->starts_at) || ($event->starts_at->lte($now) && $game->status !== Game::SCHEDULED && $starts->gt($now))) {
            return false;
        }

        $event->starts_at = $starts;
        $event->save();

        return true;
    }

    /** Keep an imported event's names and logos in step with its teams (after staff edit a team). */
    public function refreshNames(Team $team): void
    {
        $url = $this->logos->url($team->logo_path);

        foreach (['home', 'away'] as $side) {
            $this->db->table('longshot_events')->where($side.'_team_id', $team->id)->where('locked', false)
                ->update([$side.'_name' => $team->name, $side.'_logo' => $url, $side.'_color' => $team->color]);
        }
    }
}
