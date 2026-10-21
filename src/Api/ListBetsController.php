<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Config;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Presenter;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/longshot/bets?user=&status=open|settled&offset=
 *
 * 🚨 A member's own bets, all of them. Somebody else's: only when the forum
 * shows bet history, the member has not made theirs private, the actor may
 * see the member at all, and only SETTLED bets — an open bet is the member's
 * business until the game is over. Staff who manage the book see everything.
 */
class ListBetsController implements RequestHandlerInterface
{
    use Concerns;

    public const PAGE = 20;

    public function __construct(private Presenter $presenter, private Config $config, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.view');

        $query = $request->getQueryParams();
        $userId = $this->int($query['user'] ?? null) ?? $actor->id;
        $status = ($query['status'] ?? 'open') === 'settled' ? 'settled' : 'open';
        $offset = max(0, min(100000, (int) ($query['offset'] ?? 0)));

        $own = ! $actor->isGuest() && $userId === $actor->id;
        $staff = $actor->can('longshot.manage');

        if (! $own) {
            $user = User::query()->whereVisibleTo($actor)->find($userId);
            $private = (bool) $this->db->table('longshot_wallets')->where('user_id', $userId)->value('private');

            if (! $user || (! $staff && (! $this->config->publicHistory() || $private))) {
                return new JsonResponse(['data' => [], 'meta' => ['more' => false, 'hidden' => true]]);
            }

            if (! $staff) {
                $status = 'settled';
            }
        }

        $bets = Bet::query()
            ->where('user_id', $userId)
            ->when($status === 'open', fn ($q) => $q->where('status', Bet::OPEN), fn ($q) => $q->where('status', '!=', Bet::OPEN))
            ->with('legs.selection.market.event')
            ->orderByDesc($status === 'open' ? 'id' : 'settled_at')->orderByDesc('id')
            ->offset($offset)->limit(self::PAGE + 1)
            ->get();

        $more = $bets->count() > self::PAGE;

        return new JsonResponse([
            'data' => $this->presenter->bets($bets->take(self::PAGE)),
            'meta' => ['more' => $more, 'status' => $status],
        ]);
    }
}
