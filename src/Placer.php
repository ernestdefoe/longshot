<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Leg;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Selection;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Places bets from a slip.
 *
 * 🚨 Nothing the browser sends is trusted but the ids, the stakes and the
 * price the member agreed to. Prices, lines and whether the event has started
 * are read from the database inside the same transaction that takes the
 * stake, and a price that moved since the member saw it refuses the slip (409,
 * with the new prices) instead of placing it at a price nobody agreed to.
 */
class Placer
{
    use Atomic;

    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Bank $bank,
        private Limits $limits,
        private Allowance $allowance,
        private PicksBridge $picks
    ) {
    }

    /**
     * @param list<array{selection: int, odds: int, line: ?string, stake?: int}> $legs
     * @return list<Bet>
     */
    public function place(User $actor, string $kind, array $legs, int $stake = 0): array
    {
        $actor->assertCan('longshot.bet');

        $parlay = $kind === Bet::PARLAY;
        $count = count($legs);

        if ($count === 0) {
            throw new Rejected('empty');
        }

        if ($parlay && ($count < 2 || $count > $this->config->maxLegs())) {
            throw new Rejected('parlay_legs', ['max' => $this->config->maxLegs()]);
        }

        if (! $parlay && $count > 20) {
            throw new Rejected('too_many');
        }

        $ids = array_map(fn ($leg) => $leg['selection'], $legs);

        if (count(array_unique($ids)) !== $count) {
            throw new Rejected('duplicate');
        }

        $limits = $this->limits->for($actor);
        $stakes = $parlay ? [$stake] : array_map(fn ($leg) => (int) ($leg['stake'] ?? 0), $legs);

        foreach ($stakes as $s) {
            if ($s < $this->config->minStake()) {
                throw new Rejected('min_stake', ['min' => $this->config->minStake()]);
            }

            if ($s > $limits['max_stake']) {
                throw new Rejected('max_stake', ['max' => $limits['max_stake']]);
            }
        }

        $this->bank->open($actor);
        $this->allowance->collect($actor);

        return $this->atomic(function () use ($actor, $parlay, $legs, $ids, $stakes, $limits) {
            // Serialise this member's slips: the daily limit and the balance
            // are read and spent under one lock.
            $this->lockMember($actor);

            $selections = $this->selections($ids);
            $this->check($legs, $selections, $parlay);

            $total = array_sum($stakes);

            if ($limits['daily_limit'] > 0 && $this->limits->stakedToday($actor) + $total > $limits['daily_limit']) {
                throw new Rejected('daily_limit', ['limit' => $limits['daily_limit']]);
            }

            $now = Carbon::now();
            $bets = [];
            $groups = $parlay ? [$legs] : array_map(fn ($leg) => [$leg], $legs);

            foreach ($groups as $i => $group) {
                $odds = array_map(fn ($leg) => $selections[$leg['selection']]->odds, $group);
                $potential = $parlay ? Odds::parlay($stakes[$i], $odds) : Odds::payout($stakes[$i], $odds[0]);

                if ($potential > min($this->config->maxPayout(), Odds::HARD_MAX_PAYOUT)) {
                    throw new Rejected('max_payout', ['max' => $this->config->maxPayout()]);
                }

                $bet = new Bet();
                $bet->user_id = $actor->id;
                $bet->kind = $parlay ? Bet::PARLAY : Bet::SINGLE;
                $bet->stake = $stakes[$i];
                $bet->potential = $potential;
                $bet->status = Bet::OPEN;
                $bet->placed_at = $now;
                $bet->save();

                foreach ($group as $leg) {
                    $selection = $selections[$leg['selection']];
                    $market = $selection->market;

                    $row = new Leg();
                    $row->bet_id = $bet->id;
                    $row->selection_id = $selection->id;
                    $row->market_id = $market->id;
                    $row->event_id = $market->event_id;
                    $row->odds = (int) $selection->odds;
                    $row->line = $market->hasLine() ? $market->line : null;
                    $row->save();
                }

                try {
                    $this->bank->move($actor, -$bet->stake, 'stake', 'bet:'.$bet->id.':stake');
                } catch (InsufficientBalance) {
                    throw new Rejected('balance');
                }

                $bets[] = $bet;
            }

            return $bets;
        });
    }

    /** Lock the row a debit will change, so this member's requests queue. */
    public function lockMember(User $actor): void
    {
        $this->config->currency() === Config::POINTS
            ? $this->db->table('users')->where('id', $actor->id)->lockForUpdate()->value('id')
            : $this->db->table('longshot_wallets')->where('user_id', $actor->id)->lockForUpdate()->value('user_id');
    }

    /**
     * @param list<int> $ids
     * @return array<int, Selection>
     */
    private function selections(array $ids): array
    {
        /** @var Collection<int, Selection> $rows */
        $rows = Selection::query()->whereIn('id', $ids)->with('market.event')->get();

        return $rows->keyBy('id')->all();
    }

    /**
     * @param list<array{selection: int, odds: int, line: ?string}> $legs
     * @param array<int, Selection> $selections
     */
    private function check(array $legs, array $selections, bool $parlay): void
    {
        $now = Carbon::now();
        $moved = [];
        $events = [];

        foreach ($legs as $leg) {
            $selection = $selections[$leg['selection']] ?? null;
            $market = $selection?->market;
            $event = $market?->event;

            if (! $selection || ! $market || ! $event) {
                throw new Rejected('gone');
            }

            if (! $event->isBettable($now) || $market->status !== Market::OPEN || $selection->odds === null) {
                throw new Rejected('closed', ['event' => $event->name()]);
            }

            if ($parlay && isset($events[$event->id])) {
                throw new Rejected('same_event', ['event' => $event->name()]);
            }

            $events[$event->id] = $event;

            $line = $market->hasLine() ? Grader::normalise($market->line) : null;
            $samePrice = Odds::valid($leg['odds']) && $leg['odds'] === $selection->odds
                || (Odds::valid($leg['odds']) && $this->config->acceptBetterOdds() && Odds::decimal($selection->odds) > Odds::decimal($leg['odds']));

            if (! $samePrice || Grader::normalise($leg['line']) !== $line) {
                $moved[] = ['selection' => $selection->id, 'odds' => $selection->odds, 'line' => $line];
            }
        }

        // A game Picks already has under way is closed, whatever our copy of
        // its kickoff says; the minutely sync may not have caught up yet.
        if ($started = $this->picks->startedAmong(array_values($events))) {
            throw new Rejected('closed', ['event' => $started->name()]);
        }

        if ($moved) {
            throw new Rejected('prices', [], $moved);
        }
    }
}
