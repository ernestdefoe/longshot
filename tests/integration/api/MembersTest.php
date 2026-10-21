<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** History, privacy, leaderboards and the weekly allowance. */
class MembersTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-longshot');
        $this->prepareDatabase([User::class => array_merge([$this->normalUser()], $this->members())]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function history(int $actor, int $user, string $status = 'settled'): array
    {
        return $this->json($this->send($this->request('GET', '/api/longshot/bets', ['authenticatedAs' => $actor])->withQueryParams(['user' => (string) $user, 'status' => $status])));
    }

    #[Test]
    public function others_see_only_settled_bets_and_never_a_private_members(): void
    {
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $a = $this->game();
        $b = $this->game();
        $this->balance(2);
        $this->bet(2, [$this->leg($a['home'], 10)]);
        $this->bet(2, [$this->leg($b['home'], 10)]);
        Event::query()->whereKey($a['event'])->update(['starts_at' => Carbon::now()->subHour()]);
        $this->score($a['event'], 3, 0);

        $this->assertCount(1, $this->history(2, 2, 'open')['data']);
        $this->assertCount(1, $this->history(2, 2)['data']);

        // Carol asks for open bets and gets the settled one only.
        $seen = $this->history(3, 2, 'open');
        $this->assertCount(1, $seen['data']);
        $this->assertSame('won', $seen['data'][0]['status']);

        // Private: nothing, to anyone but the member and staff.
        $patch = $this->send($this->request('PATCH', '/api/longshot/wallet', ['authenticatedAs' => 2, 'json' => ['private' => true]]));
        $this->assertSame(200, $patch->getStatusCode(), (string) $patch->getBody());
        $this->assertSame([], $this->history(3, 2)['data']);
        $this->assertTrue($this->history(3, 2)['meta']['hidden']);
        $this->assertCount(1, $this->history(2, 2)['data']);
        $this->assertCount(1, $this->history(1, 2, 'open')['data']);

        // Guests: nothing.
        $guest = $this->json($this->send($this->request('GET', '/api/longshot/bets')->withQueryParams(['user' => '2'])));
        $this->assertSame([], $guest['data']);
    }

    #[Test]
    public function the_forum_can_keep_all_history_private(): void
    {
        $this->setting('ernestdefoe-longshot.public_history', false);
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $a = $this->game();
        $this->balance(2);
        $this->bet(2, [$this->leg($a['home'], 10)]);
        Event::query()->whereKey($a['event'])->update(['starts_at' => Carbon::now()->subHour()]);
        $this->score($a['event'], 3, 0);

        $this->assertSame([], $this->history(3, 2)['data']);
        $this->assertCount(1, $this->history(2, 2)['data']);
    }

    #[Test]
    public function the_leaderboard_ranks_profit_from_bets_and_the_exchange(): void
    {
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $g = $this->game();
        foreach ([2, 3, 4] as $u) {
            $this->balance($u);
        }

        $this->bet(2, [$this->leg($g['home'], 170)]);   // wins 100
        $this->bet(3, [$this->leg($g['away'], 100)]);   // loses 100
        $offer = $this->json($this->send($this->request('POST', '/api/longshot/offers', ['authenticatedAs' => 4, 'json' => ['selection' => $g['away'], 'odds' => 300, 'stake' => 50]])))['data']['id'];
        $this->send($this->request('POST', '/api/longshot/offers/'.$offer.'/matches', ['authenticatedAs' => 3, 'json' => ['back' => 50]])); // Carol lays: wins 50
        Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);
        $this->score($g['event'], 21, 7);

        $board = $this->json($this->send($this->request('GET', '/api/longshot/leaderboard', ['authenticatedAs' => 4])));
        $rows = array_map(fn ($r) => [$r['user']['id'], $r['profit']], $board['data']);
        $this->assertSame([[2, 100], [3, -50], [4, -50]], $rows);
        // Tied with Carol on -50: ties share a rank.
        $this->assertSame(2, $board['meta']['me']['rank']);
        $this->assertSame(100.0, (float) $board['data'][0]['winRate']);

        // Carol goes private: off everyone else's board, still on her own.
        $this->send($this->request('PATCH', '/api/longshot/wallet', ['authenticatedAs' => 3, 'json' => ['private' => true]]));
        $ids = array_column(array_column($this->json($this->send($this->request('GET', '/api/longshot/leaderboard', ['authenticatedAs' => 4])))['data'], 'user'), 'id');
        $this->assertSame([2, 4], $ids);
        $own = $this->json($this->send($this->request('GET', '/api/longshot/leaderboard', ['authenticatedAs' => 3])));
        $this->assertSame(2, $own['meta']['me']['rank']);

        $balances = $this->json($this->send($this->request('GET', '/api/longshot/leaderboard')->withQueryParams(['by' => 'balance'])));
        $this->assertSame('balance', $balances['meta']['by']);
        $this->assertSame(2, $balances['data'][0]['user']['id']);
        $this->assertSame(1100, $balances['data'][0]['balance']);
    }

    #[Test]
    public function the_weekly_allowance_is_added_once_a_week(): void
    {
        $this->setting('ernestdefoe-longshot.allowance', 250);
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));

        $this->assertSame(1250, $this->balance(2));
        $this->assertSame(1250, $this->balance(2));

        Carbon::setTestNow(Carbon::parse('2026-10-25 23:59:00', 'UTC'));
        $this->assertSame(1250, $this->balance(2));

        Carbon::setTestNow(Carbon::parse('2026-10-26 00:00:01', 'UTC'));
        $this->assertSame(1500, $this->balance(2));
        $this->assertSame(2, $this->database()->table('longshot_ledger')->where('kind', 'allowance')->count());
    }

    #[Test]
    public function a_group_allowance_raises_it_and_top_up_never_takes_away(): void
    {
        $this->setting('ernestdefoe-longshot.allowance', 500);
        $this->setting('ernestdefoe-longshot.allowance_mode', 'topup');
        $this->setting('ernestdefoe-longshot.starting_balance', 0);
        $this->prepareDatabase(['longshot_group_limits' => [['group_id' => 3, 'max_stake' => null, 'daily_limit' => null, 'allowance' => 800]]]);
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));

        $this->assertSame(800, $this->balance(2));

        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
        $this->database()->table('longshot_wallets')->where('user_id', 2)->update(['balance' => 900]);
        $this->assertSame(900, $this->balance(2));

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
        $this->database()->table('longshot_wallets')->where('user_id', 2)->update(['balance' => 50]);
        $this->assertSame(800, $this->balance(2));
    }

    #[Test]
    public function a_wallet_is_only_ever_the_actors_own(): void
    {
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->balance(3);
        $this->send($this->request('PATCH', '/api/longshot/wallet', ['authenticatedAs' => 2, 'json' => ['private' => true, 'user' => 3, 'balance' => 99999]]));

        $this->assertFalse((bool) $this->database()->table('longshot_wallets')->where('user_id', 3)->value('private'));
        $this->assertSame(1000, $this->stored(2));
        $this->assertSame(1000, $this->stored(3));
        $this->assertSame(401, $this->send($this->request('GET', '/api/longshot/wallet'))->getStatusCode());
    }

    #[Test]
    public function staff_grants_move_points_through_the_ledger(): void
    {
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->balance(2);

        $this->assertSame(200, $this->send($this->request('POST', '/api/longshot/admin/grants', ['authenticatedAs' => 1, 'json' => ['user' => 'normal', 'amount' => 500]]))->getStatusCode());
        $this->assertSame(1500, $this->stored(2));
        $this->assertSame(422, $this->send($this->request('POST', '/api/longshot/admin/grants', ['authenticatedAs' => 1, 'json' => ['user' => 2, 'amount' => -2000]]))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('POST', '/api/longshot/admin/grants', ['authenticatedAs' => 1, 'json' => ['user' => 2, 'amount' => -1500]]))->getStatusCode());
        $this->assertSame(0, $this->stored(2));
        $this->assertSame(422, $this->send($this->request('POST', '/api/longshot/admin/grants', ['authenticatedAs' => 1, 'json' => ['user' => 'nobody', 'amount' => 5]]))->getStatusCode());
    }
}
