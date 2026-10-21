<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class BetTest extends TestCase
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

    #[Test]
    public function guests_see_the_board_but_cannot_bet(): void
    {
        $g = $this->game();

        $board = $this->send($this->request('GET', '/api/longshot'));
        $this->assertSame(200, $board->getStatusCode(), (string) $board->getBody());
        $data = $this->json($board);
        $this->assertCount(1, $data['data']);
        $this->assertNull($data['meta']['wallet']);
        $this->assertSame(['NFL'], $data['meta']['leagues']);

        $response = $this->send($this->request('POST', '/api/longshot/bets', ['json' => ['legs' => [$this->leg($g['home'], 10)]]]));
        // 400: a guest has no CSRF token; with one it would be 401.
        $this->assertContains($response->getStatusCode(), [400, 401, 403]);
        $this->assertSame(0, Bet::query()->count());
    }

    #[Test]
    public function the_starting_balance_is_paid_once(): void
    {
        $this->assertSame(1000, $this->balance(2));
        $this->assertSame(1000, $this->balance(2));
        $this->assertSame(1, $this->database()->table('longshot_ledger')->where('user_id', 2)->count());
    }

    #[Test]
    public function a_single_takes_the_stake_and_locks_the_price_and_line(): void
    {
        $g = $this->game();
        $this->balance(2);

        $response = $this->bet(2, [$this->leg($g['home_spread'], 100)]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $bet = $this->json($response)['data'][0];
        $this->assertSame(100, $bet['stake']);
        $this->assertSame(190, $bet['potential']);
        $this->assertSame('-3.5', $bet['legs'][0]['line']);
        $this->assertSame(-110, $bet['legs'][0]['odds']);
        $this->assertSame(900, $this->stored(2));
        $this->assertSame(900, $this->json($response)['meta']['wallet']['balance']);
    }

    #[Test]
    public function a_moved_price_or_line_refuses_the_slip_with_the_new_prices(): void
    {
        $g = $this->game();
        $this->balance(2);
        $leg = $this->leg($g['home_spread'], 100);

        Selection::query()->whereKey($g['home_spread'])->update(['odds' => -120]);
        $response = $this->bet(2, [$leg]);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(-120, $this->json($response)['meta']['prices'][0]['odds']);

        Selection::query()->whereKey($g['home_spread'])->update(['odds' => -110]);
        Market::query()->whereKey($g['spread'])->update(['line' => '-4.5']);
        $response = $this->bet(2, [$leg]);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('-4.5', $this->json($response)['meta']['prices'][0]['line']);

        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(1000, $this->stored(2));
    }

    #[Test]
    public function a_better_price_is_refused_unless_the_forum_accepts_better_odds(): void
    {
        $g = $this->game();
        $this->balance(2);
        $leg = $this->leg($g['away'], 100); // +150
        Selection::query()->whereKey($g['away'])->update(['odds' => 170]);

        $this->assertSame(409, $this->bet(2, [$leg])->getStatusCode());
    }

    #[Test]
    public function better_odds_are_taken_at_the_new_price_when_allowed(): void
    {
        $this->setting('ernestdefoe-longshot.accept_better_odds', true);
        $g = $this->game();
        $this->balance(2);
        $leg = $this->leg($g['away'], 100);

        Selection::query()->whereKey($g['away'])->update(['odds' => 170]);
        $response = $this->bet(2, [$leg]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(270, $this->json($response)['data'][0]['potential']);

        // Worse is still refused.
        Selection::query()->whereKey($g['away'])->update(['odds' => 120]);
        $this->assertSame(409, $this->bet(2, [$leg])->getStatusCode());
    }

    #[Test]
    public function started_suspended_and_unpriced_markets_take_no_bets(): void
    {
        $started = $this->game(['starts_at' => Carbon::now()->subMinute()]);
        $this->balance(2);
        $this->assertSame(422, $this->bet(2, [$this->leg($started['home'], 10)])->getStatusCode());

        $g = $this->game();
        Market::query()->whereKey($g['ml'])->update(['status' => Market::SUSPENDED]);
        $this->assertSame(422, $this->bet(2, [$this->leg($g['home'], 10)])->getStatusCode());

        Selection::query()->whereKey($g['over'])->update(['odds' => null]);
        $this->assertSame(422, $this->bet(2, [['selection' => $g['over'], 'odds' => -110, 'line' => '44.5', 'stake' => 10]])->getStatusCode());

        $this->assertSame(422, $this->bet(2, [['selection' => 999999, 'odds' => -110, 'line' => null, 'stake' => 10]])->getStatusCode());
        $this->assertSame(1000, $this->stored(2));
    }

    #[Test]
    public function stakes_respect_the_limits(): void
    {
        $g = $this->game();
        $this->balance(2);

        $this->assertSame(422, $this->bet(2, [$this->leg($g['home'], 0)])->getStatusCode());
        $this->assertSame(422, $this->bet(2, [$this->leg($g['home'], -50)])->getStatusCode());
        $this->assertSame(422, $this->bet(2, [$this->leg($g['home'], 1001)])->getStatusCode());
        $this->assertSame(422, $this->bet(2, [['selection' => $g['home'], 'odds' => -170, 'line' => null, 'stake' => '1e3']])->getStatusCode());
        $this->assertSame(1000, $this->stored(2));
    }

    #[Test]
    public function a_group_limit_raises_the_max_stake_and_the_daily_limit_holds(): void
    {
        $this->setting('ernestdefoe-longshot.daily_limit', 150);
        $this->setting('ernestdefoe-longshot.max_stake', 100);
        $this->prepareDatabase(['longshot_group_limits' => [['group_id' => 3, 'max_stake' => 120, 'daily_limit' => null, 'allowance' => null]]]);
        $g = $this->game();
        $this->balance(2);

        $this->assertSame(201, $this->bet(2, [$this->leg($g['home'], 120)])->getStatusCode());
        $over = $this->bet(2, [$this->leg($g['away'], 40)]);
        $this->assertSame(422, $over->getStatusCode());
        $this->assertSame('longshot_daily_limit', $this->json($over)['errors'][0]['code']);
        $this->assertSame(201, $this->bet(2, [$this->leg($g['away'], 30)])->getStatusCode());
    }

    #[Test]
    public function not_enough_points_places_nothing_at_all(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->database()->table('longshot_wallets')->where('user_id', 2)->update(['balance' => 150]);

        // Two singles of 100: the second cannot be covered, so neither is placed.
        $response = $this->bet(2, [$this->leg($g['home'], 100), $this->leg($g['over'], 100)]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('longshot_balance', $this->json($response)['errors'][0]['code']);
        $this->assertSame(0, Bet::query()->count());
        $this->assertSame(0, $this->database()->table('longshot_bet_legs')->count());
        $this->assertSame(150, $this->stored(2));
        $this->assertSame(0, $this->database()->table('longshot_ledger')->where('kind', 'stake')->count());
    }

    #[Test]
    public function a_slip_sent_twice_is_placed_once(): void
    {
        $g = $this->game();
        $this->balance(2);

        $this->assertSame(201, $this->bet(2, [$this->leg($g['home'], 10)], 'single', 0, ['nonce' => 'slip-abcdef12'])->getStatusCode());
        $this->assertSame(422, $this->bet(2, [$this->leg($g['home'], 10)], 'single', 0, ['nonce' => 'slip-abcdef12'])->getStatusCode());
        $this->assertSame(1, Bet::query()->count());
    }

    #[Test]
    public function parlays_need_distinct_events_and_a_payout_under_the_cap(): void
    {
        $this->setting('ernestdefoe-longshot.max_payout', 2000);
        $a = $this->game();
        $b = $this->game();
        $this->balance(2);

        $same = $this->bet(2, [$this->leg($a['home']), $this->leg($a['over'])], 'parlay', 10);
        $this->assertSame('longshot_same_event', $this->json($same)['errors'][0]['code']);

        $one = $this->bet(2, [$this->leg($a['home'])], 'parlay', 10);
        $this->assertSame('longshot_parlay_legs', $this->json($one)['errors'][0]['code']);

        $big = $this->bet(2, [$this->leg($a['away']), $this->leg($b['away'])], 'parlay', 400); // 400 × 6.25 = 2500
        $this->assertSame('longshot_max_payout', $this->json($big)['errors'][0]['code']);

        $ok = $this->bet(2, [$this->leg($a['away']), $this->leg($b['home_spread'])], 'parlay', 100);
        $this->assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
        // 100 × 2.5 × 1.909 = 477.25
        $this->assertSame(477, $this->json($ok)['data'][0]['potential']);
        $this->assertSame(900, $this->stored(2));
    }

    #[Test]
    public function a_member_without_the_permission_cannot_bet(): void
    {
        $this->database()->table('group_permission')->where('permission', 'longshot.bet')->delete();
        $g = $this->game();

        $this->assertSame(403, $this->bet(2, [$this->leg($g['home'], 10)])->getStatusCode());
    }
}
