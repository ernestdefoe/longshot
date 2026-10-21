<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Grader;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Odds;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

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
        private LoggerInterface $log,
        private ?Client $http = null
    ) {
    }

    /** @return array{calls: int, updated: int} */
    public function run(): array
    {
        if (! $this->config->picksAvailable() || ! $this->config->feedOdds()) {
            return ['calls' => 0, 'updated' => 0];
        }

        $now = Carbon::now();

        // Open, not started, imported from Picks, with a feed-priced market left.
        $rows = $this->db->table('longshot_events')
            ->join('picks_events', 'picks_events.id', '=', 'longshot_events.picks_event_id')
            ->join('picks_weeks', 'picks_weeks.id', '=', 'picks_events.week_id')
            ->join('picks_seasons', 'picks_seasons.id', '=', 'picks_weeks.season_id')
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
            if ($calls >= self::MAX_CALLS) {
                break;
            }

            [$path, $day] = explode('|', $key);
            $calls++;

            foreach ($this->fetch($path, $day) as $espnId => $prices) {
                if (isset($events[$espnId])) {
                    $updated += $this->apply($events[$espnId], $prices);
                }
            }
        }

        return ['calls' => $calls, 'updated' => $updated];
    }

    /** @return array<string, array<string, mixed>> ESPN event id => parsed prices */
    private function fetch(string $path, string $day): array
    {
        $query = ['dates' => $day, 'limit' => 400];
        if ($path === 'football/college-football') {
            $query['groups'] = 80;
        }

        try {
            $response = ($this->http ?? new Client())->get(self::BASE.$path.'/scoreboard', [
                'query' => $query,
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable $e) {
            $this->log->warning('[longshot] ESPN odds fetch failed for '.$path.' '.$day.': '.$e->getMessage());

            return [];
        }

        if ($response->getStatusCode() !== 200) {
            // The status is the diagnosis: a 403 is a header problem, not an outage.
            $this->log->warning('[longshot] ESPN odds fetch for '.$path.' '.$day.' answered HTTP '.$response->getStatusCode());

            return [];
        }

        $data = json_decode((string) $response->getBody(), true);
        $out = [];

        foreach ((array) ($data['events'] ?? []) as $event) {
            $odds = ((array) (($event['competitions'][0] ?? [])['odds'] ?? []))[0] ?? null;

            if (is_array($odds) && isset($event['id'])) {
                $out[(string) $event['id']] = self::parse($odds);
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

    /**
     * Write one game's prices to its feed-priced markets. A market the feed
     * has no complete price for keeps what it had.
     *
     * @param array<string, array<string, mixed>> $prices
     */
    private function apply(int $eventId, array $prices): int
    {
        $updated = 0;

        $markets = Market::query()->where('event_id', $eventId)->where('feed', true)->where('status', Market::OPEN)->with('selections')->get();

        foreach ($markets as $market) {
            $feed = $prices[$market->type] ?? null;

            if (! $feed) {
                continue;
            }

            $selections = $market->selections->keyBy('key');
            $complete = $selections->every(fn ($s) => isset($feed[$s->key]))
                && (! $market->hasLine() || $feed['line'] !== null);

            if (! $complete) {
                continue;
            }

            $this->db->transaction(function () use ($market, $selections, $feed, &$updated) {
                if ($market->hasLine() && Grader::normalise($market->line) !== $feed['line']) {
                    $market->line = $feed['line'];
                    $market->save();
                    $updated++;
                }

                foreach ($selections as $key => $selection) {
                    if ($selection->odds !== $feed[$key]) {
                        $selection->odds = $feed[$key];
                        $selection->save();
                        $updated++;
                    }
                }
            });
        }

        return $updated;
    }
}
