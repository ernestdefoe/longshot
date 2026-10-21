<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Feed\Leagues;
use ErnestDefoe\Longshot\Feed\Logos;
use ErnestDefoe\Longshot\Feed\Sync;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Model\Team;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

/**
 * The data providers end to end, against a scripted internet: real ESPN
 * payloads from tests/fixtures, made-up answers for the keyed providers.
 */
class FeedTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var array<string, callable(RequestInterface): Response> path fragment => answer */
    private array $routes = [];

    /** Syncing a day at a time and a picture per team are repeated shapes by design. */
    protected function detectsRepeatedQueries(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        $this->extension('ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->setting('ernestdefoe-longshot.leagues', 'nfl,epl');
        $this->setting('ernestdefoe-longshot.horizon_days', 7);
        $this->prepareDatabase([User::class => [$this->normalUser()]]);

        $this->routes = [
            'football/nfl/scoreboard' => fn (RequestInterface $r) => str_contains($r->getUri()->getQuery(), 'dates=20261025')
                ? $this->reply(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'))
                : $this->reply('{"events":[]}'),
            'football/nfl/teams' => fn () => $this->reply('{"sports":[{"leagues":[{"teams":[]}]}]}'),
            'soccer/eng.1/scoreboard' => fn () => $this->reply('{"events":[]}'),
            'soccer/eng.1/teams' => fn () => $this->reply('{"sports":[{"leagues":[{"teams":[]}]}]}'),
            'espncdn.com' => fn () => new Response(200, ['Content-Type' => 'image/png'], $this->png()),
            'thesportsdb.com' => fn () => $this->reply('{"teams":null,"leagues":[{"strBadge":null}]}'),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reply(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** The app's HTTP client, answering from $routes and recording every request. */
    private function internet(): void
    {
        $handler = function (RequestInterface $request) {
            $this->requests[] = $request;
            foreach ($this->routes as $fragment => $answer) {
                if (str_contains((string) $request->getUri(), $fragment)) {
                    return Create::promiseFor($answer($request));
                }
            }

            return Create::promiseFor(new Response(404));
        };

        $this->app()->getContainer()->instance(Client::class, new Client(['handler' => HandlerStack::create($handler)]));
    }

    private function sync(): Sync
    {
        $this->internet();

        return $this->app()->getContainer()->make(Sync::class);
    }

    private function league(string $key): \ErnestDefoe\Longshot\Feed\League
    {
        return $this->app()->getContainer()->make(Leagues::class)->get($key);
    }

    /** @return list<RequestInterface> */
    private function to(string $fragment): array
    {
        return array_values(array_filter($this->requests, fn (RequestInterface $r) => str_contains((string) $r->getUri(), $fragment)));
    }

    #[Test]
    public function a_followed_league_is_imported_priced_and_branded_from_espn(): void
    {
        $report = $this->sync()->league($this->league('nfl'));

        $this->assertSame('espn', $report['schedule']['provider']);
        $this->assertSame(3, $report['schedule']['added']);

        // One scoreboard call per Eastern day from yesterday to the horizon, no
        // more, and one undated call for the league's badge (once a month).
        $dated = array_filter($this->to('football/nfl/scoreboard'), fn ($r) => str_contains($r->getUri()->getQuery(), 'dates='));
        $this->assertCount(9, $dated);
        $this->assertCount(10, $this->to('football/nfl/scoreboard'));
        $this->assertSame('', $this->to('football/nfl/scoreboard')[0]->getHeaderLine('X-Custom'), 'no custom headers');
        $this->assertStringStartsWith('GuzzleHttp', $this->to('football/nfl/scoreboard')[0]->getHeaderLine('User-Agent'));

        $event = Event::query()->where('external_id', '401873011')->firstOrFail();
        $this->assertSame('espn', $event->source);
        $this->assertSame('nfl', $event->league_key);
        $this->assertSame('New Orleans Saints', $event->home_name);
        $this->assertSame('2026-10-25 13:30:00', $event->starts_at->format('Y-m-d H:i:s'));

        // Priced from the payload's own odds, and says so.
        $ml = Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->firstOrFail();
        $this->assertSame(0, Selection::query()->where('market_id', $ml->id)->whereNull('odds')->count());
        $this->assertStringContainsString('(via ESPN)', (string) $ml->odds_provider);

        // Teams with colours; logos fetched once from ESPN's CDN (by the
        // scheduled run, a few at a time) and stored as our own WebP.
        $this->assertSame(6, $this->app()->getContainer()->make(Logos::class)->cacheTeams());
        $saints = Team::query()->findOrFail($event->home_team_id);
        $this->assertSame('#d3bc8d', $saints->color);
        $this->assertNotNull($saints->logo_path);
        $this->assertStringEndsWith('.webp', (string) $saints->logo_path);
        $this->assertStringContainsString('/assets/longshot/', (string) Event::query()->find($event->id)->home_logo);
        $this->assertStringNotContainsString('espncdn', (string) Event::query()->find($event->id)->home_logo);

        $stored = $this->app()->getContainer()->make(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-assets')->get((string) $saints->logo_path);
        [$w, $h] = getimagesizefromstring((string) $stored);
        $this->assertSame([96, 96], [$w, $h], 'resized to 96px');

        // The calls were counted against ESPN's budget.
        $this->assertSame(count($this->to('site.api.espn.com')), (int) $this->database()->table('longshot_provider_calls')->where('provider', 'espn')->value('calls'));
    }

    #[Test]
    public function a_second_run_inside_the_throttle_asks_nobody(): void
    {
        $sync = $this->sync();
        $sync->league($this->league('nfl'));
        $before = count($this->requests);

        $sync->league($this->league('nfl'));

        $this->assertSame($before, count($this->requests));
    }

    #[Test]
    public function a_final_score_from_the_feed_settles_the_game(): void
    {
        $this->sync()->league($this->league('nfl'));
        $event = Event::query()->where('external_id', '401873011')->firstOrFail();
        $home = Selection::query()->where('market_id', Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->value('id'))->where('key', 'home')->firstOrFail();
        $this->balance(2);
        $this->assertSame(201, $this->bet(2, [$this->leg($home->id, 100)])->getStatusCode());

        // Sunday evening: ESPN says final, Saints 27, Steelers 20.
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:00:00', 'UTC'));
        $final = json_decode(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'), true);
        foreach ($final['events'] as &$e) {
            $e['competitions'][0]['status']['type'] = ['name' => 'STATUS_FINAL', 'state' => 'post', 'completed' => true];
            foreach ($e['competitions'][0]['competitors'] as &$c) {
                $c['score'] = $c['homeAway'] === 'home' ? '27' : '20';
            }
        }
        $this->routes['football/nfl/scoreboard'] = fn () => $this->reply(json_encode($final));

        $settled = $this->sync()->results();

        $this->assertGreaterThanOrEqual(1, $settled);
        $this->assertSame(Event::SETTLED, Event::query()->find($event->id)->status);
        $this->assertSame(Bet::WON, Bet::query()->value('status'));

        // And not asked again for five minutes.
        $calls = count($this->requests);
        $this->sync()->results();
        $this->assertSame($calls, count($this->requests));
    }

    private function apiFootball(): void
    {
        $this->setting('ernestdefoe-longshot.provider_apifootball_key', 'test-key');
        $this->routes['v3.football.api-sports.io/fixtures'] = fn () => $this->reply(json_encode(['errors' => [], 'response' => [[
            'fixture' => ['id' => 9001, 'date' => '2026-10-24T14:00:00+00:00', 'status' => ['short' => 'NS']],
            'teams' => ['home' => ['id' => 33, 'name' => 'Manchester United', 'logo' => 'https://media.api-sports.io/football/teams/33.png'], 'away' => ['id' => 40, 'name' => 'Liverpool', 'logo' => 'https://media.api-sports.io/football/teams/40.png']],
            'score' => ['fulltime' => ['home' => null, 'away' => null]],
        ]]]));
        $this->routes['v3.football.api-sports.io/odds'] = fn () => $this->reply('{"errors":[],"response":[]}');
    }

    #[Test]
    public function the_first_usable_provider_brings_the_league_in(): void
    {
        // ESPN switched off by staff: API-Football is the league's provider.
        $this->setting('ernestdefoe-longshot.provider_espn_enabled', '0');
        $this->apiFootball();

        $report = $this->sync()->league($this->league('epl'));

        $this->assertSame('apifootball', $report['schedule']['provider']);
        $event = Event::query()->where('source', 'apifootball')->firstOrFail();
        $this->assertSame(['Liverpool', 'Manchester United'], [$event->away_name, $event->home_name]);
        $this->assertSame(['away', 'home', 'draw'], Selection::query()->where('market_id', Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->value('id'))->orderBy('sort')->pluck('key')->all());
        $this->assertSame('test-key', $this->to('api-sports.io/fixtures')[0]->getHeaderLine('x-apisports-key'));
        $this->assertCount(0, $this->to('site.api.espn.com'));
    }

    #[Test]
    public function a_failing_provider_pauses_and_a_backup_adds_nothing(): void
    {
        $this->apiFootball();
        $this->routes['soccer/eng.1/scoreboard'] = fn () => $this->reply('{}', 500);

        $report = $this->sync()->league($this->league('epl'));

        // The backup answered, but a backup only updates games already on the
        // board: its names differ from ESPN's, and it would double them.
        $this->assertSame('apifootball', $report['schedule']['provider']);
        $this->assertSame(0, $report['schedule']['added']);
        $this->assertSame(0, Event::query()->count());

        // ESPN failed every day it was asked: paused, and skipped next time.
        $status = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Budget::class)->status('espn');
        $this->assertNotNull($status['paused']);
        $this->assertSame('HTTP 500', $status['error']['message']);
    }

    #[Test]
    public function a_budget_stops_calls_at_its_limit(): void
    {
        $this->setting('ernestdefoe-longshot.provider_espn_budget', 4);

        $this->sync()->league($this->league('nfl'));

        $this->assertCount(4, $this->to('football/nfl/scoreboard'));
        $this->assertSame(4, (int) $this->database()->table('longshot_provider_calls')->where('provider', 'espn')->value('calls'));
    }

    #[Test]
    public function a_refused_key_pauses_the_provider_for_an_hour(): void
    {
        $this->setting('ernestdefoe-longshot.provider_oddsapi_key', 'bad');
        $this->internet();
        $http = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Http::class);
        $this->routes['the-odds-api.com'] = fn () => $this->reply('{"message":"invalid key"}', 401);

        $this->assertNull($http->json('oddsapi', 'https://api.the-odds-api.com/v4/sports/x/odds'));
        $this->assertNull($http->json('oddsapi', 'https://api.the-odds-api.com/v4/sports/x/odds'));
        $this->assertCount(1, $this->to('the-odds-api.com'), 'paused after the first refusal');
    }

    #[Test]
    public function staff_locks_and_hides_survive_the_sync(): void
    {
        $sync = $this->sync();
        $sync->league($this->league('nfl'));
        $locked = Event::query()->where('external_id', '401873011')->firstOrFail();
        $locked->locked = true;
        $locked->starts_at = Carbon::parse('2026-10-25 15:00:00');
        $locked->save();
        $hidden = Event::query()->where('source', 'espn')->where('id', '!=', $locked->id)->firstOrFail();
        $hidden->status = Event::HIDDEN;
        $hidden->save();

        $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->flush();
        $sync->league($this->league('nfl'), true);

        $this->assertSame('2026-10-25 15:00:00', Event::query()->find($locked->id)->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(Event::HIDDEN, Event::query()->find($hidden->id)->status);
        $this->assertSame(3, Event::query()->where('source', 'espn')->count(), 'nothing re-imported');

        // Hidden events are on nobody's board.
        $board = $this->json($this->send($this->request('GET', '/api/longshot')));
        $this->assertNotContains($hidden->id, array_column($board['data'], 'id'));
    }

    #[Test]
    public function the_odds_api_prices_what_espn_did_not_matched_by_teams(): void
    {
        $this->setting('ernestdefoe-longshot.provider_oddsapi_key', 'k');
        $fixture = json_decode(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'), true);
        foreach ($fixture['events'] as &$e) {
            unset($e['competitions'][0]['odds']);
        }
        $this->routes['football/nfl/scoreboard'] = fn (RequestInterface $r) => str_contains($r->getUri()->getQuery(), 'dates=20261025') ? $this->reply(json_encode($fixture)) : $this->reply('{"events":[]}');
        $this->routes['the-odds-api.com'] = fn () => $this->reply(json_encode([[
            'id' => 'abc', 'commence_time' => '2026-10-25T13:30:00Z', 'home_team' => 'New Orleans Saints', 'away_team' => 'Pittsburgh Steelers',
            'bookmakers' => [['key' => 'fanduel', 'title' => 'FanDuel', 'markets' => [
                ['key' => 'h2h', 'outcomes' => [['name' => 'New Orleans Saints', 'price' => 150], ['name' => 'Pittsburgh Steelers', 'price' => -180]]],
                ['key' => 'spreads', 'outcomes' => [['name' => 'New Orleans Saints', 'price' => -110, 'point' => 3.5], ['name' => 'Pittsburgh Steelers', 'price' => -110, 'point' => -3.5]]],
                ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'price' => -105, 'point' => 44.5], ['name' => 'Under', 'price' => -115, 'point' => 44.5]]],
            ]]],
        ]]));

        $this->sync()->league($this->league('nfl'));

        $event = Event::query()->where('external_id', '401873011')->firstOrFail();
        $spread = Market::query()->where('event_id', $event->id)->where('type', Market::SPREAD)->firstOrFail();
        $this->assertSame('3.5', \ErnestDefoe\Longshot\Grader::normalise($spread->line));
        $this->assertSame('FanDuel (via The Odds API)', $spread->odds_provider);
        $this->assertSame(150, Selection::query()->where('market_id', Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->value('id'))->where('key', 'home')->value('odds'));

        // Billed in credits: three markets, one region.
        $this->assertSame(3, (int) $this->database()->table('longshot_provider_calls')->where('provider', 'oddsapi')->value('calls'));
        $this->assertSame('h2h,spreads,totals', parse_query_value((string) $this->to('the-odds-api.com')[0]->getUri()->getQuery(), 'markets'));
    }

    #[Test]
    public function a_failover_provider_never_doubles_a_game(): void
    {
        $this->sync()->league($this->league('nfl'));
        $before = Event::query()->count();

        // The same Steelers–Saints game, as another provider would send it.
        $importer = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Importer::class);
        $importer->apply($this->league('nfl'), [new \ErnestDefoe\Longshot\Feed\Game('oddsapi', 'zzz', 'nfl', Carbon::parse('2026-10-25 14:00:00'),
            new \ErnestDefoe\Longshot\Feed\Side('Saints'), new \ErnestDefoe\Longshot\Feed\Side('Pittsburgh Steelers'))]);

        $this->assertSame($before, Event::query()->count());
    }

    #[Test]
    public function only_known_image_hosts_are_fetched(): void
    {
        $this->internet();
        $logos = $this->app()->getContainer()->make(Logos::class);

        foreach (['http://a.espncdn.com/x.png', 'https://evil.example/x.png', 'https://a.espncdn.com.evil.example/x.png', 'https://a.espncdn.com:8443/x.png', 'https://user@a.espncdn.com/x.png', 'file:///etc/passwd', 'https://169.254.169.254/latest/meta-data'] as $url) {
            $this->assertFalse(Logos::allowed($url), $url);
            $this->assertNull($logos->store($url, 'test'));
        }

        $this->assertSame([], $this->requests);
        $this->assertTrue(Logos::allowed('https://a.espncdn.com/i/teamlogos/nfl/500/no.png'));
    }

    #[Test]
    public function texas_and_texas_tech_stay_two_teams(): void
    {
        $this->app();
        $teams = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Teams::class);
        $texas = $teams->resolve('ncaaf', new \ErnestDefoe\Longshot\Feed\Side('Texas Longhorns', 'TEX', id: '251', aliases: ['Longhorns', 'Texas']), 'espn');
        $tech = $teams->resolve('ncaaf', new \ErnestDefoe\Longshot\Feed\Side('Texas Tech Red Raiders', 'TTU', id: '2641', aliases: ['Red Raiders', 'Texas Tech']), 'espn');
        $north = $teams->resolve('ncaaf', new \ErnestDefoe\Longshot\Feed\Side('North Texas Mean Green', 'UNT', id: '249'), 'espn');

        $this->assertNotSame($texas->id, $tech->id);
        $this->assertNotSame($texas->id, $north->id);
        // CFBD's names find the right ones, exactly.
        $this->assertSame($tech->id, $teams->find('ncaaf', 'Texas Tech')?->id);
        $this->assertSame($texas->id, $teams->find('ncaaf', 'Texas')?->id);
        $this->assertNull($teams->find('ncaaf', 'Tech'));
        // ESPN's id wins over a name, and a name another team owns is never learned.
        $this->assertSame($texas->id, $teams->resolve('ncaaf', new \ErnestDefoe\Longshot\Feed\Side('Texas Tech', id: '251'), 'espn')->id);
        $this->assertNotContains('texas tech', (array) Team::query()->find($texas->id)->aliases);
    }

    #[Test]
    public function a_locked_game_is_not_settled_by_the_feed(): void
    {
        $this->sync()->league($this->league('nfl'));
        $event = Event::query()->where('external_id', '401873011')->firstOrFail();
        $event->locked = true;
        $event->save();

        Carbon::setTestNow(Carbon::parse('2026-10-25 20:00:00', 'UTC'));
        $final = json_decode(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'), true);
        foreach ($final['events'] as &$e) {
            $e['competitions'][0]['status']['type'] = ['name' => 'STATUS_FINAL', 'state' => 'post', 'completed' => true];
        }
        $this->routes['football/nfl/scoreboard'] = fn () => $this->reply(json_encode($final));

        $this->sync()->results();
        $this->settlement()->sweep();

        $this->assertSame(Event::OPEN, Event::query()->find($event->id)->status);
    }

    #[Test]
    public function a_postponed_game_is_not_asked_about_every_five_minutes(): void
    {
        $this->sync()->league($this->league('nfl'));
        Carbon::setTestNow(Carbon::parse('2026-10-25 20:00:00', 'UTC'));
        $off = json_decode(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'), true);
        foreach ($off['events'] as &$e) {
            $e['competitions'][0]['status']['type'] = ['name' => 'STATUS_POSTPONED', 'state' => 'post', 'completed' => true];
        }
        $this->routes['football/nfl/scoreboard'] = fn () => $this->reply(json_encode($off));

        $this->sync()->results();
        $before = count($this->to('football/nfl/scoreboard'));
        $this->assertSame(1, $before - 9 - 1, 'one call: only the day with games due');

        Carbon::setTestNow(Carbon::parse('2026-10-25 20:10:00', 'UTC'));
        $this->sync()->results();
        $this->assertSame($before, count($this->to('football/nfl/scoreboard')), 'postponed games are left alone for hours');
    }

    #[Test]
    public function the_odds_api_is_asked_at_most_every_six_hours_per_league(): void
    {
        $this->setting('ernestdefoe-longshot.provider_oddsapi_key', 'k');
        $fixture = json_decode(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'), true);
        foreach ($fixture['events'] as &$e) {
            unset($e['competitions'][0]['odds']);
        }
        $this->routes['football/nfl/scoreboard'] = fn (RequestInterface $r) => str_contains($r->getUri()->getQuery(), 'dates=20261025') ? $this->reply(json_encode($fixture)) : $this->reply('{"events":[]}');
        $this->routes['the-odds-api.com'] = fn () => $this->reply('[]');

        $sync = $this->sync();
        $sync->league($this->league('nfl'));
        $sync->league($this->league('nfl'), true);
        Carbon::setTestNow(Carbon::parse('2026-10-21 17:00:00', 'UTC'));
        $sync->league($this->league('nfl'));

        $this->assertCount(1, $this->to('the-odds-api.com'), 'not forced by "sync now", not again within 6 hours');
    }

    #[Test]
    public function an_image_bomb_is_refused_before_it_is_decoded(): void
    {
        $this->internet();
        // A tiny file whose header claims 30000 x 30000 pixels.
        $img = imagecreatetruecolor(30000, 1);
        ob_start();
        imagepng($img);
        $bomb = (string) ob_get_clean();
        $this->routes['espncdn.com'] = fn () => new Response(200, [], $bomb);

        $team = new Team();
        $team->league_key = 'nfl';
        $team->name = 'Bombers';
        $team->name_key = 'bombers';
        $team->logo_source = 'https://a.espncdn.com/bomb.png';
        $team->locked = false;
        $team->save();

        $logos = $this->app()->getContainer()->make(Logos::class);
        $this->assertSame(0, $logos->cacheTeams());
        $this->assertNotNull(Team::query()->find($team->id)->logo_failed_at);
        $this->assertNull(Team::query()->find($team->id)->logo_path);

        // Not tried again this week.
        $this->assertSame(0, $logos->cacheTeams());
        $this->assertCount(1, $this->to('espncdn.com'));
    }

    #[Test]
    public function an_api_error_inside_a_200_is_a_failure_and_a_new_key_lifts_the_pause(): void
    {
        $this->setting('ernestdefoe-longshot.provider_apifootball_key', 'k');
        $this->internet();
        $this->routes['api-sports.io'] = fn () => $this->reply('{"errors":{"token":"Error/Missing application key."},"response":[]}');
        $http = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Http::class);
        $budget = $this->app()->getContainer()->make(\ErnestDefoe\Longshot\Feed\Budget::class);

        $this->assertNull($http->json('apifootball', 'https://v3.football.api-sports.io/status'));
        $this->assertNotNull($budget->status('apifootball')['paused']);
        $this->assertStringContainsString('application key', $budget->status('apifootball')['error']['message']);

        $this->send($this->request('POST', '/api/settings', ['authenticatedAs' => 1, 'json' => ['ernestdefoe-longshot.provider_apifootball_key' => 'new']]));
        $this->assertNull($budget->status('apifootball')['paused']);
    }

    #[Test]
    public function errors_never_keep_a_key(): void
    {
        $this->assertSame('cURL error 28: timed out for https://api.the-odds-api.com/v4/sports/x/odds?…', \ErnestDefoe\Longshot\Feed\Http::scrub('cURL error 28: timed out for https://api.the-odds-api.com/v4/sports/x/odds?apiKey=SECRET&regions=us'));
    }

    #[Test]
    public function only_admins_may_sync_now(): void
    {
        $this->prepareDatabase([
            'group_permission' => [['group_id' => 3, 'permission' => 'longshot.manage']],
        ]);

        $this->assertSame(403, $this->send($this->request('POST', '/api/longshot/admin/sync', ['authenticatedAs' => 2]))->getStatusCode());
    }

    #[Test]
    public function a_locked_game_keeps_its_prices(): void
    {
        $sync = $this->sync();
        $sync->league($this->league('nfl'));
        $event = Event::query()->where('external_id', '401873011')->firstOrFail();
        $event->locked = true;
        $event->save();
        $home = Selection::query()->where('market_id', Market::query()->where('event_id', $event->id)->where('type', Market::MONEYLINE)->value('id'))->where('key', 'home')->firstOrFail();
        $before = $home->odds;

        $moved = json_decode(file_get_contents(__DIR__.'/../../fixtures/espn-nfl-20261025.json'), true);
        $moved['events'][0]['competitions'][0]['odds'][0]['moneyline']['home']['close']['odds'] = '+900';
        $this->routes['football/nfl/scoreboard'] = fn (RequestInterface $r) => str_contains($r->getUri()->getQuery(), 'dates=20261025') ? $this->reply(json_encode($moved)) : $this->reply('{"events":[]}');

        $sync->league($this->league('nfl'), true);

        $this->assertSame($before, Selection::query()->find($home->id)->odds);
    }
}

function parse_query_value(string $query, string $key): ?string
{
    parse_str($query, $values);

    return isset($values[$key]) ? (string) $values[$key] : null;
}