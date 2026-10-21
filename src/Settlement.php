<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Leg;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Model\OfferMatch;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Notification\BetSettledBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Results and payouts.
 *
 * 🚨 Exact, and safe to run twice. Each step is its own transaction that
 * starts by locking its row and checking it is still undecided: an event is
 * scored once, a market graded once, a leg's result written only where it is
 * still null, a bet paid only while it is open. The payout itself carries a
 * unique ledger ref. So a run that dies halfway is finished by the next one
 * (see `sweep()`), and two runs at once pay nothing twice.
 *
 * Lock order is always the bet, match or offer row first, then wallets in
 * ascending member id, so two settlements cannot deadlock each other or a
 * member placing a bet (which locks only their own wallet).
 */
class Settlement
{
    use Atomic;

    public function __construct(
        private ConnectionInterface $db,
        private Bank $bank,
        private Config $config,
        private PicksBridge $picks,
        private NotificationSyncer $notifications,
        private Queue $queue,
        private \Psr\Log\LoggerInterface $log
    ) {
    }

    /** Record a final score and grade every scored market on the event. */
    public function score(Event $event, int $home, int $away): void
    {
        if ($home < 0 || $away < 0 || $home > 9999 || $away > 9999) {
            throw new InvalidArgumentException('A score is a whole number from 0 to 9999.');
        }

        if (! $event->isMatchup()) {
            throw new InvalidArgumentException('Only an event between two sides has a score.');
        }

        $this->atomic(function () use ($event, $home, $away) {
            $fresh = Event::query()->whereKey($event->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== Event::OPEN) {
                return;
            }

            $fresh->home_score = $home;
            $fresh->away_score = $away;
            $fresh->status = Event::SETTLED;
            $fresh->settled_at = Carbon::now();
            $fresh->save();
        });

        $this->closeOffersOf($event->id);
        $this->later($this->gradeScored($event->id));
    }

    /**
     * Grade every unfinished scored market of a scored event, all at once:
     * one update per result, not one per selection or per market.
     *
     * @return list<int> the markets graded
     */
    public function gradeScored(int $eventId): array
    {
        return $this->atomic(function () use ($eventId) {
            $event = Event::query()->find($eventId);

            if (! $event || $event->status !== Event::SETTLED || $event->home_score === null || $event->away_score === null) {
                return [];
            }

            $markets = Market::query()->where('event_id', $eventId)
                ->whereIn('type', [Market::MONEYLINE, Market::SPREAD, Market::TOTAL])
                ->whereNotIn('status', [Market::SETTLED, Market::VOID])
                ->lockForUpdate()->get();

            if ($markets->isEmpty()) {
                return [];
            }

            $selections = Selection::query()->whereIn('market_id', $markets->modelKeys())->get()->groupBy('market_id');
            $by = [];

            foreach ($markets as $market) {
                $group = $selections->get($market->id, collect());
                $hasDraw = $group->contains('key', 'draw');

                foreach ($group as $selection) {
                    $by[(string) Grader::grade($market->type, $selection->key, $market->line, $event->home_score, $event->away_score, $hasDraw)][] = $selection->id;
                }
            }

            foreach ($by as $result => $ids) {
                Selection::query()->whereIn('id', $ids)->update(['result' => $result === '' ? null : $result]);
            }

            Market::query()->whereIn('id', $markets->modelKeys())->update(['status' => Market::SETTLED, 'settled_at' => Carbon::now()]);

            return $markets->modelKeys();
        });
    }

