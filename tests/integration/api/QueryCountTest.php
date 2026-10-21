<?php

namespace ErnestDefoe\Longshot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Tests\integration\Book;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** Every list costs the same number of queries for one item or many. */
class QueryCountTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use Book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-longshot');
        $this->setting('ernestdefoe-longshot.allowance', 0);
        $this->setting('ernestdefoe-longshot.max_stake', 100000);
        $this->prepareDatabase([User::class => array_merge([$this->normalUser()], $this->members())]);
    }

    private function queries(string $path, int $actor, array $query = []): int
    {
        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });
        $request = $this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query);
        $this->send($request); // warm: wallets opened, tokens made
        $queries = 0;
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $queries;
    }

    /** n games, each bet on by members 2 and 3, half of them offered on the exchange and settled. */
    private function book(int $n): void
    {
        $this->balance(2);
        $this->balance(3);
        $this->balance(4);

        for ($i = 0; $i < $n; $i++) {
            $g = $this->game();
            $this->assertSame(201, $this->bet(2, [$this->leg($g['home'], 10), $this->leg($g['over'], 10)])->getStatusCode());
            $this->bet(3, [$this->leg($g['away'], 10)]);
            $offer = $this->json($this->send($this->request('POST', '/api/longshot/offers', ['authenticatedAs' => 4, 'json' => ['selection' => $g['away'], 'odds' => 150, 'stake' => 20]])))['data']['id'];
            $this->send($this->request('POST', '/api/longshot/offers/'.$offer.'/matches', ['authenticatedAs' => 3, 'json' => ['back' => 10]]));

            if ($i % 2) {
                Event::query()->whereKey($g['event'])->update(['starts_at' => Carbon::now()->subHour()]);
                $this->score($g['event'], 20, 10);
            }
        }
    }

    #[Test]
    public function lists_are_flat_from_one_to_twenty(): void
    {
        $paths = [
            ['/api/longshot', 0, []],
            ['/api/longshot', 2, []],
            ['/api/longshot', 2, ['results' => '1']],
            ['/api/longshot/bets', 2, ['status' => 'open']],
            ['/api/longshot/bets', 2, ['status' => 'settled']],
            ['/api/longshot/bets', 3, ['user' => '2', 'status' => 'settled']],
            ['/api/longshot/offers', 2, []],
            ['/api/longshot/offers', 3, ['scope' => 'mine']],
            ['/api/longshot/leaderboard', 2, ['period' => 'all']],
            ['/api/longshot/wallet', 2, []],
            ['/api/longshot/admin/events', 1, []],
        ];

        $this->book(2);
        $small = array_map(fn ($p) => $this->queries(...$p), $paths);

        $this->book(18);
        $large = array_map(fn ($p) => $this->queries(...$p), $paths);

        foreach ($paths as $i => [$path, $actor, $query]) {
            $this->assertSame($small[$i], $large[$i], "$path ".json_encode($query)." as $actor: {$small[$i]} queries at 2, {$large[$i]} at 20");
        }
    }

    #[Test]
    public function the_board_for_a_guest_costs_a_handful_of_queries(): void
    {
        $this->book(3);

        $this->assertLessThanOrEqual(8, $this->queries("/api/longshot", 0));
    }
}
