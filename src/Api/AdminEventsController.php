<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\EventSaver;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Presenter;
use ErnestDefoe\Longshot\Settlement;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Staff's book. Everything here needs `longshot.manage`.
 *
 * GET    /api/longshot/admin/events?status=open|finished&q=
 * POST   /api/longshot/admin/events                  create
 * PATCH  /api/longshot/admin/events/{id}             edit
 * DELETE /api/longshot/admin/events/{id}             only if nothing was ever placed on it
 * POST   /api/longshot/admin/events/{id}/score       { home, away }
 * POST   /api/longshot/admin/events/{id}/void
 * POST   /api/longshot/admin/events/{id}/hide        off the board for good (nothing placed on it)
 * POST   /api/longshot/admin/markets/{id}/resolve    { results: { selectionId: win|lose|void } }
 * POST   /api/longshot/admin/markets/{id}/void
 */
class AdminEventsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private EventSaver $saver,
        private Settlement $settlement,
        private Presenter $presenter,
        private TranslatorInterface $translator,
        private ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.manage');

        $id = $this->routeId($request);
        $action = (string) ($request->getAttribute('routeName') ?? '');
        $action = substr($action, strrpos($action, '.') + 1);
        $method = $request->getMethod();
        $body = $this->body($request);

        if (str_starts_with((string) $request->getAttribute('routeName'), 'longshot.admin.markets')) {
            $market = Market::query()->findOrFail($id);

            try {
                if ($action === 'void') {
                    $this->settlement->voidMarket($market);
                } else {
                    $results = [];
                    foreach ((array) ($body['results'] ?? []) as $selection => $result) {
                        $results[(int) $selection] = (string) $result;
                    }
                    $this->settlement->resolve($market, $results);
                }
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException(['results' => $this->translator->trans('ernestdefoe-longshot.api.admin.results')]);
            }

            return $this->show($market->event_id);
        }

        if ($method === 'GET') {
            return $this->index($request);
        }

        if ($method === 'POST' && ! $id) {
            return $this->show($this->saver->save($actor, null, $body)->id, 201);
        }

        $event = Event::query()->findOrFail($id);

        if ($method === 'DELETE') {
            if ($this->saver->used($event)) {
                throw new ValidationException(['event' => $this->translator->trans('ernestdefoe-longshot.api.admin.event_used')]);
            }

            // Explicitly, not by cascade: SQLite only cascades with foreign keys on.
            $this->db->transaction(function () use ($event) {
                $markets = Market::query()->where('event_id', $event->id)->pluck('id')->all();
                $this->db->table('longshot_selections')->whereIn('market_id', $markets)->delete();
                Market::query()->whereIn('id', $markets)->delete();
                $event->delete();
            });

            return new EmptyResponse(204);
        }

        if ($action === 'score') {
            $home = $this->int($body['home'] ?? null);
            $away = $this->int($body['away'] ?? null);

            if ($home === null || $away === null || $home < 0 || $away < 0 || $home > 9999 || $away > 9999 || ! $event->isMatchup()) {
                throw new ValidationException(['score' => $this->translator->trans('ernestdefoe-longshot.api.admin.score')]);
            }

            if ($event->status !== Event::OPEN) {
                throw new ValidationException(['score' => $this->translator->trans('ernestdefoe-longshot.api.admin.finished')]);
            }

            $this->settlement->score($event, $home, $away);

            return $this->show($event->id);
        }

        if ($action === 'void') {
            $this->settlement->voidEvent($event);

            return $this->show($event->id);
        }

        // Off the board, and never brought back by a sync. Only while nothing
        // was placed on it: a game with bets is voided instead.
        if ($action === 'hide') {
            if ($this->saver->used($event) || $event->status !== Event::OPEN) {
                throw new ValidationException(['event' => $this->translator->trans('ernestdefoe-longshot.api.admin.event_used')]);
            }

            $event->status = Event::HIDDEN;
            $event->save();

            return $this->show($event->id);
        }

        return $this->show($this->saver->save($actor, $event, $body)->id);
    }

    private function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $finished = ($query['status'] ?? 'open') === 'finished';
        $search = is_string($query['q'] ?? null) ? trim(mb_substr($query['q'], 0, 60)) : '';
        $offset = max(0, min(100000, (int) ($query['offset'] ?? 0)));

        $events = Event::query()
            ->when($finished, fn (Builder $q) => $q->whereIn('status', [Event::SETTLED, Event::VOID, Event::HIDDEN]), fn (Builder $q) => $q->where('status', Event::OPEN))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('home_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('away_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('league', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->with('markets.selections')
            ->withCount(['offers', 'legs'])
            ->orderBy('starts_at', $finished ? 'desc' : 'asc')->orderBy('id')
            ->offset($offset)->limit(51)
            ->get();

        return new JsonResponse([
            'data' => $events->take(50)->map(fn (Event $e) => $this->presenter->event($e) + [
                'bets' => (int) $e->getAttribute('legs_count'),
                'offers' => (int) $e->getAttribute('offers_count'),
            ])->values()->all(),
            'meta' => ['more' => $events->count() > 50],
        ]);
    }

    private function show(int $id, int $status = 200): ResponseInterface
    {
        $event = Event::query()->with('markets.selections')->withCount(['offers', 'legs'])->findOrFail($id);

        return new JsonResponse(['data' => $this->presenter->event($event) + [
            'bets' => (int) $event->getAttribute('legs_count'),
            'offers' => (int) $event->getAttribute('offers_count'),
        ]], $status);
    }
}