    /**
     * Name the results of a custom market: selection id => win | lose | void.
     * Every selection needs one, and at least one must win unless all are void.
     *
     * @param array<int, string> $results
     */
    public function resolve(Market $market, array $results): void
    {
        if ($market->isScored()) {
            throw new InvalidArgumentException('A scored market is graded from the event\'s score.');
        }

        $resolved = $this->atomic(function () use ($market, $results) {
            $fresh = Market::query()->whereKey($market->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->isFinished()) {
                return false;
            }

            $selections = Selection::query()->where('market_id', $market->id)->get();

            foreach ($selections as $selection) {
                $result = $results[$selection->id] ?? null;

                if (! in_array($result, [Grader::WIN, Grader::LOSE, Grader::VOID], true)) {
                    throw new InvalidArgumentException('Every selection needs a result: win, lose or void.');
                }
            }

            if (! $selections->contains(fn ($s) => $results[$s->id] === Grader::WIN) && $selections->contains(fn ($s) => $results[$s->id] !== Grader::VOID)) {
                throw new InvalidArgumentException('At least one selection must win, unless every one is void.');
            }

            foreach ($selections->groupBy(fn ($s) => $results[$s->id]) as $result => $group) {
                Selection::query()->whereIn('id', $group->pluck('id')->all())->update(['result' => $result]);
            }

            $fresh->status = Market::SETTLED;
            $fresh->settled_at = Carbon::now();
            $fresh->save();

            return true;
        });

        if ($resolved) {
            $this->closeOffersOf($market->event_id, $market->id);
            $this->later([$market->id]);
        }
    }

    /** Void an event: every market not yet settled is voided and its stakes returned. */
    public function voidEvent(Event $event): void
    {
        // The event and its markets in one transaction: a run that dies in
        // between cannot leave a void event with open markets holding stakes.
        $voided = $this->atomic(function () use ($event) {
            $fresh = Event::query()->whereKey($event->id)->lockForUpdate()->first();

            // Scored meanwhile (an admin, a sync): its markets are graded, not voided.
            if (! $fresh || $fresh->status === Event::SETTLED) {
                return [];
            }

            if ($fresh->status === Event::OPEN) {
                $fresh->status = Event::VOID;
                $fresh->settled_at = Carbon::now();
                $fresh->save();
            }

            return $this->voidMarkets(Market::query()->where('event_id', $event->id)->pluck('id')->all());
        });

        $this->closeOffersOf($event->id);
        $this->later($voided);
    }

