<?php

namespace ErnestDefoe\Longshot\Feed;

/**
 * The leagues Longshot knows how to follow end to end.
 *
 * The ids are each provider's own: ESPN's site path, The Odds API's sport
 * key, TheSportsDB's league id, API-Football's league id, and "cfbd" for the
 * one league CollegeFootballData covers. Adding a league is one line.
 */
final class Leagues
{
    /** @var array<string, League> */
    private array $leagues = [];

    public function __construct()
    {
        $this->add(new League('nfl', 'NFL', 'football', ['espn' => 'football/nfl', 'oddsapi' => 'americanfootball_nfl', 'sportsdb' => 4391, 'sportsdb_name' => 'NFL']));
        $this->add(new League('ncaaf', 'College football', 'football', ['espn' => 'football/college-football', 'oddsapi' => 'americanfootball_ncaaf', 'sportsdb' => 4479, 'sportsdb_name' => 'NCAA Division I Football', 'cfbd' => 'fbs']));
        $this->add(new League('nba', 'NBA', 'basketball', ['espn' => 'basketball/nba', 'oddsapi' => 'basketball_nba', 'sportsdb' => 4387, 'sportsdb_name' => 'NBA']));
        $this->add(new League('wnba', 'WNBA', 'basketball', ['espn' => 'basketball/wnba', 'oddsapi' => 'basketball_wnba', 'sportsdb' => 4516, 'sportsdb_name' => 'WNBA']));
        $this->add(new League('ncaab', 'College basketball', 'basketball', ['espn' => 'basketball/mens-college-basketball', 'oddsapi' => 'basketball_ncaab', 'sportsdb' => 4607, 'sportsdb_name' => 'NCAA Division I Basketball Mens']));
        $this->add(new League('mlb', 'MLB', 'baseball', ['espn' => 'baseball/mlb', 'oddsapi' => 'baseball_mlb', 'sportsdb' => 4424, 'sportsdb_name' => 'MLB']));
        $this->add(new League('nhl', 'NHL', 'hockey', ['espn' => 'hockey/nhl', 'oddsapi' => 'icehockey_nhl', 'sportsdb' => 4380, 'sportsdb_name' => 'NHL']));
        $this->add(new League('epl', 'Premier League', 'soccer', ['espn' => 'soccer/eng.1', 'oddsapi' => 'soccer_epl', 'sportsdb' => 4328, 'sportsdb_name' => 'English Premier League', 'apifootball' => 39], true));
        $this->add(new League('laliga', 'LaLiga', 'soccer', ['espn' => 'soccer/esp.1', 'oddsapi' => 'soccer_spain_la_liga', 'sportsdb' => 4335, 'sportsdb_name' => 'Spanish La Liga', 'apifootball' => 140], true));
        $this->add(new League('bundesliga', 'Bundesliga', 'soccer', ['espn' => 'soccer/ger.1', 'oddsapi' => 'soccer_germany_bundesliga', 'sportsdb' => 4331, 'sportsdb_name' => 'German Bundesliga', 'apifootball' => 78], true));
        $this->add(new League('seriea', 'Serie A', 'soccer', ['espn' => 'soccer/ita.1', 'oddsapi' => 'soccer_italy_serie_a', 'sportsdb' => 4332, 'sportsdb_name' => 'Italian Serie A', 'apifootball' => 135], true));
        $this->add(new League('ligue1', 'Ligue 1', 'soccer', ['espn' => 'soccer/fra.1', 'oddsapi' => 'soccer_france_ligue_one', 'sportsdb' => 4334, 'sportsdb_name' => 'French Ligue 1', 'apifootball' => 61], true));
        $this->add(new League('mls', 'MLS', 'soccer', ['espn' => 'soccer/usa.1', 'oddsapi' => 'soccer_usa_mls', 'sportsdb' => 4346, 'sportsdb_name' => 'American Major League Soccer', 'apifootball' => 253], true));
        $this->add(new League('ucl', 'Champions League', 'soccer', ['espn' => 'soccer/uefa.champions', 'oddsapi' => 'soccer_uefa_champs_league', 'sportsdb' => 4480, 'sportsdb_name' => 'UEFA Champions League', 'apifootball' => 2], true));
    }

    public function add(League $league): void
    {
        $this->leagues[$league->key] = $league;
    }

    public function get(?string $key): ?League
    {
        return $this->leagues[(string) $key] ?? null;
    }

    /** @return array<string, League> */
    public function all(): array
    {
        return $this->leagues;
    }

    /** @return array<string, string> key => name */
    public function choices(): array
    {
        return array_map(fn (League $l) => $l->name, $this->leagues);
    }
}
