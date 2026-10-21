<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Offer;
use ErnestDefoe\Longshot\Model\OfferMatch;
use ErnestDefoe\Longshot\Model\Selection;
use ErnestDefoe\Longshot\Notification\ChallengeBlueprint;
use ErnestDefoe\Longshot\Notification\OfferMatchedBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Member against member. A backer names a selection, a price and a stake;
 * layers take the other side of any part of it. The backer's stake is held
 * when the offer is made, the layer's when they match, and the winner of each
 * match takes both. Unmatched stake goes back when the backer cancels or the
 * event starts.
 */
class Exchange
{
    use Atomic;

    /** Open offers one member may have at once. */
    public const MAX_OPEN = 20;

    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Bank $bank,
        private Limits $limits,
        private Allowance $allowance,
        private Placer $placer,
        private PicksBridge $picks,
        private Settlement $settlement,
        private NotificationSyncer $notifications
    ) {
    }

    public function offer(User $actor, int $selectionId, int $odds, ?string $line, int $stake, ?User $target = null): Offer
    {
        $actor->assertCan('longshot.exchange');

        if (! Odds::valid($odds)) {
            throw new Rejected('odds');
        }

        if ($target && ($target->id === $actor->id || ! $target->hasPermission('longshot.exchange'))) {
            throw new Rejected('target');
        }

        $this->checkStake($actor, $stake);
        $this->bank->open($actor);
        $this->allowance->collect($actor);

        $offer = $this->atomic(function () use ($actor, $selectionId, $odds, $line, $stake, $target) {
            $this->placer->lockMember($actor);

            $selection = $this->open($selectionId);
            $market = $selection->market;

            if (Grader::normalise($line) !== ($market->hasLine() ? Grader::normalise($market->line) : null)) {
                throw new Rejected('prices', [], [['selection' => $selection->id, 'odds' => $selection->odds, 'line' => Grader::normalise($market->line)]]);
            }

            if (Offer::query()->where('user_id', $actor->id)->where('status', Offer::OPEN)->count() >= self::MAX_OPEN) {
                throw new Rejected('max_offers', ['max' => self::MAX_OPEN]);
            }

            $this->checkDaily($actor, $stake);

            // A backer cannot name a price whose win would pay past the cap.
            if (Odds::payout($stake, $odds) > min($this->config->maxPayout(), Odds::HARD_MAX_PAYOUT)) {
                throw new Rejected('max_payout', ['max' => $this->config->maxPayout()]);
            }

            $offer = new Offer();
            $offer->user_id = $actor->id;
            $offer->selection_id = $selection->id;
            $offer->market_id = $market->id;
            $offer->event_id = $market->event_id;
            $offer->odds = $odds;
            $offer->line = $market->hasLine() ? $market->line : null;
            $offer->stake = $stake;
            $offer->matched = 0;
            $offer->target_user_id = $target?->id;
            $offer->status = Offer::OPEN;
            $offer->save();

            try {
                $this->bank->move($actor, -$stake, 'offer', 'offer:'.$offer->id.':stake');
            } catch (InsufficientBalance) {
                throw new Rejected('balance');
            }

            return $offer;
        });

        if ($target) {
            $this->notifications->sync(new ChallengeBlueprint($offer, $actor), [$target]);
        }

        return $offer;
    }

    /**
     * Lay part or all of an offer. $back is how much of the backer's stake to
     * match; the layer risks the backer's profit on it at the offer's price.
     */
    public function match(User $actor, int $offerId, int $back): OfferMatch
    {
        $actor->assertCan('longshot.exchange');

        if ($back < $this->config->minStake()) {
            throw new Rejected('min_stake', ['min' => $this->config->minStake()]);
        }

        $this->bank->open($actor);
        $this->allowance->collect($actor);

        [$match, $offer] = $this->atomic(function () use ($actor, $offerId, $back) {
            // The offer first, then the wallet: the house lock order.
            $offer = Offer::query()->whereKey($offerId)->lockForUpdate()->first();

            // A challenge is taken by the member it names, and nobody else:
            // staff may see it, not accept it on their behalf.
            if (! $offer || ! $this->canSee($actor, $offer) || ($offer->target_user_id !== null && $offer->target_user_id !== $actor->id && $offer->user_id !== $actor->id)) {
                throw new Rejected('gone');
            }

            if ($offer->user_id === $actor->id) {
                throw new Rejected('own_offer');
            }

            if ($offer->status !== Offer::OPEN) {
                throw new Rejected('offer_closed');
            }

            // Then the layer's wallet, before anything else is read, so the
            // daily limit is counted under the same lock that takes the stake.
            $this->placer->lockMember($actor);
            $this->open($offer->selection_id);

            if ($back > $offer->remaining()) {
                throw new Rejected('remaining', ['remaining' => $offer->remaining()]);
            }

            $lay = Odds::liability($back, $offer->odds);

            $this->checkStake($actor, $lay);
            $this->checkDaily($actor, $lay);

            $match = new OfferMatch();
            $match->offer_id = $offer->id;
            $match->user_id = $actor->id;
            $match->back_stake = $back;
            $match->lay_stake = $lay;
            $match->created_at = Carbon::now();
            $match->save();

            $offer->matched += $back;
            if ($offer->remaining() === 0) {
                $offer->status = Offer::CLOSED;
            }
            $offer->save();

            try {
                $this->bank->move($actor, -$lay, 'lay', 'match:'.$match->id.':stake');
            } catch (InsufficientBalance) {
                throw new Rejected('balance');
            }

            return [$match, $offer];
        });

        if ($backer = User::query()->find($offer->user_id)) {
            $this->notifications->sync(new OfferMatchedBlueprint($match, $actor), [$backer]);
        }

        return $match;
    }

    /** Withdraw what is left of an offer. Its owner, or someone who manages the book. */
    public function cancel(User $actor, int $offerId): void
    {
        $offer = Offer::query()->find($offerId);

        if (! $offer || ! $this->canSee($actor, $offer)) {
            throw new Rejected('gone');
        }

        if ($offer->user_id !== $actor->id && ! $actor->can('longshot.manage')) {
            throw new PermissionDeniedException();
        }

        $this->settlement->closeOffer($offer->id);
    }

    /**
     * Who may see an offer: everyone with the book, except a direct
     * challenge, which only its two members (and staff) see.
     */
    public function canSee(User $actor, Offer $offer): bool
    {
        return $offer->target_user_id === null
            || in_array($actor->id, [$offer->user_id, $offer->target_user_id], true)
            || $actor->can('longshot.manage');
    }

    /** A selection still taking bets, read under the caller's transaction. */
    private function open(int $selectionId): Selection
    {
        $selection = Selection::query()->with('market.event')->find($selectionId);
        $market = $selection?->market;
        $event = $market?->event;

        if (! $selection || ! $market || ! $event) {
            throw new Rejected('gone');
        }

        if (! $event->isBettable() || $market->status !== Market::OPEN || $this->picks->startedAmong([$event])) {
            throw new Rejected('closed', ['event' => $event->name()]);
        }

        return $selection;
    }

    private function checkStake(User $actor, int $stake): void
    {
        if ($stake < $this->config->minStake()) {
            throw new Rejected('min_stake', ['min' => $this->config->minStake()]);
        }

        $max = $this->limits->for($actor)['max_stake'];

        if ($stake > $max) {
            throw new Rejected('max_stake', ['max' => $max]);
        }
    }

    private function checkDaily(User $actor, int $amount): void
    {
        $limit = $this->limits->for($actor)['daily_limit'];

        if ($limit > 0 && $this->limits->stakedToday($actor) + $amount > $limit) {
            throw new Rejected('daily_limit', ['limit' => $limit]);
        }
    }
}
