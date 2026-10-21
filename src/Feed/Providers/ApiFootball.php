<?php

namespace ErnestDefoe\Longshot\Feed\Providers;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Feed\Game;
use ErnestDefoe\Longshot\Feed\Http;
use ErnestDefoe\Longshot\Feed\League;
use ErnestDefoe\Longshot\Feed\Prices;
use ErnestDefoe\Longshot\Feed\Provider;
use ErnestDefoe\Longshot\Feed\Providers as Registry;
use ErnestDefoe\Longshot\Feed\Schedules;
use ErnestDefoe\Longshot\Feed\Side;
use ErnestDefoe\Longshot\Grader as Lines;
use ErnestDefoe\Longshot\Model\Market;

/**
 * API-Football (api-football.com): soccer fixtures, their 90-minute results
 * (so knockout games settle correctly after extra time), team crests, and
 * Bet365's prices. 100 requests a day on the free plan.
 */
class ApiFootball extends Provider implements Schedules, Prices, \ErnestDefoe\Longshot\Feed\Results
{
    public const BASE = 'https://v3.football.api-sports.io/';

    /** Bet365, then 1xBet, then whoever has the market. */
    public const BOOKS = [8, 11];

    public function __construct(private Http $http, private Registry $providers)
    {
    }

    public function key(): string
    {
        return 'apifootball';
    }

    public function label(): string
    {
        return 'API-Football';
    }

    public function keyUrl(): ?string
    {
        return 'https://dashboard.api-football.com/register';
    }

    public function termsUrl(): ?string
    {
        return 'https://www.api-football.com/terms';
    }

    public function priceEvery(): int
    {
        return 180;
    }

    public function defaultBudget(): int
    {
        return 90;
    }

    /** European seasons are named for the year they start; MLS for the calendar year. */
    public static function season(League $league, Carbon $on): int
    {
        return $league->key === 'mls' || $on->month >= 7 ? $on->year : $on->year - 1;
    }

    /** @param array<string, scalar> $query @return array<mixed>|null */
    private function get(string $path, array $query): ?array
    {
        $data = $this->http->json('apifootball', self::BASE.$path, $query, ['x-apisports-key' => $this->providers->apiKey('apifootball')]);

        // It answers 200 with an "errors" object for a bad key or plan limit.
        if ($data !== null && ! empty($data['errors'])) {
            return null;
        }

        return $data;
    }

    public function games(League $league, Carbon $from, Carbon $to): ?array
    {
        $data = $this->get('fixtures', [
            'league' => (int) $league->id('apifootball'),
            'season' => self::season($league, $from),
            'from' => $from->copy()->utc()->format('Y-m-d'),
            'to' => $to->copy()->utc()->format('Y-m-d'),
            'timezone' => 'UTC',
        ]);

        if ($data === null) {
            return null;
        }

        $games = [];
        foreach ((array) ($data['response'] ?? []) as $row) {
            if ($game = $this->game((array) $row, $league)) {
                $games[] = $game;
            }
        }

        return $games;
    }

    /** Finished games of the last few days, on 90-minute scores (ESPN's soccer fallback). */
    public function results(League $league, int $days): ?array
    {
        $games = $this->games($league, Carbon::now()->subDays(max(1, min(3, $days))), Carbon::now());

        return $games === null ? null : array_values(array_filter($games, fn (Game $g) => $g->isFinal()));
    }

    /** @param array<string, mixed> $row */
    public function game(array $row, League $league): ?Game
    {
        $fixture = (array) ($row['fixture'] ?? []);
        $teams = (array) ($row['teams'] ?? []);

        if (empty($fixture['id']) || empty($fixture['date']) || empty($teams['home']['name']) || empty($teams['away']['name'])) {
            return null;
        }

        $short = strtoupper((string) ($fixture['status']['short'] ?? 'NS'));
        $status = match (true) {
            in_array($short, ['FT', 'AET', 'PEN'], true) => Game::FINAL,
            in_array($short, ['PST', 'CANC', 'ABD', 'AWD', 'WO', 'SUSP'], true) => Game::OFF,
            in_array($short, ['NS', 'TBD'], true) => Game::SCHEDULED,
            default => Game::LIVE,
        };

        // 90 minutes: `score.fulltime`, which excludes extra time and penalties.
        $full = (array) ($row['score']['fulltime'] ?? []);
        $home = is_numeric($full['home'] ?? null) ? (int) $full['home'] : null;
        $away = is_numeric($full['away'] ?? null) ? (int) $full['away'] : null;

        $side = fn (array $t) => new Side((string) $t['name'], logo: is_string($t['logo'] ?? null) ? $t['logo'] : null, id: isset($t['id']) ? (string) $t['id'] : null);

        return new Game('apifootball', (string) $fixture['id'], $league->key, Carbon::parse((string) $fixture['date'])->utc(),
            $side((array) $teams['home']), $side((array) $teams['away']),
            $status, $status === Game::FINAL ? $home : null, $status === Game::FINAL ? $away : null);
    }

