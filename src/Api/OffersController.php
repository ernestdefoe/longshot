<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Exchange;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Presenter;
use ErnestDefoe\Longshot\Rejected;
use ErnestDefoe\Longshot\Wallets;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The exchange.
 *
 * GET    /api/longshot/offers?scope=open|mine|challenges — offers to take, the
 *        actor's own (made or laid), or challenges sent to them.
 * POST   /api/longshot/offers { selection, odds, line, stake, target? }
 * POST   /api/longshot/offers/{id}/matches { back }
 * DELETE /api/longshot/offers/{id}
 */
class OffersController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Exchange $exchange,
        private Presenter $presenter,
        private Wallets $wallets,
        private TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.view');

        $id = $this->routeId($request);
        $method = $request->getMethod();

        if ($method === 'GET') {
            return $this->index($request, $actor);
        }

        $actor->assertRegistered();
        $body = $this->body($request);

        try {
            if ($method === 'DELETE') {
                $this->exchange->cancel($actor, $id);

                return new EmptyResponse(204);
            }

            if ($id) {
                $match = $this->exchange->match($actor, $id, (int) $this->int($body['back'] ?? null));

                return $this->one($match->offer_id, $actor, 201);
            }

            // A challenge names its member by id or by username.
            $target = null;
            $who = $body['target'] ?? null;
            if ($who !== null && $who !== '') {
                $id = $this->int($who);
                $target = $id !== null
                    ? User::query()->whereVisibleTo($actor)->find($id)
                    : (is_string($who) ? User::query()->whereVisibleTo($actor)->where('username', mb_substr(ltrim(trim($who), '@'), 0, 100))->first() : null);

                if (! $target) {
                    throw new Rejected('target');
                }
            }

            $line = $body['line'] ?? null;
            $offer = $this->exchange->offer(
                $actor,
                (int) $this->int($body['selection'] ?? null),
                (int) $this->int($body['odds'] ?? null),
                is_scalar($line) ? (string) $line : null,
                (int) $this->int($body['stake'] ?? null),
                $target
            );

            return $this->one($offer->id, $actor, 201);
        } catch (Rejected $e) {
            return $this->rejected($e, $this->translator);
        }
    }

    private function index(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $scope = $request->getQueryParams()['scope'] ?? 'open';
        $scope = in_array($scope, ['open', 'mine', 'challenges'], true) ? $scope : 'open';
        $offset = max(0, min(10000, (int) ($request->getQueryParams()['offset'] ?? 0)));

        if ($actor->isGuest() && $scope !== 'open') {
            $scope = 'open';
        }

        $offers = Offer::query()
            ->when($scope === 'open', fn (Builder $q) => $q->where('status', Offer::OPEN)->whereNull('target_user_id'))
            ->when($scope === 'mine', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('user_id', $actor->id)
                ->orWhereHas('matches', fn ($q) => $q->where('user_id', $actor->id))))
            ->when($scope === 'challenges', fn (Builder $q) => $q->where('target_user_id', $actor->id))
            ->with(['user', 'target', 'matches.user', 'selection.market.event'])
            ->orderByDesc('id')
            ->offset($offset)->limit(31)
            ->get();

        $more = $offers->count() > 30;
        $offers = $offers->take(30);
        $visible = $this->visibleIds($offers->pluck('user_id')->all(), $actor);
        $offers = $offers->filter(fn (Offer $o) => isset($visible[$o->user_id]) || $o->user_id === $actor->id)->values();

        return new JsonResponse([
            'data' => $this->presenter->offers($offers, $actor),
            'meta' => ['more' => $more, 'wallet' => $this->wallets->summary($actor)],
        ]);
    }

    private function one(int $id, User $actor, int $status): ResponseInterface
    {
        $offer = Offer::query()->with(['user', 'target', 'matches.user', 'selection.market.event'])->findOrFail($id);

        return new JsonResponse([
            'data' => $this->presenter->offers(collect([$offer]), $actor)[0],
            'meta' => ['wallet' => $this->wallets->summary($actor)],
        ], $status);
    }
}
