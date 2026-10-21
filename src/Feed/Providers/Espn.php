<?php

namespace ErnestDefoe\Longshot\Feed\Providers;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Feed\Branding;
use ErnestDefoe\Longshot\Feed\EspnOdds;
use ErnestDefoe\Longshot\Feed\Game;
use ErnestDefoe\Longshot\Feed\Http;
use ErnestDefoe\Longshot\Feed\League;
use ErnestDefoe\Longshot\Feed\Provider;
use ErnestDefoe\Longshot\Feed\Schedules;
use ErnestDefoe\Longshot\Feed\Side;

/**
 * ESPN's public scoreboards: no key, every league here, and each day's page
 * carries the games, their status and scores, a sportsbook's prices and the
 * teams' logos and colours in one call.
 *
 * 🚨 `groups` matters for college sports: the bare college scoreboard is a
 * "featured" subset, and a game outside it simply does not exist (Picks lost
 * 62 of 86 games a Saturday to it). 80 is FBS, 50 is Division I basketball.
 *
 * Soccer settles on 90 minutes: a knockout game that goes to extra time is
 * scored from its first two periods, as every sportsbook does.
 */
class Espn extends Provider implements Schedules, Branding
{
    public const BASE = 'https://site.api.espn.com/apis/site/v2/sports/';

    public const GROUPS = ['football/college-football' => 80, 'basketball/mens-college-basketball' => 50];

    public function __construct(private Http $http)
    {
    }

    public function key(): string
    {
        return 'espn';
    }

    public function label(): string
    {
        return 'ESPN';
    }

    public function needsKey(): bool
    {
        return false;
    }

    public function termsUrl(): ?string
    {
        return 'https://disneytermsofuse.com/';
    }

    public function defaultBudget(): int
    {
        return 2000;
    }

    public function games(League $league, Carbon $from, Carbon $to): ?array
    {
        $path = (string) $league->id('espn');
        $games = [];
        $answered = false;

        // ESPN's scoreboard days are US Eastern days.
        $day = $from->copy()->setTimezone('America/New_York')->startOfDay();
        $last = $to->copy()->setTimezone('America/New_York')->startOfDay();

        for ($i = 0; $day->lte($last) && $i < 31; $i++, $day->addDay()) {
            $data = $this->scoreboard($path, ['dates' => $day->format('Ymd')]);

            if ($data === null) {
                continue;
            }

            $answered = true;
            foreach ((array) ($data['events'] ?? []) as $event) {
                if (is_array($event) && ($game = $this->game($event, $league))) {
                    $games[$game->id] = $game;
                }
            }
        }

        return $answered ? array_values($games) : null;
    }

    /** @param array<string, scalar> $query @return array<mixed>|null */
    public function scoreboard(string $path, array $query): ?array
    {
        $query += ['limit' => 400];
        if (isset(self::GROUPS[$path])) {
            $query['groups'] = self::GROUPS[$path];
        }

        return $this->http->json('espn', self::BASE.$path.'/scoreboard', $query);
    }

    /** @param array<string, mixed> $event */
    public function game(array $event, League $league): ?Game
    {
        $competition = (array) (($event['competitions'] ?? [[]])[0] ?? []);
        $sides = [];

        foreach ((array) ($competition['competitors'] ?? []) as $side) {
            if (is_array($side) && in_array($side['homeAway'] ?? '', ['home', 'away'], true)) {
                $sides[$side['homeAway']] = $side;
            }
        }

        if (! isset($sides['home'], $sides['away'], $event['id'], $event['date'])) {
            return null;
        }

        $type = (array) (($competition['status'] ?? $event['status'] ?? [])['type'] ?? []);
        $state = (string) ($type['state'] ?? 'pre');
        $name = strtoupper((string) ($type['name'] ?? ''));

        $status = match (true) {
            str_contains($name, 'POSTPONED') || str_contains($name, 'CANCELED') || str_contains($name, 'SUSPENDED') || str_contains($name, 'ABANDONED') || str_contains($name, 'FORFEIT') => Game::OFF,
            $state === 'post' && ! empty($type['completed']) => Game::FINAL,
            $state === 'in' || $state === 'post' => Game::LIVE,
            default => Game::SCHEDULED,
        };

        // 🚨 Soccer after extra time: ESPN's scoreboard has no per-half
        // scores, and the 90-minute result cannot be inferred (a two-legged
        // tie goes to extra time after a 1-0). Left for API-Football's
        // full-time score, or for staff; never guessed.
        $extraTime = $league->sport === 'soccer' && (str_contains($name, 'AET') || str_contains($name, 'PEN') || str_contains($name, 'EXTRA'));
        if ($status === Game::FINAL && $extraTime && ! $this->hasHalves($sides)) {
            $status = Game::LIVE;
        }

        $odds = ((array) ($competition['odds'] ?? []))[0] ?? null;

        return new Game(
            provider: 'espn',
            id: (string) $event['id'],
            league: $league->key,
            startsAt: Carbon::parse((string) $event['date'])->utc(),
            home: $this->side($sides['home'], $league),
            away: $this->side($sides['away'], $league),
            status: $status,
            homeScore: $status === Game::FINAL ? $this->score($sides['home'], $league) : null,
            awayScore: $status === Game::FINAL ? $this->score($sides['away'], $league) : null,
            prices: is_array($odds) ? EspnOdds::parse($odds) : null,
            bookmaker: is_array($odds) && ! empty($odds['provider']['name']) ? $odds['provider']['name'].' (via ESPN)' : null,
        );
    }