    public function prices(League $league, Carbon $from, Carbon $to): ?array
    {
        // Odds are asked a day at a time; two days ahead is what a book needs
        // refreshed most, and keeps the free plan's 100 a day intact.
        $games = [];
        $answered = false;
        $day = $from->copy()->utc()->startOfDay();

        for ($i = 0; $i < 2 && $day->lte($to); $i++, $day->addDay()) {
            $data = $this->get('odds', [
                'league' => (int) $league->id('apifootball'),
                'season' => self::season($league, $day),
                'date' => $day->format('Y-m-d'),
                'bookmaker' => self::BOOKS[0],
            ]);

            if ($data === null) {
                continue;
            }

            $answered = true;
            foreach ((array) ($data['response'] ?? []) as $row) {
                $row = (array) $row;
                $prices = self::parse((array) ($row['bookmakers'][0]['bets'] ?? []));
                if (! empty($row['fixture']['id']) && $prices) {
                    // Matched to our events by API-Football's own fixture id.
                    $games[] = new Game('apifootball', (string) $row['fixture']['id'], $league->key, Carbon::parse((string) ($row['fixture']['date'] ?? 'now'))->utc(),
                        new Side(''), new Side(''), prices: $prices, bookmaker: ($row['bookmakers'][0]['name'] ?? 'Bet365').' (via API-Football)');
                }
            }
        }

        return $answered ? $games : null;
    }

    /**
     * Its decimal prices for "Match Winner" (1), "Asian Handicap" (4) and
     * "Goals Over/Under" (5), as American odds in our three markets. Only
     * whole and half lines: a quarter line is two bets, not one.
     *
     * @param array<int, mixed> $bets
     * @return array<string, array<string, mixed>>|null
     */
    public static function parse(array $bets): ?array
    {
        $by = [];
        foreach ($bets as $bet) {
            if (is_array($bet) && isset($bet['id'])) {
                $by[(int) $bet['id']] = (array) ($bet['values'] ?? []);
            }
        }

        $value = function (int $bet, string $name) use ($by): ?int {
            foreach ($by[$bet] ?? [] as $v) {
                if (is_array($v) && (string) ($v['value'] ?? '') === $name) {
                    return self::american($v['odd'] ?? null);
                }
            }

            return null;
        };

        // The main line: the pair of sides priced closest to each other.
        // $mirror says which point the other side carries: the handicap is
        // the opposite of home's, a total the same number.
        $pick = function (int $bet, string $a, string $b, bool $mirror) use ($by): array {
            $points = [];
            foreach ($by[$bet] ?? [] as $v) {
                if (is_array($v) && preg_match('/^(\w+) ([+-]?\d+(?:\.\d+)?)$/', trim((string) ($v['value'] ?? '')), $m)) {
                    $points[$m[1]][Lines::normalise($m[2]) ?? 'x'] = self::american($v['odd'] ?? null);
                }
            }

            $best = [null, null, null, PHP_INT_MAX];
            foreach ($points[$a] ?? [] as $line => $pa) {
                if ($line === 'x' || $pa === null) {
                    continue;
                }
                $other = $mirror ? Lines::normalise(-1 * (float) $line) : $line;
                $pb = $points[$b][$other] ?? null;
                if ($pb !== null && abs(abs($pa) - abs($pb)) < $best[3]) {
                    $best = [(string) $line, $pa, $pb, abs(abs($pa) - abs($pb))];
                }
            }

            return $best;
        };

        [$hLine, $hHome, $hAway] = $pick(4, 'Home', 'Away', true);
        [$tLine, $over, $under] = $pick(5, 'Over', 'Under', false);

        $prices = [
            Market::MONEYLINE => ['home' => $value(1, 'Home'), 'away' => $value(1, 'Away'), 'draw' => $value(1, 'Draw')],
            Market::SPREAD => ['line' => $hLine, 'home' => $hHome, 'away' => $hAway],
            Market::TOTAL => ['line' => $tLine, 'over' => $over, 'under' => $under],
        ];

        return $prices[Market::MONEYLINE]['home'] === null && $tLine === null && $hLine === null ? null : $prices;
    }

    /** Decimal odds ("1.91") as American (-110), or null. */
    public static function american(mixed $decimal): ?int
    {
        if (! is_numeric($decimal) || (float) $decimal <= 1.0) {
            return null;
        }

        $d = (float) $decimal;
        $american = $d >= 2.0 ? (int) round(($d - 1) * 100) : (int) round(-100 / ($d - 1));

        return \ErnestDefoe\Longshot\Odds::valid($american) ? $american : null;
    }
}
