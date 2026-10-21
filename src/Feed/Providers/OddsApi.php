<?php

namespace ErnestDefoe\Longshot\Feed\Providers;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Feed\Game;
use ErnestDefoe\Longshot\Feed\Http;
use ErnestDefoe\Longshot\Feed\League;
use ErnestDefoe\Longshot\Feed\Prices;
use ErnestDefoe\Longshot\Feed\Providers as Registry;
use ErnestDefoe\Longshot\Feed\Results;
use ErnestDefoe\Longshot\Feed\Side;
use ErnestDefoe\Longshot\Grader as Lines;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Odds;

/**
 * The Odds API (the-odds-api.com): prices from dozens of bookmakers for
 * every league here, and final scores for the last three days.
 *
 * 🚨 Billed in credits, not calls: an odds request costs one credit per
 * market per region (three markets: 3), a scores request 2. The free tier is
 * 500 credits a month, so the budget here is in credits and the sync asks it
 * only for games ESPN could not price.
 */
class OddsApi extends \ErnestDefoe\Longshot\Feed\Provider implements Prices, Results
{
    public const BASE = 'https://api.the-odds-api.com/v4/sports/';

    /** Bookmakers preferred, in order, when several price a game. */
    public const BOOKS = ['draftkings', 'fanduel', 'betmgm', 'williamhill_us', 'betrivers', 'pinnacle', 'bet365', 'williamhill', 'paddypower', 'unibet_eu'];

    public function __construct(private Http $http, private Registry $providers)
    {
    }

    public function key(): string
    {
        return 'oddsapi';
    }

    public function label(): string
    {
        return 'The Odds API';
    }

    public function keyUrl(): ?string
    {
        return 'https://the-odds-api.com/#get-access';
    }

    public function termsUrl(): ?string
    {
        return 'https://the-odds-api.com/terms-and-conditions.html';
    }

    public function priceEvery(): int
    {
        return 360;
    }

    public function defaultBudget(): int
    {
        return 450;
    }

    public function budgetPeriod(): string
    {
        return 'month';
    }

    public function prices(League $league, Carbon $from, Carbon $to): ?array
    {
        $data = $this->http->json('oddsapi', self::BASE.$league->id('oddsapi').'/odds', [
            'apiKey' => $this->providers->apiKey('oddsapi'),
            'regions' => $league->sport === 'soccer' ? 'uk' : 'us',
            'markets' => 'h2h,spreads,totals',
            'oddsFormat' => 'american',
            'commenceTimeFrom' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'commenceTimeTo' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], [], 3);

        if ($data === null) {
            return null;
        }

        $games = [];
        foreach ($data as $event) {
            if (! is_array($event) || empty($event['id']) || empty($event['home_team']) || empty($event['away_team'])) {
                continue;
            }

            [$prices, $book] = self::best((array) ($event['bookmakers'] ?? []), (string) $event['home_team'], (string) $event['away_team']);

            $games[] = new Game('oddsapi', (string) $event['id'], $league->key, Carbon::parse((string) $event['commence_time'])->utc(),
                new Side((string) $event['home_team']), new Side((string) $event['away_team']),
                prices: $prices, bookmaker: $book ? $book.' (via The Odds API)' : null);
        }

        return $games;
    }

    /**
     * The first preferred bookmaker's prices for the three markets.
     *
     * @param array<int, mixed> $books
     * @return array{0: array<string, array<string, mixed>>|null, 1: string|null}
     */
    public static function best(array $books, string $home, string $away): array
    {
        usort($books, function ($a, $b) {
            $ia = array_search($a['key'] ?? '', self::BOOKS, true);
            $ib = array_search($b['key'] ?? '', self::BOOKS, true);

            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });

        foreach ($books as $book) {
            $markets = [];
            foreach ((array) ($book['markets'] ?? []) as $m) {
                if (is_array($m) && isset($m['key'])) {
                    $markets[$m['key']] = (array) ($m['outcomes'] ?? []);
                }
            }

            if (! $markets) {
                continue;
            }

            $find = fn (string $market, string $name) => collect($markets[$market] ?? [])->first(fn ($o) => is_array($o) && ($o['name'] ?? null) === $name);
            $price = fn (?array $o) => $o ? Odds::parse(is_numeric($o['price'] ?? null) ? (int) $o['price'] : null) : null;

            $homeSpread = $find('spreads', $home);
            $over = $find('totals', 'Over');

            return [[
                Market::MONEYLINE => ['home' => $price($find('h2h', $home)), 'away' => $price($find('h2h', $away)), 'draw' => $price($find('h2h', 'Draw'))],
                Market::SPREAD => ['line' => $homeSpread ? Lines::normalise($homeSpread['point'] ?? null) : null, 'home' => $price($homeSpread), 'away' => $price($find('spreads', $away))],
                Market::TOTAL => ['line' => $over ? Lines::normalise($over['point'] ?? null) : null, 'over' => $price($over), 'under' => $price($find('totals', 'Under'))],
            ], (string) ($book['title'] ?? $book['key'] ?? '')];
        }

        return [null, null];
    }

    public function results(League $league, int $days): ?array
    {
        // Its soccer scores include extra time; soccer settles on 90 minutes.
        if ($league->sport === 'soccer') {
            return [];
        }

        $data = $this->http->json('oddsapi', self::BASE.$league->id('oddsapi').'/scores', [
            'apiKey' => $this->providers->apiKey('oddsapi'),
            'daysFrom' => max(1, min(3, $days)),
        ], [], 2);

        if ($data === null) {
            return null;
        }

        $games = [];
        foreach ($data as $event) {
            if (! is_array($event) || empty($event['completed']) || empty($event['home_team'])) {
                continue;
            }

            $scores = [];
            foreach ((array) ($event['scores'] ?? []) as $s) {
                if (is_array($s) && is_numeric($s['score'] ?? null)) {
                    $scores[(string) $s['name']] = (int) $s['score'];
                }
            }

            $home = $scores[$event['home_team']] ?? null;
            $away = $scores[$event['away_team']] ?? null;

            $games[] = new Game('oddsapi', (string) $event['id'], $league->key, Carbon::parse((string) $event['commence_time'])->utc(),
                new Side((string) $event['home_team']), new Side((string) $event['away_team']),
                $home !== null && $away !== null ? Game::FINAL : Game::LIVE, $home, $away);
        }

        return $games;
    }
}
