<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class AdminEventsTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    /**
     * Saving the form writes each market (its id is needed for its options)
     * and each price that changed. Bounded by the form, not by the forum.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['into "ls_longshot_markets"', 'into `ls_longshot_markets`', 'into "longshot_markets"', 'into `longshot_markets`', 'longshot_selections" set', 'longshot_selections` set'];
    }

    private function save(array $payload, ?int $id = null, int $as = 1): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request($id ? 'PATCH' : 'POST', '/api/longshot/admin/events'.($id ? '/'.$id : ''), ['authenticatedAs' => $as, 'json' => $payload]));
    }

    private function matchup(array $markets): array
    {
        return ['home' => 'Lakers', 'away' => 'Celtics', 'league' => 'NBA', 'startsAt' => Carbon::now()->addDay()->toIso8601String(), 'markets' => $markets];
    }

    private function full(): array
    {
        return [
            ['type' => 'moneyline', 'selections' => [['key' => 'away', 'odds' => 130], ['key' => 'home', 'odds' => -150]]],
            ['type' => 'spread', 'line' => '-2.5', 'selections' => [['key' => 'away', 'odds' => -110], ['key' => 'home', 'odds' => -110]]],
            ['type' => 'total', 'line' => '221.5', 'selections' => [['key' => 'over', 'odds' => -110], ['key' => 'under', 'odds' => -110]]],
        ];
    }

    #[Test]
    public function staff_create_a_game_with_all_three_markets(): void
    {
        $this->assertSame(403, $this->save($this->matchup($this->full()), null, 2)->getStatusCode());

        $response = $this->save($this->matchup($this->full()));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        $this->assertSame('Celtics @ Lakers', $data['name']);
        $this->assertSame(['moneyline', 'spread', 'total'], array_column($data['markets'], 'type'));
        $this->assertSame('221.5', $data['markets'][2]['line']);
        $this->assertFalse($data['markets'][0]['feed'], 'only games from Picks follow the feed');

        // On the board for members straight away.
        $this->assertCount(1, $this->json($this->send($this->request('GET', '/api/longshot', ['authenticatedAs' => 2])))['data']);
    }

    #[Test]
    public function bad_forms_are_refused(): void
    {
        $cases = [
            'no names' => ['startsAt' => Carbon::now()->addDay()->toIso8601String(), 'markets' => []],
            'one side' => ['home' => 'A', 'startsAt' => Carbon::now()->addDay()->toIso8601String()],
            'bad date' => array_merge($this->matchup([]), ['startsAt' => 'tomorrowish']),
            'bad type' => $this->matchup([['type' => 'exotic', 'selections' => []]]),
            'quarter line' => $this->matchup([['type' => 'spread', 'line' => '-2.25', 'selections' => [['key' => 'away'], ['key' => 'home']]]]),
            'missing side' => $this->matchup([['type' => 'total', 'line' => '200.5', 'selections' => [['key' => 'over', 'odds' => -110]]]]),
            'twice' => $this->matchup([['type' => 'moneyline', 'selections' => [['key' => 'home'], ['key' => 'home'], ['key' => 'away']]]]),
            'bad odds' => $this->matchup([['type' => 'moneyline', 'selections' => [['key' => 'away', 'odds' => 50], ['key' => 'home', 'odds' => -110]]]]),
            'string odds' => $this->matchup([['type' => 'moneyline', 'selections' => [['key' => 'away', 'odds' => '150'], ['key' => 'home', 'odds' => -110]]]]),
            'unnamed prop' => $this->matchup([['type' => 'custom', 'selections' => [['label' => 'Yes'], ['label' => 'No']]]]),
            'one option' => $this->matchup([['type' => 'custom', 'label' => 'MVP', 'selections' => [['label' => 'Only']]]]),
            'scored market, no sides' => ['title' => 'Oscars', 'startsAt' => Carbon::now()->addDay()->toIso8601String(), 'markets' => [['type' => 'moneyline', 'selections' => []]]],
        ];

        foreach ($cases as $name => $payload) {
            $this->assertSame(422, $this->save($payload)->getStatusCode(), $name);
        }

        $this->assertSame(0, Event::query()->count(), 'a refused form leaves nothing behind');
    }

    #[Test]
    public function what_was_bet_on_is_fixed_but_prices_and_lines_still_move(): void
    {
        $data = $this->json($this->save($this->matchup($this->full())))['data'];
        $id = $data['id'];
        $this->balance(2);
        $home = $data['markets'][0]['selections'][1]['id'];
        $this->assertSame(201, $this->bet(2, [$this->leg($home, 50)])->getStatusCode());

        $markets = array_map(fn ($m) => ['id' => $m['id'], 'type' => $m['type'], 'line' => $m['line'], 'selections' => array_map(fn ($s) => ['id' => $s['id'], 'key' => $s['key'], 'odds' => $s['odds']], $m['selections'])], $data['markets']);

        // Removing the market that was bet on: refused.
        $this->assertSame(422, $this->save($this->matchup(array_slice($markets, 1)), $id)->getStatusCode());

        // Changing its type: refused.
        $changed = $markets;
        $changed[0]['type'] = 'total';
        $changed[0]['line'] = '10.5';
        $changed[0]['selections'] = [['id' => $markets[0]['selections'][0]['id'], 'key' => 'over'], ['id' => $markets[0]['selections'][1]['id'], 'key' => 'under']];
        $this->assertSame(422, $this->save($this->matchup($changed), $id)->getStatusCode());

        // Removing an unused market and moving a price: fine.
        $moved = array_slice($markets, 0, 2);
        $moved[0]['selections'][1]['odds'] = -200;
        $response = $this->save($this->matchup($moved), $id);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(2, Market::query()->where('event_id', $id)->count());

        // The bet keeps the price it was placed at.
        $this->assertSame(-150, $this->database()->table('longshot_bet_legs')->value('odds'));

        // Bet on: it cannot be deleted, only voided.
        $this->assertSame(422, $this->send($this->request('DELETE', '/api/longshot/admin/events/'.$id, ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('POST', '/api/longshot/admin/events/'.$id.'/void', ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertSame(1000, $this->stored(2));

        // And once settled or void, it is not edited any more.
        $this->assertSame(422, $this->save($this->matchup($moved), $id)->getStatusCode());
    }

    #[Test]
    public function an_unused_event_can_be_deleted(): void
    {
        $id = $this->json($this->save($this->matchup($this->full())))['data']['id'];

        $this->assertSame(403, $this->send($this->request('DELETE', '/api/longshot/admin/events/'.$id, ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', '/api/longshot/admin/events/'.$id, ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertSame(0, Market::query()->count());
    }

    #[Test]
    public function a_titled_event_takes_custom_markets_and_new_options_get_fresh_keys(): void
    {
        $payload = ['title' => 'Super Bowl MVP', 'league' => 'NFL', 'startsAt' => Carbon::now()->addWeek()->toIso8601String(), 'markets' => [
            ['type' => 'custom', 'label' => 'MVP', 'selections' => [['label' => 'QB A', 'odds' => 150], ['label' => 'QB B', 'odds' => 200]]],
        ]];
        $data = $this->json($this->save($payload))['data'];
        $this->assertSame(['opt1', 'opt2'], array_column($data['markets'][0]['selections'], 'key'));

        // Drop the first option, keep the second, add one: no key is reused.
        $payload['markets'][0]['id'] = $data['markets'][0]['id'];
        $payload['markets'][0]['selections'] = [['id' => $data['markets'][0]['selections'][1]['id'], 'label' => 'QB B', 'odds' => 200], ['label' => 'Field', 'odds' => -300]];
        $response = $this->save($payload, $data['id']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['opt2', 'opt3'], array_column($this->json($response)['data']['markets'][0]['selections'], 'key'));
    }

    #[Test]
    public function a_suspended_market_takes_no_bets(): void
    {
        $markets = $this->full();
        $markets[0]['status'] = 'suspended';
        $data = $this->json($this->save($this->matchup($markets)))['data'];
        $this->balance(2);

        $this->assertSame(422, $this->bet(2, [$this->leg($data['markets'][0]['selections'][0]['id'], 10)])->getStatusCode());
        $this->assertSame(201, $this->bet(2, [$this->leg($data['markets'][1]['selections'][0]['id'], 10)])->getStatusCode());
    }
}
