<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\OfferMatch;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * One test per finding of the pre-release adversarial review; each fails on
 * the code before its fix.
 */
class ReviewFixesTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->prepareDatabase([User::class => array_merge([$this->normalUser()], $this->members())]);
    }

    /** @return array<string, mixed> */
    private function offer(int $user, array $body): array
    {
        return $this->json($this->send($this->request('POST', '/api/longshot/offers', ['authenticatedAs' => $user, 'json' => $body])));
    }

    private function take(int $user, int $offer, int $back): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/longshot/offers/'.$offer.'/matches', ['authenticatedAs' => $user, 'json' => ['back' => $back]]));
    }

    #[Test]
    public function laying_in_small_pieces_never_underpays_the_backer(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->balance(3);
        $offer = $this->offer(3, ['selection' => $g['away'], 'odds' => -101, 'stake' => 100])['data']['id'];

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(201, $this->take(2, $offer, 2)->getStatusCode());
        }

        // The fair liability on 100 at -101 is 99.01. Laid in pieces it may
        // cost the layer a little more, never less (it was 50 before the fix).
        $risked = (int) OfferMatch::query()->sum('lay_stake');
        $this->assertGreaterThanOrEqual(99, $risked);
        $this->assertSame(1000 - $risked, $this->stored(2));
    }

    #[Test]
    public function an_unknown_scope_lists_only_open_public_offers(): void
    {
        $g = $this->game();
        foreach ([2, 3, 4] as $u) {
            $this->balance($u);
        }
        $challenge = $this->offer(3, ['selection' => $g['home'], 'odds' => 120, 'stake' => 50, 'target' => 4])['data']['id'];

        foreach (['all', 'everything', '', 'mine ', 'CHALLENGES'] as $scope) {
            $ids = array_column($this->json($this->send($this->request('GET', '/api/longshot/offers', ['authenticatedAs' => 2])->withQueryParams(['scope' => $scope])))['data'], 'id');
            $this->assertNotContains($challenge, $ids, "scope '$scope'");
        }
    }

    #[Test]
    public function deleting_a_member_outside_the_api_still_returns_others_stakes(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->balance(3);
        $offer = $this->offer(3, ['selection' => $g['away'], 'odds' => 150, 'stake' => 100])['data']['id'];
        $this->take(2, $offer, 100);
        $this->assertSame(850, $this->stored(2));

        $this->user(3)->delete();

        $this->assertSame(1000, $this->stored(2));
        $this->settlement()->sweep();
        $this->assertSame(1000, $this->stored(2));
    }

    #[Test]
    public function one_bad_row_does_not_stop_the_sweep(): void
    {
        if ($this->database()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Orphaned rows only arise where foreign keys are off.');
        }

        $a = $this->game();
        $b = $this->game();
        $this->balance(2);
        $this->balance(3);
        $this->bet(3, [$this->leg($a['home'], 170)]);
        $this->bet(2, [$this->leg($b['home'], 170)]);

        // Member 3 vanishes without any event: a raw delete, foreign keys off.
        $this->database()->statement('PRAGMA foreign_keys = OFF');
        $this->database()->table('users')->where('id', 3)->delete();

        foreach ([$a, $b] as $g) {
            Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);
            $this->score($g['event'], 7, 0);
        }
        $this->settlement()->sweep();

        $this->assertSame(Bet::WON, Bet::query()->where('user_id', 2)->value('status'));
        $this->assertSame(1100, $this->stored(2));
    }

    #[Test]
    public function the_sweep_voids_markets_of_a_void_event_left_open(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->bet(2, [$this->leg($g['home'], 100)]);
        // A run that died between voiding the event and its markets.
        Event::query()->whereKey($g['event'])->update(['status' => Event::VOID]);

        $this->settlement()->sweep();

        $this->assertSame(Bet::VOID, Bet::query()->value('status'));
        $this->assertSame(1000, $this->stored(2));
    }

    #[Test]
    public function only_the_member_challenged_may_take_a_challenge(): void
    {
        $g = $this->game();
        foreach ([1, 3, 4] as $u) {
            $this->balance($u);
        }
        $challenge = $this->offer(3, ['selection' => $g['home'], 'odds' => 120, 'stake' => 50, 'target' => 4])['data']['id'];

        // Staff can see it, not accept it on someone's behalf.
        $this->assertSame('longshot_gone', $this->json($this->take(1, $challenge, 10))['errors'][0]['code']);
        $this->assertSame(201, $this->take(4, $challenge, 10)->getStatusCode());
    }

    #[Test]
    public function a_draw_cannot_be_added_to_a_moneyline_already_bet_on(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->bet(2, [$this->leg($g['home'], 10)]);

        $event = Event::query()->with('markets.selections')->findOrFail($g['event']);
        $markets = $event->markets->map(fn ($m) => [
            'id' => $m->id, 'type' => $m->type, 'line' => $m->line,
            'selections' => $m->selections->map(fn ($s) => ['id' => $s->id, 'key' => $s->key, 'odds' => $s->odds])->all(),
        ])->all();
        $markets[0]['selections'][] = ['key' => 'draw', 'odds' => 250];

        $response = $this->send($this->request('PATCH', '/api/longshot/admin/events/'.$g['event'], ['authenticatedAs' => 1, 'json' => [
            'home' => 'Home FC', 'away' => 'Away United', 'league' => 'NFL', 'startsAt' => Carbon::now()->addDay()->toIso8601String(), 'markets' => $markets,
        ]]));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Selection::query()->where('key', 'draw')->count());
    }

    #[Test]
    public function a_viewer_who_may_not_bet_gets_no_wallet_and_no_points(): void
    {
        $this->database()->table('group_permission')->whereIn('permission', ['longshot.bet', 'longshot.exchange'])->delete();

        $this->assertSame(403, $this->send($this->request('PATCH', '/api/longshot/wallet', ['authenticatedAs' => 2, 'json' => ['private' => true]]))->getStatusCode());
        $this->assertSame(0, $this->database()->table('longshot_ledger')->count());
        $this->assertSame(0, $this->database()->table('longshot_wallets')->count());
    }

    #[Test]
    public function a_nonsense_price_from_the_client_is_a_moved_price_not_a_500(): void
    {
        $this->setting('ernestdefoe-longshot.accept_better_odds', true);
        $g = $this->game();
        $this->balance(2);

        foreach ([50, 0, -99, 20000] as $odds) {
            $response = $this->bet(2, [['selection' => $g['home'], 'odds' => $odds, 'line' => null, 'stake' => 10]]);
            $this->assertSame(409, $response->getStatusCode(), "odds $odds");
        }
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function an_auto_void_racing_a_score_leaves_the_scored_event_alone(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->bet(2, [$this->leg($g['home'], 170)]);
        Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);

        // The sweep read the event as open, then an admin scored it.
        $stale = Event::query()->findOrFail($g['event']);
        $this->score($g['event'], 3, 0);
        $this->settlement()->voidEvent($stale);

        $this->assertSame(Event::SETTLED, Event::query()->find($g['event'])->status);
        $this->assertSame(Bet::WON, Bet::query()->value('status'));
        $this->assertSame(1100, $this->stored(2));
    }
}
