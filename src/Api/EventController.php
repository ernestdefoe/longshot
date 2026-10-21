<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Presenter;
use ErnestDefoe\Longshot\Wallets;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/longshot/events/{id} — one event, every market, and its exchange:
 * open offers anyone may take, plus the actor's own and any challenge to them.
 */
class EventController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Wallets $wallets)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.view');

        $event = Event::query()->with('markets.selections')->findOrFail($this->routeId($request));

        $offers = Offer::query()
            ->where('event_id', $event->id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', Offer::OPEN)->whereNull('target_user_id'))
                ->when(! $actor->isGuest(), fn (Builder $q) => $q
                    ->orWhere('user_id', $actor->id)
                    ->orWhere('target_user_id', $actor->id)
                    ->orWhereHas('matches', fn ($q) => $q->where('user_id', $actor->id))))
            ->with(['user', 'target', 'matches.user', 'selection.market.event'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $visible = $this->visibleIds($offers->pluck('user_id')->all(), $actor);
        $offers = $offers->filter(fn (Offer $o) => isset($visible[$o->user_id]) || $o->user_id === $actor->id)->values();

        return new JsonResponse([
            'data' => $this->presenter->event($event),
            'meta' => [
                'offers' => $this->presenter->offers($offers, $actor),
                'wallet' => $this->wallets->summary($actor),
            ],
        ]);
    }
}
