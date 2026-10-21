<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Placer;
use ErnestDefoe\Longshot\Presenter;
use ErnestDefoe\Longshot\Rejected;
use ErnestDefoe\Longshot\Wallets;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/longshot/bets — place a slip.
 *
 * { kind: "single" | "parlay", stake (parlay), nonce,
 *   legs: [{ selection, odds, line, stake (single) }] }
 *
 * `odds` and `line` are what the member saw; they are checked, never used.
 * `nonce` names the slip: the same slip sent twice (a double tap, a retry
 * after a dropped connection) is placed once.
 */
class PlaceBetsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Placer $placer,
        private Presenter $presenter,
        private Wallets $wallets,
        private TranslatorInterface $translator,
        private Cache $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('longshot.bet');

        $body = $this->body($request);
        $kind = ($body['kind'] ?? 'single') === Bet::PARLAY ? Bet::PARLAY : Bet::SINGLE;
        $legs = [];

        foreach (array_slice((array) ($body['legs'] ?? []), 0, 21) as $leg) {
            $selection = $this->int($leg['selection'] ?? null);
            $odds = $this->int($leg['odds'] ?? null);
            $line = $leg['line'] ?? null;

            if (! $selection || $odds === null || ($line !== null && ! is_string($line) && ! is_int($line) && ! is_float($line))) {
                throw new ValidationException(['legs' => $this->translator->trans('ernestdefoe-longshot.api.rejected.gone')]);
            }

            $legs[] = ['selection' => $selection, 'odds' => $odds, 'line' => $line === null ? null : (string) $line, 'stake' => (int) $this->int($leg['stake'] ?? 0)];
        }

        $nonce = is_string($body['nonce'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $body['nonce']) ? $body['nonce'] : null;
        $key = $nonce ? 'longshot.slip.'.$actor->id.'.'.$nonce : null;

        if ($key && ! $this->cache->add($key, 1, 600)) {
            throw new ValidationException(['legs' => $this->translator->trans('ernestdefoe-longshot.api.rejected.duplicate_slip')]);
        }

        try {
            $bets = $this->placer->place($actor, $kind, $legs, (int) $this->int($body['stake'] ?? 0));
        } catch (Rejected $e) {
            // Refused: the same slip may be sent again once it is fixed.
            $key && $this->cache->forget($key);

            return $this->rejected($e, $this->translator);
        } catch (\Throwable $e) {
            $key && $this->cache->forget($key);

            throw $e;
        }

        $loaded = Bet::query()->whereIn('id', array_map(fn ($b) => $b->id, $bets))->with('legs.selection.market.event')->orderBy('id')->get();

        return new JsonResponse([
            'data' => $this->presenter->bets($loaded),
            'meta' => ['wallet' => $this->wallets->summary($actor)],
        ], 201);
    }
}
