<?php

namespace ErnestDefoe\Longshot\Feed\Providers;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Feed\Game;
use ErnestDefoe\Longshot\Feed\Http;
use ErnestDefoe\Longshot\Feed\League;
use ErnestDefoe\Longshot\Feed\Prices;
use ErnestDefoe\Longshot\Feed\Provider;
use ErnestDefoe\Longshot\Feed\Providers as Registry;
use ErnestDefoe\Longshot\Feed\Side;
use ErnestDefoe\Longshot\Grader as Lines;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Odds;

/**
 * CollegeFootballData's betting lines, for college football when ESPN has
 * none for a game (it prices the featured games, not all of FBS).
 *
 * 🚨 Uses Picks' CFBD key when Longshot has none of its own, so a forum that
 * already set one up needs nothing more. The free tier is 1,000 calls a month
 * and Picks spends from the same key, so the budget here is small by default:
 * one call fetches a whole season's lines.
 */
class Cfbd extends Provider implements Prices
{
    public const BASE = 'https://api.collegefootballdata.com/';

    public function __construct(private Http $http, private Registry $providers)
    {
    }

    public function key(): string
    {
        return 'cfbd';
    }

    public function label(): string
    {
        return 'CollegeFootballData';
    }

    public function keyUrl(): ?string
    {
        return 'https://collegefootballdata.com/key';
    }

    public function termsUrl(): ?string
    {
        return 'https://collegefootballdata.com/terms';
    }

    public function priceEvery(): int
    {
        return 1440;
    }

    public function defaultBudget(): int
    {
        return 60;
    }

    public function budgetPeriod(): string
    {
        return 'month';
    }

    public function apiKey(): string
    {
        return $this->providers->apiKey('cfbd');
    }

    public function prices(League $league, Carbon $from, Carbon $to): ?array
    {
        $data = $this->http->json('cfbd', self::BASE.'lines', [
            'year' => $from->month >= 2 ? $from->year : $from->year - 1,
        ], ['Authorization' => 'Bearer '.$this->apiKey()]);

        if ($data === null) {
            return null;
        }

        $games = [];
        foreach ($data as $row) {
            if (! is_array($row) || empty($row['id']) || empty($row['homeTeam']) || empty($row['startDate'])) {
                continue;
            }

            $start = Carbon::parse((string) $row['startDate'])->utc();
            if ($start->lt($from) || $start->gt($to)) {
                continue;
            }

            if ($prices = self::parse((array) ($row['lines'] ?? []))) {
                $games[] = new Game('cfbd', (string) $row['id'], $league->key, $start,
                    new Side((string) $row['homeTeam']), new Side((string) $row['awayTeam']),
                    prices: $prices['prices'], bookmaker: $prices['book'].' (via CollegeFootballData)');
            }
        }

        return $games;
    }

    /**
     * The first line with a spread or total. CFBD gives moneylines but not
     * the spread's and total's prices, which are taken as the usual -110.
     *
     * @param array<int, mixed> $lines
     * @return array{prices: array<string, array<string, mixed>>, book: string}|null
     */
    public static function parse(array $lines): ?array
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $spread = Lines::normalise($line['spread'] ?? null);
            $total = Lines::normalise($line['overUnder'] ?? null);

            if ($spread === null && $total === null) {
                continue;
            }

            $ml = fn ($v) => is_numeric($v) ? Odds::parse((int) $v) : null;

            return ['book' => (string) ($line['provider'] ?? 'consensus'), 'prices' => [
                Market::MONEYLINE => ['home' => $ml($line['homeMoneyline'] ?? null), 'away' => $ml($line['awayMoneyline'] ?? null), 'draw' => null],
                Market::SPREAD => ['line' => $spread, 'home' => $spread === null ? null : -110, 'away' => $spread === null ? null : -110],
                Market::TOTAL => ['line' => $total, 'over' => $total === null ? null : -110, 'under' => $total === null ? null : -110],
            ]];
        }

        return null;
    }
}
