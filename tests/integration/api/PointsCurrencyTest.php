<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Betting FoF Gamification points, through its point grants. Runs only where
 * the installed Gamification has them (FriendsOfFlarum/gamification#180).
 */
class PointsCurrencyTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\FoF\Gamification\PointGrants::class) || ! method_exists(\FoF\Gamification\PointGrants::class, 'total')) {
            $this->markTestSkipped('This FoF Gamification has no point grants.');
        }

        $this->extension('fof-gamification', 'ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.currency', 'points');
        $this->setting('ernestdefoe-longshot.starting_balance', 300);
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    private function votes(int $user): int
    {
        return (int) $this->database()->table('users')->where('id', $user)->value('votes');
    }

    #[Test]
    public function stakes_and_payouts_move_gamification_points_once(): void
    {
        $g = $this->game();

        // The starting balance is granted as points.
        $this->assertSame(300, $this->balance(2));
        $this->assertSame(300, $this->votes(2));

        $this->assertSame(201, $this->bet(2, [$this->leg($g['home'], 170)])->getStatusCode());
        $this->assertSame(130, $this->votes(2));
        $this->assertSame('longshot_balance', $this->json($this->bet(2, [$this->leg($g['away'], 131)]))['errors'][0]['code']);

        Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);
        $this->score($g['event'], 3, 0);
        $this->settlement()->sweep();

        $this->assertSame(Bet::WON, Bet::query()->value('status'));
        $this->assertSame(130 + 270, $this->votes(2));
        $this->assertSame(3, $this->database()->table('gamification_point_grants')->where('user_id', 2)->where('source', 'longshot')->count());

        // A recount from votes keeps the grants: points do not drift back.
        \FoF\Gamification\Vote::updateUserVotes($this->user(2))->save();
        $this->assertSame(400, $this->votes(2));
    }
}