    /** @param array<string, mixed> $side */
    private function side(array $side, League $league): Side
    {
        $team = (array) ($side['team'] ?? []);

        return new Side(
            name: (string) ($team['displayName'] ?? $team['name'] ?? ''),
            abbreviation: isset($team['abbreviation']) ? mb_substr((string) $team['abbreviation'], 0, 10) : null,
            color: Side::color($team['color'] ?? null),
            altColor: Side::color($team['alternateColor'] ?? null),
            logo: is_string($team['logo'] ?? null) ? $team['logo'] : null,
            id: isset($team['id']) ? (string) $team['id'] : null,
            aliases: self::aliases($team, $league),
        );
    }

    /**
     * ESPN's own other names for a team: "Texas Tech" (its location, which is
     * what CollegeFootballData calls it) and "Red Raiders", both ESPN's.
     *
     * @param array<string, mixed> $team
     * @return list<string>
     */
    public static function aliases(array $team, League $league): array
    {
        // A city is a school's name in college sports, but two pro teams can
        // share one (New York, Los Angeles): college only.
        $keys = in_array($league->key, ['ncaaf', 'ncaab'], true) ? ['shortDisplayName', 'location'] : ['shortDisplayName'];

        return array_values(array_filter(array_map(fn ($k) => is_string($team[$k] ?? null) ? $team[$k] : null, $keys)));
    }

    /** @param array<string, array<string, mixed>> $sides */
    private function hasHalves(array $sides): bool
    {
        return count((array) ($sides['home']['linescores'] ?? [])) >= 2 && count((array) ($sides['away']['linescores'] ?? [])) >= 2;
    }

    /** @param array<string, mixed> $side */
    private function score(array $side, League $league): ?int
    {
        $lines = array_values(array_filter((array) ($side['linescores'] ?? []), 'is_array'));

        // Soccer: 90 minutes. Extra time and penalties are periods 3 and up.
        if ($league->sport === 'soccer' && count($lines) > 2) {
            return (int) round((float) ($lines[0]['value'] ?? 0) + (float) ($lines[1]['value'] ?? 0));
        }

        return is_numeric($side['score'] ?? null) ? (int) $side['score'] : null;
    }

    public function teams(League $league): ?array
    {
        $data = $this->http->json('espn', self::BASE.$league->id('espn').'/teams', ['limit' => 1000]);

        if ($data === null) {
            return null;
        }

        $out = [];
        foreach ((array) ($data['sports'][0]['leagues'][0]['teams'] ?? []) as $row) {
            $team = (array) ($row['team'] ?? []);
            if (empty($team['displayName'])) {
                continue;
            }

            $logo = null;
            foreach ((array) ($team['logos'] ?? []) as $l) {
                if (is_array($l) && is_string($l['href'] ?? null)) {
                    $logo = $l['href'];
                    break;
                }
            }

            $out[] = new Side((string) $team['displayName'], $team['abbreviation'] ?? null, Side::color($team['color'] ?? null), Side::color($team['alternateColor'] ?? null), $logo, isset($team['id']) ? (string) $team['id'] : null, self::aliases($team, $league));
        }

        return $out;
    }

    public function badge(League $league): ?string
    {
        $data = $this->scoreboard((string) $league->id('espn'), []);
        $logo = $data['leagues'][0]['logos'][0]['href'] ?? null;

        return is_string($logo) ? $logo : null;
    }
}
