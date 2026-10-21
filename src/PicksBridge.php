<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Games from Picks (ernestdefoe/picks), when it is installed.
 *
 * 🚨 Picks is the data layer: it already syncs fixtures, kickoffs and final
 * scores from CollegeFootballData and ESPN for every league it knows. Longshot
 * reads them from its tables and never fetches a score itself, so the two can
 * never disagree about who won, and no extra API quota is spent.
 */
class PicksBridge
{
    /** Picks' league keys, as Longshot's. */
    public const LEAGUES = ['cfb' => 'ncaaf', 'nfl' => 'nfl', 'nba' => 'nba', 'cbb' => 'ncaab', 'mlb' => 'mlb', 'nhl' => 'nhl', 'mls' => 'mls', 'epl' => 'epl', 'ucl' => 'ucl'];

    /** @var list<string>|null */
    private ?array $covered = null;

    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Container $container,
        private \ErnestDefoe\Longshot\Feed\Teams $teams,
        private \ErnestDefoe\Longshot\Feed\Logos $logos,
        private \Flarum\Http\UrlGenerator $url,
    ) {
    }

    /**
     * Longshot's keys for the leagues Picks is playing now (a season with a
     * game in the last month or ahead). Their fixtures and scores are Picks'.
     *
     * @return list<string>
     */
    public function leagues(): array
    {
        if ($this->covered !== null) {
            return $this->covered;
        }

        if (! $this->available() || ! $this->config->picksImport()) {
            return $this->covered = [];
        }

        $keys = $this->db->table('picks_seasons')
            ->join('picks_weeks', 'picks_weeks.season_id', '=', 'picks_seasons.id')
            ->join('picks_events', 'picks_events.week_id', '=', 'picks_weeks.id')
            ->where('picks_events.match_date', '>', Carbon::now()->subMonth())
            ->distinct()->pluck('picks_seasons.league')->all();

        return $this->covered = array_values(array_unique(array_filter(array_map(fn ($k) => self::LEAGUES[$k] ?? null, $keys))));
    }

    public function available(): bool
    {
        return $this->config->picksAvailable();
    }

    /**
     * The first of these events that Picks says has started, or null.
     *
     * @param list<Event> $events
     */
    public function startedAmong(array $events): ?Event
    {
        $linked = [];
        foreach ($events as $event) {
            if ($event->picks_event_id) {
                $linked[$event->picks_event_id] = $event;
            }
        }

        if (! $linked || ! $this->available()) {
            return null;
        }

        $started = $this->db->table('picks_events')
            ->whereIn('id', array_keys($linked))
            ->where(fn (Builder $q) => $q->where('status', '!=', 'scheduled')->orWhere('match_date', '<=', Carbon::now()))
            ->value('id');

        return $started ? $linked[(int) $started] : null;
    }

    /**
     * Offer the games Picks has coming up in the next few days. Returns how
     * many were added. Each game gets a moneyline, a spread and a total, priced
     * by the odds feed (or by an admin) before it can be bet on.
     */
    public function import(): int
    {
        if (! $this->available() || ! $this->config->picksImport()) {
            return 0;
        }

        $leagues = $this->config->picksLeagues();
        /** @var \Resofire\Picks\Service\Leagues\Leagues $registry */
        $registry = $this->container->make(\Resofire\Picks\Service\Leagues\Leagues::class);
        $added = 0;

        $this->db->table('picks_events')
            ->leftJoin('picks_weeks', 'picks_weeks.id', '=', 'picks_events.week_id')
            ->leftJoin('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
            ->leftJoin('longshot_events', 'longshot_events.picks_event_id', '=', 'picks_events.id')
            ->whereNull('longshot_events.id')
            ->where('picks_events.status', 'scheduled')
            ->whereBetween('picks_events.match_date', [Carbon::now(), Carbon::now()->addDays($this->config->horizonDays())])
            // A game entered by hand in Picks may have no week, and so no
            // season: Picks treats its league as the default one, and so do we.
            ->when($leagues, fn (Builder $q) => $q->whereIn(
                $this->db->raw('COALESCE('.$this->db->getQueryGrammar()->wrap('picks_seasons.league').", '".\Resofire\Picks\Service\Leagues\Leagues::DEFAULT."')"),
                $leagues
            ))
            ->orderBy('picks_events.id')
            ->select('picks_events.id', 'picks_events.match_date', 'picks_events.home_team_id', 'picks_events.away_team_id', 'picks_seasons.league')
            ->chunk(200, function ($rows) use ($registry, &$added) {
                $teams = \Resofire\Picks\Team::query()
                    ->whereIn('id', $rows->pluck('home_team_id')->merge($rows->pluck('away_team_id'))->unique()->all())
                    ->get()->keyBy('id');

                foreach ($rows as $row) {
                    $home = $teams->get($row->home_team_id);
                    $away = $teams->get($row->away_team_id);

                    if (! $home || ! $away) {
                        continue;
                    }

                    $league = $registry->get($row->league);
                    $key = self::LEAGUES[$league->key] ?? $league->key;
                    $added += (int) $this->create((int) $row->id, $league->name, $key, $league->sport === 'soccer', $home, $away, Carbon::parse($row->match_date));
                }
            });

        return $added;
    }

    private function create(int $picksId, string $league, string $key, bool $draw, \Resofire\Picks\Team $home, \Resofire\Picks\Team $away, Carbon $starts): bool
    {
        // The book's own teams, so Picks' games share logos, colours and the
        // duplicate check with every other source.
        $homeTeam = $this->teams->resolve($key, new \ErnestDefoe\Longshot\Feed\Side($home->name, $home->abbreviation, logo: $home->logo_url), 'picks');
        $awayTeam = $this->teams->resolve($key, new \ErnestDefoe\Longshot\Feed\Side($away->name, $away->abbreviation, logo: $away->logo_url), 'picks');

        // A feed already brought this game in (the league was followed before
        // Picks played it): one copy on the board, settled by its own feed.
        if ($this->db->table('longshot_events')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('home_team_id', $homeTeam->id)->where('away_team_id', $awayTeam->id))
                ->orWhere(fn ($q) => $q->where('home_team_id', $awayTeam->id)->where('away_team_id', $homeTeam->id)))
            ->whereBetween('starts_at', [$starts->copy()->subHours(6), $starts->copy()->addHours(6)])->exists()) {
            return false;
        }

        return $this->db->transaction(function () use ($picksId, $league, $key, $draw, $homeTeam, $awayTeam, $home, $away, $starts) {
            // Two imports at once: the unique picks_event_id lets one through.
            $id = $this->db->table('longshot_events')->insertOrIgnore([
                'league' => mb_substr($league, 0, 60),
                'league_key' => $key,
                'source' => 'picks',
                'external_id' => (string) $picksId,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'home_name' => mb_substr($homeTeam->name, 0, 100),
                'away_name' => mb_substr($awayTeam->name, 0, 100),
                'home_logo' => $this->logo($homeTeam, $home->logo_url),
                'away_logo' => $this->logo($awayTeam, $away->logo_url),
                'home_color' => $homeTeam->color,
                'away_color' => $awayTeam->color,
                'starts_at' => $starts,
                'status' => Event::OPEN,
                'picks_event_id' => $picksId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if (! $id) {
                return false;
            }

            $eventId = (int) $this->db->table('longshot_events')->where('picks_event_id', $picksId)->value('id');

            foreach ([Market::MONEYLINE, Market::SPREAD, Market::TOTAL] as $sort => $type) {
                $market = new Market();
                $market->event_id = $eventId;
                $market->type = $type;
                $market->feed = true;
                $market->status = Market::OPEN;
                $market->sort = $sort;
                $market->save();

                $keys = Market::KEYS[$type];
                if ($type === Market::MONEYLINE && ! $draw) {
                    $keys = ['away', 'home'];
                }

                foreach ($keys as $i => $key) {
                    $selection = new Selection();
                    $selection->market_id = $market->id;
                    $selection->key = $key;
                    $selection->sort = $i;
                    $selection->save();
                }
            }

            return true;
        });
    }

    /**
     * The cached copy of a team's logo; or Picks' own file when Picks serves
     * it from this forum (an uploaded crest). Never a third-party URL: those
     * are cached first (Logos::cacheTeams) and the event pointed at the copy.
     */
    private function logo(\ErnestDefoe\Longshot\Model\Team $team, ?string $url): ?string
    {
        if ($team->logo_path) {
            return $this->logos->url($team->logo_path);
        }

        $base = rtrim($this->url->to('forum')->base(), '/').'/';

        return $url && str_starts_with($url, $base) && strlen($url) <= 255 ? $url : null;
    }

    /**
     * What Picks knows that we do not yet: a kickoff that moved, a game under
     * way, a final score. Cheap when nothing changed: one query that returns
     * no rows.
     *
     * @return list<array{event: int, home: int, away: int}> games to settle
     */
    public function refresh(): array
    {
        if (! $this->available()) {
            return [];
        }

        $finals = [];
        $now = Carbon::now();

        $rows = $this->db->table('longshot_events')
            ->join('picks_events', 'picks_events.id', '=', 'longshot_events.picks_event_id')
            ->where('longshot_events.status', Event::OPEN)
            // Staff locked it: Picks moves and scores it no more.
            ->where('longshot_events.locked', false)
            ->where(fn (Builder $q) => $q
                ->whereColumn('picks_events.match_date', '!=', 'longshot_events.starts_at')
                ->orWhere('picks_events.status', '!=', 'scheduled'))
            ->select('longshot_events.id', 'longshot_events.starts_at', 'picks_events.match_date', 'picks_events.status', 'picks_events.home_score', 'picks_events.away_score')
            ->limit(500)
            ->get();

        foreach ($rows as $row) {
            $kickoff = Carbon::parse($row->match_date);
            $ours = Carbon::parse($row->starts_at);

            if ($row->status === 'finished' && $row->home_score !== null && $row->away_score !== null) {
                $finals[] = ['event' => (int) $row->id, 'home' => (int) $row->home_score, 'away' => (int) $row->away_score];
                continue;
            }

            // Under way (or locked by Picks) earlier than we thought: close it,
            // at Picks' kickoff if that has passed, or now if Picks closed early.
            if ($row->status !== 'scheduled' && $kickoff->gt($now)) {
                $kickoff = $now;
            }

            if (! $kickoff->equalTo($ours) && ($ours->gt($now) || $kickoff->gt($now))) {
                // A kickoff moved. Before it starts, it simply moves; a game
                // already closed reopens only if Picks says it has not started.
                if ($row->status === 'scheduled' || $kickoff->lte($now)) {
                    $this->db->table('longshot_events')->where('id', $row->id)->update(['starts_at' => $kickoff, 'updated_at' => $now]);
                }
            }
        }

        return $finals;
    }
}
