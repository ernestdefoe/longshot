<?php

namespace ErnestDefoe\Longshot;

use ErnestDefoe\Longshot\Model\Bet;
use ErnestDefoe\Longshot\Model\Event;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Model\Offer;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * Everything Longshot sends to a browser is built here, field by field, from
 * models whose relations the caller loaded in bulk: nothing here queries.
 */
class Presenter
{
    public function __construct(private SlugManager $slugs)
    {
    }

    /** @return array<string, mixed> */
    public function event(Event $event, bool $withMarkets = true): array
    {
        $out = [
            'id' => $event->id,
            'title' => $event->title,
            'name' => $event->name(),
            'league' => $event->league,
            'leagueKey' => $event->league_key,
            'home' => $event->home_name === null ? null : ['name' => $event->home_name, 'logo' => $event->home_logo, 'color' => $event->home_color],
            'away' => $event->away_name === null ? null : ['name' => $event->away_name, 'logo' => $event->away_logo, 'color' => $event->away_color],
            'locked' => $event->locked,
            'startsAt' => $event->starts_at->toIso8601String(),
            'status' => $event->status,
            'bettable' => $event->isBettable(),
            'score' => $event->home_score === null ? null : ['home' => $event->home_score, 'away' => $event->away_score],
            'linked' => $event->picks_event_id !== null,
            'source' => $event->source,
        ];

        if ($withMarkets) {
            $out['markets'] = $event->markets->map(fn (Market $m) => $this->market($m))->values()->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function market(Market $market): array
    {
        return [
            'id' => $market->id,
            'type' => $market->type,
            'label' => $market->label,
            'line' => $market->hasLine() ? Grader::normalise($market->line) : null,
            'status' => $market->status,
            'feed' => $market->feed,
            'oddsProvider' => $market->feed ? $market->odds_provider : null,
            'selections' => $market->selections->map(fn ($s) => [
                'id' => $s->id,
                'key' => $s->key,
                'label' => $s->label,
                'odds' => $s->odds,
                'result' => $s->result,
            ])->values()->all(),
        ];
    }

    /**
     * @param Collection<int, Bet> $bets with legs.selection.market.event loaded
     * @return list<array<string, mixed>>
     */
    public function bets(Collection $bets): array
    {
        return $bets->map(fn (Bet $bet) => [
            'id' => $bet->id,
            'kind' => $bet->kind,
            'stake' => $bet->stake,
            'potential' => $bet->potential,
            'payout' => $bet->payout,
            'status' => $bet->status,
            'placedAt' => $bet->placed_at->toIso8601String(),
            'settledAt' => $bet->settled_at?->toIso8601String(),
            'legs' => $bet->legs->map(function ($leg) {
                $selection = $leg->selection;
                $market = $selection->market;

                return [
                    'id' => $leg->id,
                    'odds' => $leg->odds,
                    'line' => Grader::normalise($leg->line),
                    'result' => $leg->result,
                    'selection' => ['id' => $selection->id, 'key' => $selection->key, 'label' => $selection->label],
                    'market' => ['id' => $market->id, 'type' => $market->type, 'label' => $market->label],
                    'event' => $this->event($market->event, false),
                ];
            })->values()->all(),
        ])->values()->all();
    }

    /**
     * @param Collection<int, Offer> $offers with user, target, matches and selection.market.event loaded
     * @return list<array<string, mixed>>
     */
    public function offers(Collection $offers, User $actor): array
    {
        return $offers->map(function (Offer $offer) use ($actor) {
            $selection = $offer->selection;
            $market = $selection->market;
            $involved = $offer->user_id === $actor->id || $offer->target_user_id === $actor->id;

            return [
                'id' => $offer->id,
                'user' => $offer->user ? $this->user($offer->user) : null,
                'target' => $offer->target ? $this->user($offer->target) : null,
                'odds' => $offer->odds,
                // What a layer risks per point of the backer's stake is the
                // backer's price turned around; the client shows both.
                'line' => Grader::normalise($offer->line),
                'stake' => $offer->stake,
                'matched' => $offer->matched,
                'remaining' => $offer->status === Offer::OPEN ? $offer->remaining() : 0,
                'status' => $offer->status,
                'createdAt' => $offer->created_at->toIso8601String(),
                'mine' => $offer->user_id === $actor->id,
                'selection' => ['id' => $selection->id, 'key' => $selection->key, 'label' => $selection->label],
                'market' => ['id' => $market->id, 'type' => $market->type, 'label' => $market->label],
                'event' => $this->event($market->event, false),
                // Who laid what is between the two sides; others see totals.
                'matches' => $offer->matches
                    ->filter(fn ($m) => $involved || $m->user_id === $actor->id)
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'user' => $m->user ? $this->user($m->user) : null,
                        'back' => $m->back_stake,
                        'lay' => $m->lay_stake,
                        'result' => $m->result,
                    ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array{id: int, username: string, displayName: string, avatarUrl: ?string, slug: string} */
    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
        ];
    }
}
