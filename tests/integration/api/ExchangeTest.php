<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Model\OfferMatch;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ExchangeTest extends TestCase
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

    private function offer(int $user, int $selection, int $odds, int $stake, ?string $line = null, ?int $target = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/longshot/offers', ['authenticatedAs' => $user, 'json' => array_filter([
            'selection' => $selection, 'odds' => $odds, 'stake' => $stake, 'line' => $line, 'target' => $target,
        ], fn ($v) => $v !== null)]));
    }

    private function take(int $user, int $offer, int $back): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/longshot/offers/'.$offer.'/matches', ['authenticatedAs' => $user, 'json' => ['back' => $back]]));
    }

    #[Test]
    public function backer_and_layers_put_up_their_sides_and_the_winner_takes_the_pot(): void
    {
        $g = $this->game();
        foreach ([2, 3, 4] as $u) {
            $this->balance($u);
        }

        // Carol backs the away side at +200 for 100.
        $response = $this->offer(3, $g['away'], 200, 100);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $offer = $this->json($response)['data']['id'];
        $this->assertSame(900, $this->stored(3));

        // Normal lays 30 of it (risking 60), Dave the remaining 70 (risking 140).
        $this->assertSame(201, $this->take(2, $offer, 30)->getStatusCode());
        $this->assertSame(940, $this->stored(2));
        $this->assertSame(422, $this->take(4, $offer, 71)->getStatusCode());
        $this->assertSame(201, $this->take(4, $offer, 70)->getStatusCode());
        $this->assertSame(860, $this->stored(4));
        $this->assertSame(Offer::CLOSED, Offer::query()->value('status'));
        $this->assertSame('longshot_offer_closed', $this->json($this->take(2, $offer, 1))['errors'][0]['code']);

        Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);
        $this->score($g['event'], 10, 20); // away wins: Carol takes both pots

        $this->assertSame(900 + 30 + 60 + 70 + 140, $this->stored(3));
        $this->assertSame(940, $this->stored(2));
        $this->assertSame(860, $this->stored(4));
        $this->assertSame([OfferMatch::BACK, OfferMatch::BACK], OfferMatch::query()->orderBy('id')->pluck('result')->all());
        $this->assertSame(Offer::SETTLED, Offer::query()->value('status'));

        // A second sweep pays nothing more.
        $this->settlement()->sweep();
        $this->assertSame(1200, $this->stored(3));
        // Points are neither made nor lost: the three wallets still hold 3000.
        $this->assertSame(3000, $this->stored(2) + $this->stored(3) + $this->stored(4));
    }

    #[Test]
    public function the_layer_wins_and_pushes_return_both_stakes(): void
    {
        $a = $this->game([], '-3.0');
        $b = $this->game();
        $this->balance(2);
        $this->balance(3);

        $lose = $this->json($this->offer(3, $a['home'], -200, 100))['data']['id']; // layer risks 50
        $push = $this->json($this->offer(3, $a['home_spread'], -110, 110, '-3.0'))['data']['id']; // layer risks 100
        $this->assertSame(201, $this->take(2, $lose, 100)->getStatusCode());
        $this->assertSame(201, $this->take(2, $push, 110)->getStatusCode());
        $this->assertSame(1000 - 50 - 100, $this->stored(2));

        $this->score($a['event'], 20, 23); // away wins outright; home -3 at 20-23 loses... and the spread loses too
        $this->assertSame(1000 - 50 - 100 + 150 + 210, $this->stored(2));

        // A push on b's total returns each side's own stake.
        $total = $this->json($this->offer(3, $b['over'], 100, 50, '44.5'))['data']['id'];
        $this->take(2, $total, 50);
        $this->settlement()->voidEvent(Event::query()->findOrFail($b['event']));
        $this->assertSame(1210, $this->stored(2));
        $this->assertSame(1000 - 210, $this->stored(3));
        $this->assertSame(2000, $this->stored(2) + $this->stored(3));
    }

    #[Test]
    public function unmatched_stake_comes_back_once_on_cancel_or_kickoff(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->balance(3);

        $cancelled = $this->json($this->offer(3, $g['away'], 150, 100))['data']['id'];
        $this->take(2, $cancelled, 40);

        // Only the backer (or staff) may cancel.
        $this->assertSame(403, $this->send($this->request('DELETE', '/api/longshot/offers/'.$cancelled, ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', '/api/longshot/offers/'.$cancelled, ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', '/api/longshot/offers/'.$cancelled, ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(1000 - 40, $this->stored(3));

        $kickoff = $this->json($this->offer(3, $g['home'], 150, 100))['data']['id'];
        Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subMinute()]);
        $this->assertSame('longshot_closed', $this->json($this->take(2, $kickoff, 10))['errors'][0]['code']);
        $this->settlement()->sweep();
        $this->settlement()->sweep();
        $this->assertSame(1000 - 40, $this->stored(3));
        $this->assertSame(Offer::SETTLED, Offer::query()->find($kickoff)->status);
        $this->assertSame(1, $this->database()->table('longshot_ledger')->where('ref', 'offer:'.$kickoff.':unmatched')->count());
    }

    #[Test]
    public function members_cannot_take_their_own_offer_or_a_challenge_meant_for_someone_else(): void
    {
        $g = $this->game();
        foreach ([2, 3, 4] as $u) {
            $this->balance($u);
        }

        $open = $this->json($this->offer(3, $g['away'], 150, 100))['data']['id'];
        $this->assertSame('longshot_own_offer', $this->json($this->take(3, $open, 10))['errors'][0]['code']);

        // Carol challenges Dave directly.
        $challenge = $this->offer(3, $g['home'], 120, 50, null, 4);
        $this->assertSame(201, $challenge->getStatusCode(), (string) $challenge->getBody());
        $challenge = $this->json($challenge)['data']['id'];
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'longshotChallenge')->where('user_id', 4)->count());

        // Normal can neither see nor take it, by list, by event or by id.
        $this->assertSame('longshot_gone', $this->json($this->take(2, $challenge, 10))['errors'][0]['code']);
        $listed = array_column($this->json($this->send($this->request('GET', '/api/longshot/offers', ['authenticatedAs' => 2])))['data'], 'id');
        $this->assertNotContains($challenge, $listed);
        $this->assertContains($open, $listed);
        $event = array_column($this->json($this->send($this->request('GET', '/api/longshot/events/'.$g['event'], ['authenticatedAs' => 2])))['meta']['offers'], 'id');
        $this->assertNotContains($challenge, $event);
        $this->assertSame(422, $this->send($this->request('DELETE', '/api/longshot/offers/'.$challenge, ['authenticatedAs' => 2]))->getStatusCode());

        // Dave sees it and takes it.
        $mine = array_column($this->json($this->send($this->request('GET', '/api/longshot/offers?scope=challenges', ['authenticatedAs' => 4])->withQueryParams(['scope' => 'challenges'])))['data'], 'id');
        $this->assertSame([$challenge], $mine);
        $this->assertSame(201, $this->take(4, $challenge, 50)->getStatusCode());

        // Challenging yourself, or a member who does not exist, is refused.
        $this->assertSame('longshot_target', $this->json($this->offer(3, $g['home'], 120, 50, null, 3))['errors'][0]['code']);
        $this->assertSame('longshot_target', $this->json($this->offer(3, $g['home'], 120, 50, null, 999))['errors'][0]['code']);
    }

    #[Test]
    public function offers_check_prices_stakes_and_lines(): void
    {
        $g = $this->game();
        $this->balance(3);

        $this->assertSame('longshot_odds', $this->json($this->offer(3, $g['away'], 50, 100))['errors'][0]['code']);
        $this->assertSame('longshot_odds', $this->json($this->offer(3, $g['away'], 20000, 100))['errors'][0]['code']);
        $this->assertSame('longshot_min_stake', $this->json($this->offer(3, $g['away'], 150, 0))['errors'][0]['code']);
        $this->assertSame('longshot_max_stake', $this->json($this->offer(3, $g['away'], 150, 5000))['errors'][0]['code']);
        $this->assertSame('longshot_prices', $this->json($this->offer(3, $g['home_spread'], -110, 10, '-2.5'))['errors'][0]['code']);
        $this->assertSame(1000, $this->stored(3));

        $this->assertSame(201, $this->offer(3, $g['away'], 150, 1000)->getStatusCode());
        $this->assertSame('longshot_balance', $this->json($this->offer(3, $g['home'], 150, 1))['errors'][0]['code']);
        $this->assertSame(0, $this->stored(3));
        $this->assertSame(1, Offer::query()->count());
    }

    #[Test]
    public function a_layer_without_the_points_for_the_liability_is_refused(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->balance(3);
        $offer = $this->json($this->offer(3, $g['away'], 1000, 100))['data']['id']; // liability 1000
        $this->database()->table('longshot_wallets')->where('user_id', 2)->update(['balance' => 999]);

        $this->assertSame('longshot_balance', $this->json($this->take(2, $offer, 100))['errors'][0]['code']);
        $this->assertSame(0, OfferMatch::query()->count());
        $this->assertSame(0, Offer::query()->value('matched'));
        $this->assertSame(999, $this->stored(2));
    }

    #[Test]
    public function deleting_a_member_returns_everyone_else_their_stake(): void
    {
        $g = $this->game();
        $this->balance(2);
        $this->balance(3);
        $offer = $this->json($this->offer(3, $g['away'], 150, 100))['data']['id'];
        $this->take(2, $offer, 100); // normal risks 150

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/users/3', ['authenticatedAs' => 1]))->getStatusCode());

        $this->assertSame(1000, $this->stored(2));
        // Carol's rows are gone, on every database, cascade or not.
        $this->assertSame(0, Offer::query()->count());
        $this->assertSame(0, OfferMatch::query()->count());
        $this->assertSame(0, $this->database()->table('longshot_ledger')->where('user_id', 3)->count());
        // And the sweep has nothing left to trip over.
        $this->settlement()->sweep();
    }
}