    public function voidMarket(Market $market): void
    {
        if ($voided = $this->voidMarkets([$market->id])) {
            $this->closeOffersOf($market->event_id, $market->id);
            $this->later($voided);
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int> the markets voided (those not already finished)
     */
    public function voidMarkets(array $ids): array
    {
        return $this->atomic(function () use ($ids) {
            $open = Market::query()->whereIn('id', $ids)->whereNotIn('status', [Market::SETTLED, Market::VOID])->lockForUpdate()->pluck('id')->all();

            if ($open) {
                Selection::query()->whereIn('market_id', $open)->update(['result' => Grader::VOID]);
                Market::query()->whereIn('id', $open)->update(['status' => Market::VOID, 'settled_at' => Carbon::now()]);
            }

            return array_map('intval', $open);
        });
    }

    /**
     * Payouts for finished markets go to the queue: one transaction per bet,
     * so a big game is paid by a worker rather than inside a staff member's
     * request. Without a worker (the sync queue) they are paid right away, and
     * the minutely sweep finishes any job that never ran.
     *
     * @param list<int> $marketIds
     */
    private function later(array $marketIds): void
    {
        if ($marketIds) {
            $this->queue->push(new Jobs\PayMarkets($marketIds));
        }
    }

    /**
     * Everything that follows markets' results: their legs graded, every bet
     * that is now complete paid, and the exchange's matches paid. A fixed
     * number of queries for the grading; one transaction per bet or match paid.
     *
     * @param list<int> $marketIds
     */
    public function payMarkets(array $marketIds): void
    {
        $markets = Market::query()->whereIn('id', $marketIds)->whereIn('status', [Market::SETTLED, Market::VOID])->get()->keyBy('id');

        if ($markets->isEmpty()) {
            return;
        }

        $ids = $markets->keys()->all();
        $events = Event::query()->whereIn('id', $markets->pluck('event_id')->unique()->all())->get()->keyBy('id');
        $selections = Selection::query()->whereIn('market_id', $ids)->get()->keyBy('id');
        $draws = $selections->where('key', 'draw')->pluck('market_id')->flip();

        $result = function (int $marketId, int $selectionId, ?string $line) use ($markets, $events, $selections, $draws): ?string {
            $market = $markets->get($marketId);
            $selection = $selections->get($selectionId);

            if (! $market || ! $selection) {
                return null;
            }

            if ($market->status === Market::VOID) {
                return Grader::VOID;
            }

            $event = $events->get($market->event_id);

            // A scored bet is graded at the line it was TAKEN at, not today's.
            return $market->isScored() && $event && $event->home_score !== null && $event->away_score !== null
                ? Grader::grade($market->type, $selection->key, $line, $event->home_score, $event->away_score, $draws->has($marketId))
                : $selection->result;
        };

        // Legs: one update per result per chunk, and only where still null.
        Leg::query()->whereIn('market_id', $ids)->whereNull('result')
            ->chunkById(500, function ($legs) use ($result) {
                $by = [];
                foreach ($legs as $leg) {
                    if ($r = $result($leg->market_id, $leg->selection_id, $leg->line)) {
                        $by[$r][] = $leg->id;
                    }
                }

                foreach ($by as $r => $legIds) {
                    Leg::query()->whereIn('id', $legIds)->whereNull('result')->update(['result' => $r]);
                }
            });

        // Every open bet with a leg here: payBet pays those now complete.
        $bets = Leg::query()->whereIn('longshot_bet_legs.market_id', $ids)
            ->join('longshot_bets', 'longshot_bets.id', '=', 'longshot_bet_legs.bet_id')
            ->where('longshot_bets.status', Bet::OPEN)
            ->distinct()->pluck('longshot_bet_legs.bet_id');

        foreach ($bets as $betId) {
            $this->payBet((int) $betId);
        }

        $matches = OfferMatch::query()
            ->join('longshot_offers', 'longshot_offers.id', '=', 'longshot_matches.offer_id')
            ->whereIn('longshot_offers.market_id', $ids)
            ->whereNull('longshot_matches.result')
            ->pluck('longshot_matches.id');

        foreach ($matches as $id) {
            $this->payMatch((int) $id, $result);
        }

        $this->finishOffers($ids);
    }

    /** Pay one bet if all its legs are graded. Safe to call any number of times. */
    public function payBet(int $betId): void
    {
        $paid = $this->atomic(function () use ($betId) {
            $bet = Bet::query()->whereKey($betId)->where('status', Bet::OPEN)->lockForUpdate()->first();

            if (! $bet) {
                return null;
            }

            $legs = Leg::query()->where('bet_id', $bet->id)->orderBy('id')->get();

            if ($legs->isEmpty() || $legs->contains(fn ($leg) => $leg->result === null)) {
                return null;
            }

            $winners = $legs->where('result', Grader::WIN)->pluck('odds')->map(fn ($o) => (int) $o)->values()->all();

            if ($legs->contains('result', Grader::LOSE)) {
                [$status, $payout] = [Bet::LOST, 0];
            } elseif (! $winners) {
                // Nothing won and nothing lost: the stake comes back.
                $status = $legs->every(fn ($leg) => $leg->result === Grader::VOID) ? Bet::VOID : Bet::PUSH;
                $payout = $bet->stake;
            } else {
                [$status, $payout] = [Bet::WON, min(Odds::parlay($bet->stake, $winners), $bet->potential)];
            }

            if ($payout > 0 && ($member = $this->user($bet->user_id))) {
                $this->bank->move($member, $payout, $status === Bet::WON ? 'payout' : 'refund', 'bet:'.$bet->id.':payout');
            }

            $bet->status = $status;
            $bet->payout = $payout;
            $bet->settled_at = Carbon::now();
            $bet->save();

            return $bet;
        });

        if ($paid) {
            $this->notify($paid);
        }
    }

    /**
     * @param callable(int, int, ?string): ?string $result
     */
    private function payMatch(int $matchId, callable $result): void
    {
        $this->atomic(function () use ($matchId, $result) {
            $match = OfferMatch::query()->whereKey($matchId)->whereNull('result')->lockForUpdate()->first();

            if (! $match) {
                return;
            }

            $offer = Offer::query()->findOrFail($match->offer_id);
            $selection = $result($offer->market_id, $offer->selection_id, $offer->line);

            if ($selection === null) {
                return;
            }

            $outcome = match ($selection) {
                Grader::WIN => OfferMatch::BACK,
                Grader::LOSE => OfferMatch::LAY,
                Grader::PUSH => OfferMatch::PUSH,
                default => OfferMatch::VOID,
            };

            $pot = $match->back_stake + $match->lay_stake;
            $backer = $this->user($offer->user_id);
            $layer = $this->user($match->user_id);

            // Wallets in ascending id order, the house lock order.
            $moves = match ($outcome) {
                OfferMatch::BACK => [[$backer, $pot, 'payout', 'match:'.$match->id.':back']],
                OfferMatch::LAY => [[$layer, $pot, 'payout', 'match:'.$match->id.':lay']],
                default => [
                    [$backer, $match->back_stake, 'refund', 'match:'.$match->id.':back'],
                    [$layer, $match->lay_stake, 'refund', 'match:'.$match->id.':lay'],
                ],
            };

            // A side whose member is gone is skipped; the other is still paid.
            $moves = array_values(array_filter($moves, fn ($move) => $move[0] !== null));
            usort($moves, fn ($a, $b) => $a[0]->id <=> $b[0]->id);

            foreach ($moves as [$user, $amount, $kind, $ref]) {
                $this->bank->move($user, $amount, $kind, $ref);
            }

            $match->result = $outcome;
            $match->settled_at = Carbon::now();
            $match->save();
        });
    }

    /**
     * Stop matching offers on an event (or one market of it) and give back
     * what nobody matched.
     */
    public function closeOffersOf(int $eventId, ?int $marketId = null): void
    {
        $ids = Offer::query()->where('event_id', $eventId)->where('status', Offer::OPEN)
            ->when($marketId, fn ($q) => $q->where('market_id', $marketId))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->closeOffer((int) $id);
        }
    }

    /** Close one offer, refunding its unmatched stake once. */
    public function closeOffer(int $offerId): ?Offer
    {
        return $this->atomic(function () use ($offerId) {
            $offer = Offer::query()->whereKey($offerId)->where('status', Offer::OPEN)->lockForUpdate()->first();

            if (! $offer) {
                return null;
            }

            if ($offer->remaining() > 0 && ($backer = $this->user($offer->user_id))) {
                $this->bank->move($backer, $offer->remaining(), 'refund', 'offer:'.$offer->id.':unmatched');
            }

            $offer->status = $offer->matched > 0 ? Offer::CLOSED : Offer::SETTLED;
            $offer->save();

            return $offer;
        });
    }

    /**
     * Offers whose every match is settled are finished.
     *
     * @param list<int> $marketIds
     */
    private function finishOffers(array $marketIds): void
    {
        Offer::query()->whereIn('market_id', $marketIds)->where('status', Offer::CLOSED)
            ->whereNotExists(fn (Builder $q) => $q->from('longshot_matches')
                ->whereColumn('longshot_matches.offer_id', 'longshot_offers.id')
                ->whereNull('longshot_matches.result'))
            ->update(['status' => Offer::SETTLED]);
    }

    /**
     * The minutely run: results from Picks, offers closed at kickoff, stale
     * games voided, and anything a crashed run left half done, finished.
     *
     * @return array<string, int>
     */
    public function sweep(): array
    {
        $done = ['scored' => 0, 'closed' => 0, 'voided' => 0, 'recovered' => 0, 'failed' => 0];

        // 🚨 Each item on its own: one bad row (a score Picks got wrong, a
        // member removed behind our back) is logged and skipped, never left
        // to stop every payout behind it, every minute, for good.
        $safely = function (string $what, callable $work) use (&$done): void {
            try {
                $work();
            } catch (\Throwable $e) {
                $done['failed']++;
                $this->log->error('[longshot] '.$what.' failed: '.$e->getMessage());
            }
        };

        $finals = [];
        $safely('reading results from Picks', function () use (&$finals) {
            $finals = $this->picks->refresh();
        });

        foreach ($finals as $final) {
            $safely('scoring event '.$final['event'], function () use ($final, &$done) {
                if ($event = Event::query()->find($final['event'])) {
                    $this->score($event, $final['home'], $final['away']);
                    $done['scored']++;
                }
            });
        }

        // Offers on events that have started: no more matching, unmatched back.
        $started = Offer::query()->where('longshot_offers.status', Offer::OPEN)
            ->join('longshot_events', 'longshot_events.id', '=', 'longshot_offers.event_id')
            ->where(fn ($q) => $q->where('longshot_events.starts_at', '<=', Carbon::now())->orWhere('longshot_events.status', '!=', Event::OPEN))
            ->orderBy('longshot_offers.id')->limit(500)->pluck('longshot_offers.id');

        foreach ($started as $id) {
            $safely('closing offer '.$id, function () use ($id, &$done) {
                $done['closed'] += (int) (bool) $this->closeOffer((int) $id);
            });
        }

        // Games no feed ever finished (postponed, cancelled, a feed gone
        // quiet): voided after the configured wait. Staff's own events wait for staff.
        if (($days = $this->config->autoVoidDays()) > 0) {
            foreach (Event::query()->where('status', Event::OPEN)->where('source', '!=', 'manual')->where('locked', false)
                ->where('starts_at', '<', Carbon::now()->subDays($days))->orderBy('id')->limit(100)->get() as $event) {
                $safely('voiding event '.$event->id, function () use ($event, &$done) {
                    $this->voidEvent($event);
                    $done['voided']++;
                });
            }
        }

        // Scored events whose markets a crashed run left ungraded, and void
        // events whose markets were not voided with them.
        $unfinished = fn (string $status) => Market::query()
            ->whereNotIn('longshot_markets.status', [Market::SETTLED, Market::VOID])
            ->join('longshot_events', 'longshot_events.id', '=', 'longshot_markets.event_id')
            ->where('longshot_events.status', $status);

        foreach ($unfinished(Event::SETTLED)->whereIn('longshot_markets.type', [Market::MONEYLINE, Market::SPREAD, Market::TOTAL])
            ->distinct()->orderBy('longshot_markets.event_id')->limit(100)->pluck('longshot_markets.event_id') as $eventId) {
            $safely('grading event '.$eventId, function () use ($eventId, &$done) {
                $this->payMarkets($this->gradeScored((int) $eventId));
                $done['recovered']++;
            });
        }

        $voids = $unfinished(Event::VOID)->orderBy('longshot_markets.id')->limit(200)->pluck('longshot_markets.id')->map(fn ($id) => (int) $id)->all();
        if ($voids) {
            $safely('voiding markets', function () use ($voids, &$done) {
                $this->payMarkets($this->voidMarkets($voids));
                $done['recovered'] += count($voids);
            });
        }

        // Finished markets with ungraded legs or unpaid matches: a queued
        // payout that never ran, or a run that died halfway. (Parlays still
        // waiting on another game have no ungraded leg here, so they do not
        // crowd the list.)
        $markets = Leg::query()
            ->join('longshot_markets', 'longshot_markets.id', '=', 'longshot_bet_legs.market_id')
            ->whereIn('longshot_markets.status', [Market::SETTLED, Market::VOID])
            ->whereNull('longshot_bet_legs.result')
            ->distinct()->orderBy('longshot_bet_legs.market_id')->limit(200)->pluck('longshot_bet_legs.market_id')
            ->merge(OfferMatch::query()->whereNull('longshot_matches.result')
                ->join('longshot_offers', 'longshot_offers.id', '=', 'longshot_matches.offer_id')
                ->join('longshot_markets', 'longshot_markets.id', '=', 'longshot_offers.market_id')
                ->whereIn('longshot_markets.status', [Market::SETTLED, Market::VOID])
                ->distinct()->orderBy('longshot_offers.market_id')->limit(200)->pluck('longshot_offers.market_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($markets as $marketId) {
            $safely('paying market '.$marketId, function () use ($marketId, &$done) {
                $this->payMarkets([$marketId]);
                $done['recovered']++;
            });
        }

        // Open bets whose every leg is graded: paid.
        foreach (Bet::query()->where('status', Bet::OPEN)
            ->whereNotExists(fn (Builder $q) => $q->from('longshot_bet_legs')
                ->whereColumn('longshot_bet_legs.bet_id', 'longshot_bets.id')
                ->whereNull('longshot_bet_legs.result'))
            ->orderBy('id')->limit(500)->pluck('id') as $id) {
            $safely('paying bet '.$id, function () use ($id, &$done) {
                $this->payBet((int) $id);
                $done['recovered']++;
            });
        }

        return $done;
    }

    /**
     * A member is being deleted. Their exchange business with OTHER members
     * is unwound first, while every row still exists: unmatched stakes go
     * back, and each unsettled match is voided so the other side gets their
     * stake back instead of losing it to the foreign key's cascade. A
     * challenge naming them is withdrawn rather than left open to everyone.
     */
    public function releaseMember(User $user): void
    {
        // Held for the whole release: the member cannot make an offer (or a
        // bet) halfway through and have it deleted with someone's lay on it.
        $this->atomic(fn () => $this->release($user));
    }

    private function release(User $user): void
    {
        $this->db->table('longshot_wallets')->where('user_id', $user->id)->lockForUpdate()->value('user_id');
        $this->db->table('users')->where('id', $user->id)->lockForUpdate()->value('id');

        $offers = Offer::query()->where('status', Offer::OPEN)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('target_user_id', $user->id))
            ->pluck('id');

        foreach ($offers as $id) {
            $this->closeOffer((int) $id);
        }

        $matches = OfferMatch::query()->whereNull('longshot_matches.result')
            ->join('longshot_offers', 'longshot_offers.id', '=', 'longshot_matches.offer_id')
            ->where(fn ($q) => $q->where('longshot_matches.user_id', $user->id)->orWhere('longshot_offers.user_id', $user->id))
            ->pluck('longshot_matches.id');

        foreach ($matches as $id) {
            $this->payMatch((int) $id, fn () => Grader::VOID);
        }

        // Then their own rows go, here rather than by the foreign keys'
        // cascade, which SQLite only performs with foreign keys switched on.
        $this->atomic(function () use ($user) {
            $bets = Bet::query()->where('user_id', $user->id)->pluck('id')->all();
            $offers = Offer::query()->where('user_id', $user->id)->pluck('id')->all();

            Leg::query()->whereIn('bet_id', $bets)->delete();
            Bet::query()->whereIn('id', $bets)->delete();
            OfferMatch::query()->whereIn('offer_id', $offers)->orWhere('user_id', $user->id)->delete();
            Offer::query()->whereIn('id', $offers)->delete();
            Offer::query()->where('target_user_id', $user->id)->update(['target_user_id' => null, 'status' => Offer::SETTLED]);
            $this->db->table('longshot_ledger')->where('user_id', $user->id)->delete();
            $this->db->table('longshot_wallets')->where('user_id', $user->id)->delete();
        });
    }

    /** @var array<int, User> loaded once per run, however many payouts they get */
    private array $users = [];

    /** A member, or null if they are gone (deleted with foreign keys off). */
    private function user(int $id): ?User
    {
        if (! array_key_exists($id, $this->users)) {
            $this->users[$id] = User::query()->find($id);
        }

        return $this->users[$id];
    }

    private function notify(Bet $bet): void
    {
        if ($user = $this->user($bet->user_id)) {
            $this->notifications->sync(new BetSettledBlueprint($bet, $user), [$user]);
        }
    }
}
