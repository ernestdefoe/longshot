<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Bank;
use ErnestDefoe\Longshot\Wallets;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET   /api/longshot/wallet — the actor's wallet and their latest ledger lines.
 * PATCH /api/longshot/wallet — { private: bool }: stay off leaderboards and
 *                              keep bet history to yourself.
 *
 * Only ever the actor's own: there is no id to change.
 */
class WalletController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Wallets $wallets, private Bank $bank, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('longshot.view');

        if ($request->getMethod() === 'PATCH') {
            // Privacy is for members who play; opening a wallet pays the
            // starting balance, which a viewer who may not bet never gets.
            // can(), not hasPermission(): a suspended member is refused by policy.
            if (! $actor->can('longshot.bet') && ! $actor->can('longshot.exchange')) {
                throw new \Flarum\User\Exception\PermissionDeniedException();
            }

            $this->bank->open($actor);
            $this->db->table('longshot_wallets')->where('user_id', $actor->id)
                ->update(['private' => (bool) ($this->body($request)['private'] ?? false)]);
        }

        $offset = max(0, min(100000, (int) ($request->getQueryParams()['offset'] ?? 0)));
        $rows = $this->db->table('longshot_ledger')->where('user_id', $actor->id)
            ->orderByDesc('id')->offset($offset)->limit(51)
            ->get(['id', 'amount', 'kind', 'ref', 'created_at']);

        return new JsonResponse([
            'data' => $this->wallets->summary($actor),
            'meta' => [
                'ledger' => $rows->take(50)->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'amount' => (int) $r->amount,
                    'kind' => $r->kind,
                    'ref' => $r->ref,
                    'createdAt' => \Carbon\Carbon::parse($r->created_at)->toIso8601String(),
                ])->values()->all(),
                'more' => $rows->count() > 50,
            ],
        ]);
    }
}
