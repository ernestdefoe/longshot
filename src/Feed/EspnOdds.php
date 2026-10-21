<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Grader;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Odds;
use Illuminate\Database\ConnectionInterface;

/**
 * Prices for games imported from Picks, read from ESPN's public scoreboard.
 *
 * Picks keeps fixtures and scores but not pre-game prices, so this is the one
 * thing Longshot fetches itself. It is the same scoreboard Picks reads,
 * fetched the same way, at most once per league per game day per run (every
 * 30 minutes), and only for days that have a game still open for bets.
 *
 * 🚨 No User-Agent header: ESPN answers Guzzle's own and refuses any custom
 * one with a 403 (see Picks' EspnProvider). `groups=80&limit=400` for college
 * football, or the bare URL returns a "featured" subset of the day's games.
 *
 * Only markets marked as feed-priced are touched, and never after kickoff:
 * an admin who set a price by hand keeps it.
 */
class EspnOdds
{
    public const BASE = 'https://site.api.espn.com/apis/site/v2/sports/';

    /** At most this many scoreboard calls per run, whatever is open. */
    public const MAX_CALLS = 40;

    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Providers\Espn $espn,
        private Pricer $pricer,
    ) {
    }

    /**
     * @param int $budget seconds to stop after, 0 for none: a staff member's
     *                    "sync now" is a web request and must answer.
     * @return array{calls: int, updated: int}
     */
    public function run(int $budget = 0): array
    {
        $began = microtime(true);

        if (! $this->config->picksAvailable() || ! $this->config->feedOdds()) {
            return ['calls' => 0, 'updated' => 0];
        }

        $now = Carbon::now();

        // Open, not started, imported from Picks, with a feed-priced market left.
        $rows = $this->db->table('longshot_events')
            ->join('picks_events', 'picks_events.id', '=', 'longshot_events.picks_event_id')
            ->leftJoin('picks_weeks', 'picks_weeks.id', '=', 'picks_events.week_id')
            ->leftJoin('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
            ->where('longshot_events.status', Event::OPEN)
            ->where('longshot_events.starts_at', '>', $now)
            ->whereExists(fn ($q) => $q->from('longshot_markets')
                ->whereColumn('longshot_markets.event_id', 'longshot_events.id')
                ->where('longshot_markets.feed', true)
                ->where('longshot_markets.status', Market::OPEN))
            ->select('longshot_events.id', 'longshot_events.starts_at', 'picks_events.external_id', 'picks_events.cfbd_id', 'picks_seasons.league')
            ->get();

        /** @var \Resofire\Picks\Service\Leagues\Leagues $registry */
        $registry = resolve(\Resofire\Picks\Service\Leagues\Leagues::class);
        $wanted = [];

        foreach ($rows as $row) {
            $espnId = (string) ($row->external_id ?: $row->cfbd_id);
            $league = $registry->get($row->league);

            if ($espnId === '' || $league->espnPath === '') {
                continue;
            }

            // ESPN's scoreboard days are US Eastern days.
            $day = Carbon::parse($row->starts_at)->setTimezone('America/New_York')->format('Ymd');
            $wanted[$league->espnPath.'|'.$day][$espnId] = (int) $row->id;
        }

        ksort($wanted);
        $calls = $updated = 0;

        foreach ($wanted as $key => $events) {
            if ($calls >= self::MAX_CALLS || ($budget > 0 && microtime(true) - $began > $budget)) {
                break;
            }

            [$path, $day] = explode('|', $key);
            $calls++;

            foreach ($this->fetch($path, $day) as $espnId => $found) {
                if (isset($events[$espnId])) {
                    $updated += $this->pricer->apply($events[$espnId], $found['prices'], $found['book']);
                }
            }
        }

        return ['calls' => $calls, 'updated' => $updated];
    }

    /** @return array<string, array<string, mixed>> ESPN event id => parsed prices */
    private function fetch(string $path, string $day): array
    {
        // Through the shared, budgeted client: every ESPN call Longshot makes
        // counts against one budget and one circuit breaker.
        $data = $this->espn->scoreboard($path, ['dates' => $day]);
        $out = [];

        foreach ((array) ($data['events'] ?? []) as $event) {
            $odds = ((array) (($event['competitions'][0] ?? [])['odds'] ?? []))[0] ?? null;

            if (is_array($odds) && isset($event['id'])) {
                $out[(string) $event['id']] = ['prices' => self::parse($odds), 'book' => ! empty($odds['provider']['name']) ? $odds['provider']['name'].' (via ESPN)' : null];
            }
        }

        return $out;
    }

    /**
     * One ESPN odds block, as {moneyline: {home, away, draw}, spread: {line,
     * home, away}, total: {line, over, under}}, current ("close") prices first.
     *
     * @param array<string, mixed> $odds
     * @return array<string, array<string, mixed>>
     */
    public static function parse(array $odds): array
    {
        $price = fn (array $side) => Odds::parse(($side['close']['odds'] ?? null) ?? ($side['open']['odds'] ?? null));
        $line = fn (array $side, string $strip = '') => Grader::normalise(ltrim((string) (($side['close']['line'] ?? null) ?? ($side['open']['line'] ?? '')), $strip));

        $ml = (array) ($odds['moneyline'] ?? []);
        $spread = (array) ($odds['pointSpread'] ?? []);
        $total = (array) ($odds['total'] ?? []);

        $draw = $odds['drawOdds'] ?? null;
        $drawPrice = is_array($draw) ? Odds::parse(isset($draw['moneyLine']) ? (int) $draw['moneyLine'] : null) : null;

        return [
            Market::MONEYLINE => [
                'home' => $price((array) ($ml['home'] ?? [])),
                'away' => $price((array) ($ml['away'] ?? [])),
                'draw' => $drawPrice,
            ],
            Market::SPREAD => [
                'line' => $line((array) ($spread['home'] ?? [])),
                'home' => $price((array) ($spread['home'] ?? [])),
                'away' => $price((array) ($spread['away'] ?? [])),
            ],
            Market::TOTAL => [
                'line' => $line((array) ($total['over'] ?? []), 'oOuU'),
                'over' => $price((array) ($total['over'] ?? [])),
                'under' => $price((array) ($total['under'] ?? [])),
            ],
        ];
    }
}
