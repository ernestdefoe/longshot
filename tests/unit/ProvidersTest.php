<?php

namespace ErnestDefoe\Longshot\Tests\unit;

use ErnestDefoe\Longshot\Feed\Game;
use ErnestDefoe\Longshot\Feed\League;
use ErnestDefoe\Longshot\Feed\Leagues;
use ErnestDefoe\Longshot\Feed\Providers\ApiFootball;
use ErnestDefoe\Longshot\Feed\Providers\Cfbd;
use ErnestDefoe\Longshot\Feed\Providers\Espn;
use ErnestDefoe\Longshot\Feed\Providers\OddsApi;
use ErnestDefoe\Longshot\Feed\Side;
use ErnestDefoe\Longshot\Model\Market;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProvidersTest extends TestCase
{
    private function espn(): Espn
    {
        return (new \ReflectionClass(Espn::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function espn_games_carry_teams_colours_logos_and_prices(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/../fixtures/espn-nfl-20261025.json'), true);
        $game = $this->espn()->game($data['events'][0], (new Leagues())->get('nfl'));

        $this->assertSame('401873011', $game->id);
        $this->assertSame('New Orleans Saints', $game->home->name);
        $this->assertSame('#d3bc8d', $game->home->color);
        $this->assertStringStartsWith('https://a.espncdn.com/', (string) $game->home->logo);
        $this->assertSame(Game::SCHEDULED, $game->status);
        $this->assertNotNull($game->prices[Market::MONEYLINE]['home']);
        $this->assertStringEndsWith('(via ESPN)', (string) $game->bookmaker);
    }

    #[Test]
    public function soccer_after_extra_time_is_not_settled_from_espns_total(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/../fixtures/espn-epl-20261018.json'), true);
        $event = $data['events'][0];
        $epl = (new Leagues())->get('epl');

        $this->assertSame(Game::FINAL, $this->espn()->game($event, $epl)->status);

        $event['competitions'][0]['status']['type']['name'] = 'STATUS_FINAL_AET';
        $this->assertSame(Game::LIVE, $this->espn()->game($event, $epl)->status, 'no 90-minute score to settle on');

        // With per-half scores, the first two halves are the result.
        foreach ($event['competitions'][0]['competitors'] as &$c) {
            $c['score'] = '3';
            $c['linescores'] = [['value' => 1], ['value' => 0], ['value' => 2]];
        }
        $game = $this->espn()->game($event, $epl);
        $this->assertSame(Game::FINAL, $game->status);
        $this->assertSame([1, 1], [$game->homeScore, $game->awayScore]);

        // Postponed is off, not final.
        $event['competitions'][0]['status']['type'] = ['name' => 'STATUS_POSTPONED', 'state' => 'post', 'completed' => true];
        $this->assertSame(Game::OFF, $this->espn()->game($event, $epl)->status);
    }

    #[Test]
    public function the_odds_api_takes_the_preferred_bookmaker_with_all_three_markets(): void
    {
        [$prices, $book] = OddsApi::best([
            ['key' => 'unknownbook', 'title' => 'Unknown', 'markets' => [['key' => 'h2h', 'outcomes' => [['name' => 'A', 'price' => 999], ['name' => 'B', 'price' => -999]]]]],
            ['key' => 'draftkings', 'title' => 'DraftKings', 'markets' => [
                ['key' => 'h2h', 'outcomes' => [['name' => 'A', 'price' => -150], ['name' => 'B', 'price' => 130], ['name' => 'Draw', 'price' => 240]]],
                ['key' => 'spreads', 'outcomes' => [['name' => 'A', 'price' => -110, 'point' => -1.5], ['name' => 'B', 'price' => -110, 'point' => 1.5]]],
                ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'price' => -105, 'point' => 2.5], ['name' => 'Under', 'price' => -115, 'point' => 2.5]]],
            ]],
        ], 'A', 'B');

        $this->assertSame('DraftKings', $book);
        $this->assertSame(['home' => -150, 'away' => 130, 'draw' => 240], $prices[Market::MONEYLINE]);
        $this->assertSame(['line' => '-1.5', 'home' => -110, 'away' => -110], $prices[Market::SPREAD]);
        $this->assertSame(['line' => '2.5', 'over' => -105, 'under' => -115], $prices[Market::TOTAL]);
    }

    #[Test]
    public function api_football_decimal_prices_become_american_and_the_main_lines_are_chosen(): void
    {
        $this->assertSame(-110, ApiFootball::american('1.91'));
        $this->assertSame(150, ApiFootball::american('2.50'));
        $this->assertSame(100, ApiFootball::american('2.00'));
        $this->assertNull(ApiFootball::american('1.00'));
        $this->assertNull(ApiFootball::american('abc'));
        $this->assertNull(ApiFootball::american('1.0001'), 'past -10000 is not a price');

        $prices = ApiFootball::parse([
            ['id' => 1, 'name' => 'Match Winner', 'values' => [['value' => 'Home', 'odd' => '2.10'], ['value' => 'Draw', 'odd' => '3.40'], ['value' => 'Away', 'odd' => '3.50']]],
            ['id' => 4, 'name' => 'Asian Handicap', 'values' => [
                ['value' => 'Home -1.5', 'odd' => '3.20'], ['value' => 'Away +1.5', 'odd' => '1.33'],
                ['value' => 'Home -0.5', 'odd' => '2.10'], ['value' => 'Away +0.5', 'odd' => '1.80'],
                ['value' => 'Home -0.25', 'odd' => '1.95'], ['value' => 'Away +0.25', 'odd' => '1.95'],
                ['value' => 'Home 0', 'odd' => '1.60'], ['value' => 'Away 0', 'odd' => '2.30'],
            ]],
            ['id' => 5, 'name' => 'Goals Over/Under', 'values' => [['value' => 'Over 2.5', 'odd' => '1.85'], ['value' => 'Under 2.5', 'odd' => '1.95'], ['value' => 'Over 3.5', 'odd' => '3.00'], ['value' => 'Under 3.5', 'odd' => '1.36']]],
        ]);

        $this->assertSame(['home' => 110, 'away' => 250, 'draw' => 240], $prices[Market::MONEYLINE]);
        // The quarter line (-0.25) is skipped; -0.5 is the closest-priced whole/half line.
        $this->assertSame('-0.5', $prices[Market::SPREAD]['line']);
        $this->assertSame([110, -125], [$prices[Market::SPREAD]['home'], $prices[Market::SPREAD]['away']]);
        $this->assertSame('2.5', $prices[Market::TOTAL]['line']);
    }

    #[Test]
    public function cfbd_lines_take_the_spread_and_total_at_minus_110(): void
    {
        $parsed = Cfbd::parse([['provider' => 'ESPN Bet', 'spread' => -7.5, 'overUnder' => 51.5, 'homeMoneyline' => -300, 'awayMoneyline' => 240]]);

        $this->assertSame('ESPN Bet', $parsed['book']);
        $this->assertSame(['home' => -300, 'away' => 240, 'draw' => null], $parsed['prices'][Market::MONEYLINE]);
        $this->assertSame(['line' => '-7.5', 'home' => -110, 'away' => -110], $parsed['prices'][Market::SPREAD]);
        $this->assertNull(Cfbd::parse([['provider' => 'x', 'spread' => null, 'overUnder' => null]]));
    }

    #[Test]
    public function team_names_match_across_providers(): void
    {
        $this->assertSame(Side::key('Manchester United F.C.'), Side::key('manchester united'));
        $this->assertSame('atletico madrid', Side::key('Atlético Madrid'));
        $this->assertSame('#a40227', Side::color('A40227'));
        $this->assertNull(Side::color('red'));
        $this->assertNull(Side::color('#12345'));
    }

    #[Test]
    public function every_league_has_espn_and_the_odds_api_and_soccer_has_a_draw(): void
    {
        foreach ((new Leagues())->all() as $league) {
            $this->assertNotNull($league->id('espn'), $league->key);
            $this->assertNotNull($league->id('oddsapi'), $league->key);
            $this->assertNotNull($league->id('sportsdb_name'), $league->key);
            $this->assertSame($league->sport === 'soccer', $league->draw, $league->key);
        }

        $this->assertSame(2026, ApiFootball::season(new League('epl', 'x', 'soccer', []), \Carbon\Carbon::parse('2026-10-21')));
        $this->assertSame(2025, ApiFootball::season(new League('epl', 'x', 'soccer', []), \Carbon\Carbon::parse('2026-03-01')));
        $this->assertSame(2026, ApiFootball::season(new League('mls', 'x', 'soccer', []), \Carbon\Carbon::parse('2026-03-01')));
    }
}
