<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class SettlementTest extends TestCase
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

    /** Place a bet and let the game start, as it would before a result arrives. */
    private function placed(int $user, array $legs, string $kind = 'single', int $stake = 0): void
    {
        $response = $this->bet($user, $legs, $kind, $stake);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function kickoff(int $event): void
    {
        Event::query()->whereKey($event)->update(['starts_at' => Carbon::now()->subHour()]);
    }

    #[Test]
    public function a_winning_single_is_paid_exactly_once(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->placed(2, [$this->leg($g['home_spread'], 100)]);
        $this->kickoff($g['event']);

        $this->score($g['event'], 24, 20); // home -3.5 covers

        $bet = Bet::query()->firstOrFail();
        $this->assertSame(Bet::WON, $bet->status);
        $this->assertSame(190, $bet->payout);
        $this->assertSame(1090, $this->stored(2));

        // Every retry path, and the sweep, pays nothing more.
        $this->score($g['event'], 24, 20);
        $this->settlement()->payBet($bet->id);
        $this->settlement()->payMarkets([$g['spread']]);
        $this->settlement()->sweep();
        $this->settlement()->sweep();

        $this->assertSame(1090, $this->stored(2));
        $this->assertSame(1, $this->database()->table('longshot_ledger')->where('ref', 'bet:'.$bet->id.':payout')->count());
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'longshotBetSettled')->count());
    }

    #[Test]
    public function losing_pushing_and_winning_singles_settle_from_one_score(): void
    {
        $g = $this->game([], '-4.0', '44.0');
        $this->balance(2);
        $this->placed(2, [
            $this->leg($g['away'], 100),        // loses
            $this->leg($g['home_spread'], 100), // 24-20 at -4: push
            $this->leg($g['over'], 100),        // 44: push
            $this->leg($g['home'], 170),        // -170: wins 100
        ]);
        $this->kickoff($g['event']);
        $this->assertSame(530, $this->stored(2));

        $this->score($g['event'], 24, 20);

        $bets = Bet::query()->orderBy('id')->get();
        $this->assertSame([Bet::LOST, Bet::PUSH, Bet::PUSH, Bet::WON], $bets->pluck('status')->all());
        $this->assertSame([0, 100, 100, 270], $bets->pluck('payout')->all());
        $this->assertSame(530 + 100 + 100 + 270, $this->stored(2));
    }

    #[Test]
    public function a_bet_is_graded_at_the_line_it_was_taken_at(): void
    {
        $g = $this->game([], '-3.5');
        $this->balance(2);
        $this->placed(2, [$this->leg($g['home_spread'], 100)]);

        // The line moves to -6.5 and a second member bets at it.
        Market::query()->whereKey($g['spread'])->update(['line' => '-6.5']);
        $this->balance(3);
        $this->placed(3, [$this->leg($g['home_spread'], 100)]);
        $this->kickoff($g['event']);

        $this->score($g['event'], 25, 20); // won by 5

        $this->assertSame(Bet::WON, Bet::query()->where('user_id', 2)->value('status'));
        $this->assertSame(Bet::LOST, Bet::query()->where('user_id', 3)->value('status'));
    }

    #[Test]
    public function a_parlay_drops_pushed_legs_and_loses_on_any_loss(): void
    {
        $a = $this->game([], '-3.0');
        $b = $this->game();
        $c = $this->game();
        $this->balance(2);
        // a: home -3 pushes at 23-20; b: away +150 wins; c: over 44.5
        $this->placed(2, [$this->leg($a['home_spread']), $this->leg($b['away']), $this->leg($c['over'])], 'parlay', 100);
        $this->placed(2, [$this->leg($a['home_spread']), $this->leg($b['away'])], 'parlay', 100);
        foreach ([$a, $b, $c] as $g) {
            $this->kickoff($g['event']);
        }

        $this->score($a['event'], 23, 20);
        $this->score($b['event'], 10, 17);
        $this->assertSame([Bet::LOST, Bet::WON], Bet::query()->orderBy('id')->pluck('status')->all() === [Bet::OPEN, Bet::WON] ? [Bet::LOST, Bet::WON] : Bet::query()->orderBy('id')->pluck('status')->all());

        // The second parlay was complete after two games: push + win = a single at +150.
        $this->assertSame(250, Bet::query()->orderBy('id')->skip(1)->first()->payout);

        $this->score($c['event'], 20, 20); // 40: under, so the first parlay loses
        $first = Bet::query()->orderBy('id')->first();
        $this->assertSame(Bet::LOST, $first->status);
        $this->assertSame(0, $first->payout);
        $this->assertSame(1000 - 200 + 250, $this->stored(2));
    }

    #[Test]
    public function a_void_event_refunds_singles_and_shortens_parlays(): void
    {
        $a = $this->game();
        $b = $this->game();
        $this->balance(2);
        $this->placed(2, [$this->leg($a['home'], 100)]);
        $this->placed(2, [$this->leg($a['home']), $this->leg($b['away'])], 'parlay', 100);
        $this->kickoff($a['event']);
        $this->kickoff($b['event']);

        $this->settlement()->voidEvent(Event::query()->findOrFail($a['event']));
        $this->settlement()->voidEvent(Event::query()->findOrFail($a['event']));

        $single = Bet::query()->orderBy('id')->first();
        $this->assertSame(Bet::VOID, $single->status);
        $this->assertSame(100, $single->payout);
        $this->assertSame(Bet::OPEN, Bet::query()->orderBy('id')->skip(1)->first()->status);

        $this->score($b['event'], 0, 3);
        $parlay = Bet::query()->orderBy('id')->skip(1)->first();
        $this->assertSame(Bet::WON, $parlay->status);
        $this->assertSame(250, $parlay->payout);
        $this->assertSame(1000 - 200 + 100 + 250, $this->stored(2));
        $this->assertSame(Event::VOID, Event::query()->find($a['event'])->status);
    }

    #[Test]
    public function a_two_way_moneyline_pushes_on_a_tie_and_a_three_way_pays_the_draw(): void
    {
        $g = $this->game();
        $s = new Selection();
        $s->market_id = $g['ml'];
        $s->key = 'draw';
        $s->odds = 250;
        $s->save();

        $two = $this->game();
        $this->balance(2);
        $this->placed(2, [$this->leg($g['home'], 100), $this->leg($s->id, 100), $this->leg($two['home'], 100)]);
        $this->kickoff($g['event']);
        $this->kickoff($two['event']);

        $this->score($g['event'], 1, 1);
        $this->score($two['event'], 7, 7);

        $this->assertSame([Bet::LOST, Bet::WON, Bet::PUSH], Bet::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame([0, 350, 100], Bet::query()->orderBy('id')->pluck('payout')->all());
    }

    #[Test]
    public function a_custom_market_is_settled_by_naming_its_results(): void
    {
        $this->app();
        $event = new Event();
        $event->title = 'Who wins the Heisman?';
        $event->starts_at = Carbon::now()->addWeek();
        $event->status = Event::OPEN;
        $event->save();

        $response = $this->send($this->request('PATCH', '/api/longshot/admin/events/'.$event->id, ['authenticatedAs' => 1, 'json' => [
            'title' => 'Who wins the Heisman?', 'startsAt' => Carbon::now()->addWeek()->toIso8601String(),
            'markets' => [['type' => 'custom', 'label' => 'Winner', 'selections' => [
                ['label' => 'Player A', 'odds' => 200], ['label' => 'Player B', 'odds' => 300], ['label' => 'Field', 'odds' => -150],
            ]]],
        ]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $market = $this->json($response)['data']['markets'][0];
        [$a, $b, $field] = array_column($market['selections'], 'id');

        $this->balance(2);
        $this->placed(2, [$this->leg($a, 100), $this->leg($b, 100)]);

        $resolve = fn (array $results) => $this->send($this->request('POST', '/api/longshot/admin/markets/'.$market['id'].'/resolve', ['authenticatedAs' => 1, 'json' => ['results' => $results]]));

        // Every option needs a result, and someone must win.
        $this->assertSame(422, $resolve([$a => 'win'])->getStatusCode());
        $this->assertSame(422, $resolve([$a => 'lose', $b => 'lose', $field => 'lose'])->getStatusCode());
        $this->assertSame(Bet::OPEN, Bet::query()->value('status'));

        $this->assertSame(200, $resolve([$a => 'lose', $b => 'win', $field => 'lose'])->getStatusCode());
        $this->assertSame(200, $resolve([$a => 'win', $b => 'lose', $field => 'lose'])->getStatusCode()); // ignored: already settled

        $this->assertSame([Bet::LOST, Bet::WON], Bet::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(800 + 400, $this->stored(2));
    }

    #[Test]
    public function the_sweep_finishes_what_an_interrupted_run_left(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->placed(2, [$this->leg($g['home'], 170)]);
        $this->kickoff($g['event']);

        // A run that scored the event and died before grading anything.
        Event::query()->whereKey($g['event'])->update(['status' => Event::SETTLED, 'home_score' => 3, 'away_score' => 0]);
        $this->settlement()->sweep();
        $this->assertSame(Bet::WON, Bet::query()->value('status'));

        // A run that graded the legs and died before paying.
        $h = $this->game();
        $this->placed(2, [$this->leg($h['home'], 170)]);
        $this->kickoff($h['event']);
        Event::query()->whereKey($h['event'])->update(['status' => Event::SETTLED, 'home_score' => 3, 'away_score' => 0]);
        Market::query()->where('event_id', $h['event'])->update(['status' => Market::SETTLED]);
        $this->database()->table('longshot_bet_legs')->where('event_id', $h['event'])->update(['result' => 'win']);

        $this->settlement()->sweep();
        $this->assertSame([Bet::WON, Bet::WON], Bet::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(1000 - 340 + 540, $this->stored(2));
    }

    #[Test]
    public function staff_score_and_void_through_the_api_and_members_cannot(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->placed(2, [$this->leg($g['home'], 170)]);

        $this->assertSame(403, $this->send($this->request('POST', '/api/longshot/admin/events/'.$g['event'].'/score', ['authenticatedAs' => 2, 'json' => ['home' => 9, 'away' => 0]]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/longshot/admin/events/'.$g['event'].'/void', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/longshot/admin/grants', ['authenticatedAs' => 2, 'json' => ['user' => 2, 'amount' => 1000000]]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('GET', '/api/longshot/admin/events', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(Bet::OPEN, Bet::query()->value('status'));

        $this->assertSame(422, $this->send($this->request('POST', '/api/longshot/admin/events/'.$g['event'].'/score', ['authenticatedAs' => 1, 'json' => ['home' => -1, 'away' => 0]]))->getStatusCode());
        $this->assertSame(422, $this->send($this->request('POST', '/api/longshot/admin/events/'.$g['event'].'/score', ['authenticatedAs' => 1, 'json' => ['home' => '2.5', 'away' => 0]]))->getStatusCode());

        $ok = $this->send($this->request('POST', '/api/longshot/admin/events/'.$g['event'].'/score', ['authenticatedAs' => 1, 'json' => ['home' => 9, 'away' => 0]]));
        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $this->assertSame(422, $this->send($this->request('POST', '/api/longshot/admin/events/'.$g['event'].'/score', ['authenticatedAs' => 1, 'json' => ['home' => 0, 'away' => 9]]))->getStatusCode());
        $this->assertSame(Bet::WON, Bet::query()->value('status'));

        // Nothing can be deleted once bet on.
        $this->assertSame(422, $this->send($this->request('DELETE', '/api/longshot/admin/events/'.$g['event'], ['authenticatedAs' => 1]))->getStatusCode());
    }
}
