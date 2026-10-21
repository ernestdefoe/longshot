<?php

namespace ErnestDefoe\Longshot\Api;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\OfferMatch;
use ErnestDefoe\Longshot\Presenter;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/longshot/leaderboard?period=week|month|all&by=profit|balance
 *
 * Profit counts settled bets and settled exchange matches alike, by when they
 * were settled. Members who keep their wallet private, and members the viewer
 * may not see, are left off; the viewer always sees their own standing.
 */
class LeaderboardController implements RequestHandlerInterface
{
    use Concerns;

    public const SIZE = 25;

    public function __construct(private ConnectionInterface $db, private Presenter $presenter, private Config $config)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.view');

        $query = $request->getQueryParams();
        $period = in_array($query['period'] ?? '', ['week', 'month', 'all'], true) ? $query['period'] : 'week';
        $by = ($query['by'] ?? '') === 'balance' && $this->config->currency() === Config::OWN ? 'balance' : 'profit';

        $rows = $by === 'balance' ? $this->balances($actor) : $this->profits($actor, $period);
        $mine = null;

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->all())->get()->keyBy('id');
        $data = [];

        foreach ($rows as $i => $row) {
            if ($user = $users->get((int) $row->user_id)) {
                $data[] = ['rank' => $i + 1, 'user' => $this->presenter->user($user)] + $this->figures($row, $by);
            }
        }

        if (! $actor->isGuest()) {
            $all = $by === 'balance' ? $this->balanceQuery($actor) : $this->profitQuery($actor, $period);
            $me = (clone $all)->where('user_id', $actor->id)->first();

            if ($me) {
                $value = $by === 'balance' ? $me->balance : $me->profit;
                $ahead = $this->db->query()->fromSub($all, 't')->where($by, '>', $value)->count();
                $mine = ['rank' => $ahead + 1] + $this->figures($me, $by);
            }
        }

        return new JsonResponse(['data' => $data, 'meta' => ['period' => $period, 'by' => $by, 'me' => $mine]]);
    }

    /** @return array<string, int|float> */
    private function figures(object $row, string $by): array
    {
        if ($by === 'balance') {
            return ['balance' => (int) $row->balance];
        }

        $decided = (int) $row->decided;

        return [
            'profit' => (int) $row->profit,
            'staked' => (int) $row->staked,
            'settled' => (int) $row->settled,
            'winRate' => $decided ? round(100 * (int) $row->won / $decided, 1) : 0.0,
            'roi' => (int) $row->staked ? round(100 * (int) $row->profit / (int) $row->staked, 1) : 0.0,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    private function profits(User $actor, string $period)
    {
        return $this->profitQuery($actor, $period)->orderByDesc('profit')->orderBy('user_id')->limit(self::SIZE)->get();
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    private function balances(User $actor)
    {
        return $this->balanceQuery($actor)->orderByDesc('balance')->orderBy('user_id')->limit(self::SIZE)->get();
    }

    /**
     * One row per member: profit, staked, settled, won and decided, from the
     * three places a result lands: bets, matches as backer, matches as layer.
     */
    private function profitQuery(User $actor, string $period): Builder
    {
        $g = $this->db->getQueryGrammar();
        $since = match ($period) {
            'week' => Carbon::now()->utc()->startOfWeek(Carbon::MONDAY),
            'month' => Carbon::now()->utc()->startOfMonth(),
            default => null,
        };

        $r = $g->wrap('longshot_matches.result');
        $back = $g->wrap('longshot_matches.back_stake');
        $lay = $g->wrap('longshot_matches.lay_stake');
        $status = $g->wrap('longshot_bets.status');

        $bets = $this->db->table('longshot_bets')
            ->where('longshot_bets.status', '!=', Bet::OPEN)
            ->when($since, fn ($q) => $q->where('longshot_bets.settled_at', '>=', $since))
            ->select('longshot_bets.user_id')
            ->selectRaw($g->wrap('longshot_bets.payout').' - '.$g->wrap('longshot_bets.stake').' AS gain')
            ->selectRaw($g->wrap('longshot_bets.stake').' AS cost')
            ->selectRaw("CASE WHEN $status = ? THEN 1 ELSE 0 END AS won", [Bet::WON])
            ->selectRaw("CASE WHEN $status IN (?, ?) THEN 1 ELSE 0 END AS decided", [Bet::WON, Bet::LOST]);

        $matches = fn (string $side) => $this->db->table('longshot_matches')
            ->join('longshot_offers', 'longshot_offers.id', '=', 'longshot_matches.offer_id')
            ->whereNotNull('longshot_matches.result')
            ->when($since, fn ($q) => $q->where('longshot_matches.settled_at', '>=', $since))
            ->selectRaw(($side === 'back' ? $g->wrap('longshot_offers.user_id') : $g->wrap('longshot_matches.user_id')).' AS user_id')
            ->selectRaw($side === 'back'
                ? "CASE WHEN $r = ? THEN $lay WHEN $r = ? THEN 0 - $back ELSE 0 END AS gain"
                : "CASE WHEN $r = ? THEN $back WHEN $r = ? THEN 0 - $lay ELSE 0 END AS gain",
                $side === 'back' ? [OfferMatch::BACK, OfferMatch::LAY] : [OfferMatch::LAY, OfferMatch::BACK])
            ->selectRaw(($side === 'back' ? $back : $lay).' AS cost')
            ->selectRaw("CASE WHEN $r = ? THEN 1 ELSE 0 END AS won", [$side === 'back' ? OfferMatch::BACK : OfferMatch::LAY])
            ->selectRaw("CASE WHEN $r IN (?, ?) THEN 1 ELSE 0 END AS decided", [OfferMatch::BACK, OfferMatch::LAY]);

        $all = $bets->unionAll($matches('back'))->unionAll($matches('lay'));

        $results = $this->db->query()->fromSub($all, 'r')
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('SUM(gain) AS profit, SUM(cost) AS staked, COUNT(*) AS settled, SUM(won) AS won, SUM(decided) AS decided');

        return $this->visible($this->db->query()->fromSub($results, 'p'), 'p.user_id', $actor);
    }

    private function balanceQuery(User $actor): Builder
    {
        $wallets = $this->db->table('longshot_wallets')->select('user_id', 'balance');

        return $this->visible($this->db->query()->fromSub($wallets, 'p'), 'p.user_id', $actor);
    }

    /** Leave off private wallets and members the viewer may not see. */
    private function visible(Builder $query, string $column, User $actor): Builder
    {
        $private = $this->db->table('longshot_wallets')->where('private', true)->where('user_id', '!=', $actor->id)->select('user_id');

        return $query
            ->whereNotIn($column, $private)
            ->whereIn($column, User::query()->whereVisibleTo($actor)->select('id')->getQuery())
            ->select('p.*');
    }
}
