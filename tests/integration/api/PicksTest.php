<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Feed\EspnOdds;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\PicksBridge;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/** Games, kickoffs and results from Picks; prices from ESPN's scoreboard. */
class PicksTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.allowance', 0);

        $kickoff = Carbon::now()->addDay()->startOfHour();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'picks_teams' => [
                ['id' => 1, 'name' => 'Pittsburgh Steelers', 'slug' => 'pit', 'abbreviation' => 'PIT', 'logo_path' => 'https://a.espncdn.com/i/teamlogos/nfl/500/pit.png', 'logo_custom' => 0],
                ['id' => 2, 'name' => 'Cleveland Browns', 'slug' => 'cle', 'abbreviation' => 'CLE', 'logo_path' => 'javascript:alert(1)', 'logo_custom' => 0],
            ],
            'picks_seasons' => [['id' => 1, 'name' => 'NFL 2026', 'slug' => 'nfl-2026', 'year' => 2026, 'league' => 'nfl']],
            'picks_weeks' => [['id' => 1, 'season_id' => 1, 'name' => 'Week 8', 'week_number' => 8, 'season_type' => 'regular']],
            'picks_events' => [
                ['id' => 10, 'week_id' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'external_id' => '401772', 'match_date' => $kickoff, 'cutoff_date' => $kickoff, 'status' => 'scheduled'],
                // Too far out to offer yet.
                ['id' => 11, 'week_id' => 1, 'home_team_id' => 2, 'away_team_id' => 1, 'external_id' => '401773', 'match_date' => Carbon::now()->addDays(20), 'cutoff_date' => Carbon::now()->addDays(20), 'status' => 'scheduled'],
            ],
        ]);
    }

    /**
     * Saving the event form writes each price that changed: a form of six
     * prices is six updates, by design, not a lookup per record.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['longshot_selections" set', 'longshot_selections` set'];
    }

    private function bridge(): PicksBridge
    {
        return $this->app()->getContainer()->make(PicksBridge::class);
    }

    /** The odds feed, answering with one scoreboard for each call. */
    private function feed(array $events, int $status = 200): EspnOdds
    {
        $mock = new MockHandler(array_fill(0, 5, new Response($status, [], json_encode(['events' => $events]))));
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->sent));
        $this->app()->getContainer()->instance(Client::class, new Client(['handler' => $stack]));

        return $this->app()->getContainer()->make(EspnOdds::class);
    }

    private function espn(string $id, string $homeMl = '-180', string $awayMl = '+150', string $spread = '-3.5', string $total = '41.5'): array
    {
        $away = (string) (-1 * (float) $spread);

        return ['id' => $id, 'competitions' => [['odds' => [[
            'moneyline' => ['home' => ['close' => ['odds' => $homeMl]], 'away' => ['close' => ['odds' => $awayMl]]],
            'pointSpread' => [
                'home' => ['close' => ['line' => $spread, 'odds' => '-110']],
                'away' => ['close' => ['line' => ($away > 0 ? '+' : '').$away, 'odds' => '-110']],
            ],
            'total' => [
                'over' => ['close' => ['line' => 'o'.$total, 'odds' => '-105']],
                'under' => ['close' => ['line' => 'u'.$total, 'odds' => '-115']],
            ],
        ]]]]];
    }

    #[Test]
    public function upcoming_games_are_imported_once_with_three_unpriced_markets(): void
    {
        $this->assertSame(1, $this->bridge()->import());
        $this->assertSame(0, $this->bridge()->import());

        $event = Event::query()->with('markets.selections')->firstOrFail();
        $this->assertSame(10, $event->picks_event_id);
        $this->assertSame('NFL', $event->league);
        $this->assertSame('Pittsburgh Steelers', $event->home_name);
        // Never hotlinked: an ESPN crest is queued for the local cache, a
        // javascript: "logo" is not kept at all.
        $this->assertNull($event->home_logo);
        $this->assertSame('https://a.espncdn.com/i/teamlogos/nfl/500/pit.png', \ErnestDefoe\Longshot\Model\Team::query()->find($event->home_team_id)->logo_source);
        $this->assertNull(\ErnestDefoe\Longshot\Model\Team::query()->find($event->away_team_id)->logo_source);
        $this->assertSame(['nfl'], $this->app()->getContainer()->make(PicksBridge::class)->leagues());
        $this->assertSame([Market::MONEYLINE, Market::SPREAD, Market::TOTAL], $event->markets->pluck('type')->all());
        $this->assertSame([['away', 'home'], ['away', 'home'], ['over', 'under']], $event->markets->map(fn ($m) => $m->selections->pluck('key')->all())->all());
        $this->assertTrue($event->markets->every(fn ($m) => $m->feed && $m->selections->every(fn ($s) => $s->odds === null)));

        // No price yet: no bets.
        $this->balance(2);
        $home = $event->markets[0]->selections[1];
        $this->assertSame('longshot_closed', $this->json($this->bet(2, [['selection' => $home->id, 'odds' => -150, 'line' => null, 'stake' => 10]]))['errors'][0]['code']);
    }

    #[Test]
    public function the_feed_prices_imported_games_and_leaves_hand_set_markets_alone(): void
    {
        $this->bridge()->import();
        $event = Event::query()->firstOrFail();
        $ml = Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->firstOrFail();

        $result = $this->feed([$this->espn('401772'), $this->espn('999')])->run();
        $this->assertSame(1, $result['calls']);
        $this->assertCount(1, $this->sent);
        $sent = $this->sent[0]['request'];
        $query = $sent->getUri()->getQuery();
        $this->assertStringContainsString('football/nfl/scoreboard', (string) $sent->getUri());
        $this->assertStringContainsString('dates='.$event->starts_at->copy()->setTimezone('America/New_York')->format('Ymd'), $query);
        // 🚨 No custom User-Agent: ESPN refuses one with a 403.
        $this->assertStringStartsWith('GuzzleHttp', $sent->getHeaderLine('User-Agent'));

        $prices = Selection::query()->whereIn('market_id', Market::query()->where('event_id', $event->id)->pluck('id'))->pluck('odds', 'key');
        $lines = Market::query()->where('event_id', $event->id)->pluck('line', 'type');
        $this->assertSame(-180, Selection::query()->where('market_id', $ml->id)->where('key', 'home')->value('odds'));
        $this->assertSame('-3.5', (string) $lines[Market::SPREAD]);
        $this->assertSame('41.5', (string) $lines[Market::TOTAL]);
        $this->assertCount(4, $prices);

        // An admin takes the moneyline off the feed and prices it by hand.
        $ml->feed = false;
        $ml->save();
        Selection::query()->where('market_id', $ml->id)->where('key', 'home')->update(['odds' => -200]);
        $this->feed([$this->espn('401772', '-250', '+200', '-6.5')])->run();
        $this->assertSame(-200, Selection::query()->where('market_id', $ml->id)->where('key', 'home')->value('odds'));
        $this->assertSame('-6.5', (string) Market::query()->where('event_id', $event->id)->where('type', Market::SPREAD)->value('line'));
    }

    #[Test]
    public function a_failing_feed_changes_nothing(): void
    {
        $this->bridge()->import();
        $this->feed([], 403)->run();
        $this->assertSame(0, Selection::query()->whereNotNull('odds')->count());
    }

    #[Test]
    public function a_game_picks_has_under_way_takes_no_bets_and_settles_from_picks(): void
    {
        $this->bridge()->import();
        $this->feed([$this->espn('401772')])->run();
        $event = Event::query()->firstOrFail();
        $home = Selection::query()->where('market_id', Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->value('id'))->where('key', 'home')->firstOrFail();

        $this->balance(2);
        $this->assertSame(201, $this->bet(2, [$this->leg($home->id, 180)])->getStatusCode());

        // Picks says it has kicked off, though our copy of the kickoff is tomorrow.
        $this->database()->table('picks_events')->where('id', 10)->update(['status' => 'in_progress']);
        $this->assertSame('longshot_closed', $this->json($this->bet(2, [$this->leg($home->id, 10)]))['errors'][0]['code']);

        $this->settlement()->sweep();
        $this->assertTrue(Event::query()->firstOrFail()->starts_at->lte(Carbon::now()));

        $this->database()->table('picks_events')->where('id', 10)->update(['status' => 'finished', 'home_score' => 24, 'away_score' => 10]);
        $this->settlement()->sweep();
        $this->settlement()->sweep();

        $this->assertSame(Event::SETTLED, Event::query()->firstOrFail()->status);
        $this->assertSame(Bet::WON, Bet::query()->value('status'));
        $this->assertSame(280, Bet::query()->value('payout'));
        $this->assertSame(1000 - 180 + 280, $this->stored(2));
    }

    #[Test]
    public function a_kickoff_that_moves_moves_the_close(): void
    {
        $this->bridge()->import();
        $later = Carbon::now()->addDays(2)->startOfMinute();
        $this->database()->table('picks_events')->where('id', 10)->update(['match_date' => $later]);

        $this->settlement()->sweep();
        $this->assertSame($later->toIso8601String(), Event::query()->firstOrFail()->starts_at->toIso8601String());
    }

    #[Test]
    public function a_game_picks_never_finishes_is_voided_after_the_wait(): void
    {
        $this->bridge()->import();
        $this->feed([$this->espn('401772')])->run();
        $event = Event::query()->firstOrFail();
        $home = Selection::query()->where('market_id', Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->value('id'))->where('key', 'home')->firstOrFail();
        $this->balance(2);
        $this->bet(2, [$this->leg($home->id, 100)]);

        $this->database()->table('picks_events')->where('id', 10)->update(['match_date' => Carbon::now()->subDays(4), 'status' => 'closed']);
        $this->settlement()->sweep();

        $this->assertSame(Event::VOID, Event::query()->firstOrFail()->status);
        $this->assertSame(Bet::VOID, Bet::query()->value('status'));
        $this->assertSame(1000, $this->stored(2));
    }

    #[Test]
    public function staff_cannot_rename_or_reschedule_a_picks_game(): void
    {
        $this->bridge()->import();
        $event = Event::query()->with('markets.selections')->firstOrFail();

        $payload = [
            'title' => null, 'home' => 'Renamed', 'away' => 'Also renamed', 'league' => 'NFL',
            'startsAt' => Carbon::now()->addWeek()->toIso8601String(),
            'markets' => $event->markets->map(fn ($m) => [
                'id' => $m->id, 'type' => $m->type, 'line' => $m->type === Market::SPREAD ? '-2.5' : ($m->type === Market::TOTAL ? '40.5' : null), 'feed' => false,
                'selections' => $m->selections->map(fn ($s) => ['id' => $s->id, 'key' => $s->key, 'odds' => -110])->all(),
            ])->all(),
        ];

        $response = $this->send($this->request('PATCH', '/api/longshot/admin/events/'.$event->id, ['authenticatedAs' => 1, 'json' => $payload]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fresh = Event::query()->firstOrFail();
        $this->assertSame('Pittsburgh Steelers', $fresh->home_name);
        $this->assertSame($event->starts_at->toIso8601String(), $fresh->starts_at->toIso8601String());
        $this->assertSame(-110, Selection::query()->where('key', 'over')->value('odds'));
        $this->assertFalse(Market::query()->where('type', Market::TOTAL)->firstOrFail()->feed);
    }

    #[Test]
    public function a_game_a_feed_already_imported_is_not_doubled_by_picks(): void
    {
        $this->app();
        $teams = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Teams::class);
        $home = $teams->resolve('nfl', new \ErnestDefoe\Longshot\Feed\Side('Pittsburgh Steelers'), 'espn');
        $away = $teams->resolve('nfl', new \ErnestDefoe\Longshot\Feed\Side('Cleveland Browns'), 'espn');
        $feed = $this->game(['home' => 'Pittsburgh Steelers', 'away' => 'Cleveland Browns', 'starts_at' => Carbon::now()->addDay()->startOfHour()->addMinutes(30)]);
        Event::query()->whereKey($feed['event'])->update(['source' => 'espn', 'external_id' => '999', 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'league_key' => 'nfl']);

        $this->assertSame(0, $this->bridge()->import());
        $this->assertSame(1, Event::query()->count());
    }
}
