<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Exchange;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Model\OfferMatch;
use ErnestDefoe\Longshot\Placer;
use ErnestDefoe\Longshot\Rejected;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Hundreds of random bets, parlays, offers, lays and withdrawals, then every
 * game scored or voided: no point may be made or lost along the way.
 */
class ConservationTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    /** The exchange and repeated settlement are exercised hundreds of times on purpose. */
    protected function detectsRepeatedQueries(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->setting('ernestdefoe-longshot.max_stake', 400);
        $this->setting('ernestdefoe-longshot.starting_balance', 5000);
        $this->prepareDatabase([User::class => array_merge([$this->normalUser()], $this->members())]);
    }

    #[Test]
    public function random_play_conserves_every_point(): void
    {
        mt_srand(20261021);
        $members = [2, 3, 4];
        foreach ($members as $m) {
            $this->balance($m);
        }

        $games = [];
        for ($i = 0; $i < 5; $i++) {
            $games[] = $this->game([], ['-3.5', '-7.0', '2.5', '0.0', '-1.5'][$i], ['44.5', '41.0', '50.5', '38.0', '47.5'][$i]);
        }
        $keys = ['home', 'away', 'home_spread', 'away_spread', 'over', 'under'];
        $placer = $this->app()->getContainer()->make(Placer::class);
        $exchange = $this->app()->getContainer()->make(Exchange::class);

        $refused = 0;
        for ($op = 0; $op < 500; $op++) {
            $actor = $this->user($members[mt_rand(0, 2)]);
            $g = $games[mt_rand(0, 4)];
            $sel = $g[$keys[mt_rand(0, 5)]];

            try {
                switch (mt_rand(0, 4)) {
                    case 0:
                    case 1:
                        $placer->place($actor, 'single', [$this->leg($sel, mt_rand(1, 120))]);
                        break;
                    case 2:
                        $legs = array_map(fn ($game) => $this->leg($game[$keys[mt_rand(0, 5)]]), array_slice($games, mt_rand(0, 2), mt_rand(2, 3)));
                        $placer->place($actor, 'parlay', $legs, mt_rand(1, 60));
                        break;
                    case 3:
                        $l = $this->leg($sel);
                        $exchange->offer($actor, $sel, [150, -120, 200, 110, -200][mt_rand(0, 4)], $l['line'], mt_rand(1, 80));
                        break;
                    case 4:
                        $offer = Offer::query()->where('status', Offer::OPEN)->inRandomOrder()->first();
                        if ($offer && mt_rand(0, 3) === 0 && $offer->user_id === $actor->id) {
                            $exchange->cancel($actor, $offer->id);
                        } elseif ($offer) {
                            $exchange->match($actor, $offer->id, max(1, mt_rand(1, max(1, $offer->remaining()))));
                        }
                        break;
                }
            } catch (Rejected) {
                $refused++;
            }

            $this->assertBooksBalance($members);
        }

        $this->assertGreaterThan(100, Bet::query()->count());
        $this->assertGreaterThan(10, OfferMatch::query()->count());

        foreach ($games as $i => $g) {
            Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);
        }
        $this->settlement()->sweep();
        foreach ($games as $i => $g) {
            $i === 2
                ? $this->settlement()->voidEvent(Event::query()->findOrFail($g['event']))
                : $this->score($g['event'], [24, 27, 17, 10, 21][$i], [20, 20, 21, 10, 21][$i]);
        }
        $this->settlement()->sweep();
        $this->settlement()->sweep();

        $this->assertSame(0, Bet::query()->where('status', Bet::OPEN)->count());
        $this->assertSame(0, OfferMatch::query()->whereNull('result')->count());
        $this->assertSame(0, Offer::query()->whereIn('status', [Offer::OPEN, Offer::CLOSED])->count());
        $this->assertBooksBalance($members);

        // Everything that came in or went out of the three wallets is the bets'
        // net result: the exchange moves points between members, never makes them.
        $start = 5000 * count($members);
        $net = (int) Bet::query()->sum('payout') - (int) Bet::query()->sum('stake');
        $this->assertSame($start + $net, (int) $this->database()->table('longshot_wallets')->sum('balance'));
    }

    /** @param list<int> $members */
    private function assertBooksBalance(array $members): void
    {
        foreach ($members as $m) {
            $wallet = (int) $this->database()->table('longshot_wallets')->where('user_id', $m)->value('balance');
            $this->assertGreaterThanOrEqual(0, $wallet);
            $this->assertSame((int) $this->database()->table('longshot_ledger')->where('user_id', $m)->sum('amount'), $wallet, "member $m: wallet and ledger disagree");
        }

        // Every stake in play is accounted for: open bets, unmatched offer
        // stakes and unsettled matches, against what left the wallets.
        $held = (int) Bet::query()->where('status', Bet::OPEN)->sum('stake')
            + (int) Offer::query()->where('status', Offer::OPEN)->get()->sum(fn ($o) => $o->stake - $o->matched)
            + (int) OfferMatch::query()->whereNull('result')->get()->sum(fn ($mt) => $mt->back_stake + $mt->lay_stake);
        $out = -(int) $this->database()->table('longshot_ledger')->whereIn('kind', ['stake', 'offer', 'lay'])->sum('amount');
        $back = (int) $this->database()->table('longshot_ledger')->whereIn('kind', ['payout', 'refund'])->sum('amount');
        $settledBets = (int) Bet::query()->where('status', '!=', Bet::OPEN)->sum('stake');
        $settledMatches = (int) OfferMatch::query()->whereNotNull('result')->get()->sum(fn ($mt) => $mt->back_stake + $mt->lay_stake);
        $refundedOffers = (int) $this->database()->table('longshot_ledger')->where('ref', 'like', 'offer:%:unmatched')->sum('amount');
        $wonOnBets = (int) Bet::query()->where('status', '!=', Bet::OPEN)->sum('payout') - $settledBets;

        $this->assertSame($out, $held + $settledBets + $settledMatches + $refundedOffers, 'every staked point is held or settled');
        $this->assertSame($back, $settledBets + $wonOnBets + $settledMatches + $refundedOffers, 'every point paid back is a settled stake or a win');
    }
}
