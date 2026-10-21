<?php

namespace ErnestDefoe\Longshot\Api;

use Carbon\Carbon;
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
 * GET /api/longshot — the board: events taking bets (or under way, until
 * they are settled), or recent results with ?results=1. Filter by ?league=.
 * The actor's wallet rides along, so the page is one request.
 *
 * A fixed number of queries whatever the page size: events, their markets,
 * their selections, open-offer counts, the league list and the wallet.
 */
class BoardController implements RequestHandlerInterface
{
    use Concerns;

    public const PAGE = 50;

    public function __construct(private Presenter $presenter, private Wallets $wallets)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('longshot.view');

        $query = $request->getQueryParams();
        $results = ! empty($query['results']);
        $league = is_string($query['league'] ?? null) ? mb_substr($query['league'], 0, 60) : '';
        $offset = max(0, min(10000, (int) ($query['offset'] ?? 0)));

        $base = fn () => Event::query()
            ->when($results,
                fn (Builder $q) => $q->whereIn('status', [Event::SETTLED, Event::VOID])->where('settled_at', '>=', Carbon::now()->subDays(14)),
                fn (Builder $q) => $q->where('status', Event::OPEN));

        $events = $base()
            ->when($league !== '', fn (Builder $q) => $q->where('league', $league))
            ->with('markets.selections')
            ->withCount(['offers' => fn ($q) => $q->where('status', Offer::OPEN)->whereNull('target_user_id')])
            ->orderBy('starts_at', $results ? 'desc' : 'asc')->orderBy('id')
            ->offset($offset)->limit(self::PAGE + 1)
            ->get();

        $more = $events->count() > self::PAGE;
        $events = $events->take(self::PAGE);

        return new JsonResponse([
            'data' => $events->map(fn (Event $e) => $this->presenter->event($e) + ['offers' => (int) $e->getAttribute('offers_count')])->values()->all(),
            'meta' => [
                'leagues' => $base()->where('league', '!=', '')->distinct()->orderBy('league')->pluck('league')->all(),
                'more' => $more,
                'wallet' => $this->wallets->summary($actor),
            ],
        ]);
    }
}
