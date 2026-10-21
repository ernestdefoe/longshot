<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Team;
use Illuminate\Support\Collection;

/**
 * The book's own list of teams, per league, which every provider's names are
 * matched against.
 *
 * 🚨 Exact matches only: a provider's own id for the team, then its name or a
 * name the SAME provider gave for it (ESPN calls Texas Tech "Texas Tech Red
 * Raiders", "Texas Tech" and "TTU"). No substrings: "Texas" is inside "Texas
 * Tech" and "North Texas", and a wrong match is a member betting on one team
 * under another's name. A game that cannot be matched is left unpriced, or
 * for staff to settle.
 *
 * Staff's edits win: a locked team's name, colours and logo are never
 * touched by a sync.
 */
class Teams
{
    /** @var array<string, Collection<int, Team>> */
    private array $memo = [];

    /** @return Collection<int, Team> */
    public function league(string $league): Collection
    {
        return $this->memo[$league] ??= Team::query()->where('league_key', $league)->get();
    }

    /** The team a provider's side is, created the first time it is seen. */
    public function resolve(string $league, Side $side, string $provider): Team
    {
        $team = ($side->id ? $this->byId($league, $provider, $side->id) : null) ?? $this->find($league, $side->name);

        if (! $team) {
            $team = new Team();
            $team->league_key = $league;
            $team->name = mb_substr($side->name, 0, 100);
            $team->name_key = mb_substr(Side::key($side->name), 0, 100);
            $team->locked = false;
            $team->save();
            $this->league($league)->push($team);
        }

        $this->learn($team, $side, $provider);

        return $team;
    }

    /** A team by a provider's own id for it. */
    public function byId(string $league, string $provider, string $id): ?Team
    {
        return $this->league($league)->first(fn (Team $t) => (string) (((array) $t->external_ids)[$provider] ?? '') === $id);
    }

    /** A team by its name or any name a provider gave it; null if unknown. */
    public function find(string $league, string $name): ?Team
    {
        $key = Side::key($name);

        if ($key === '') {
            return null;
        }

        $teams = $this->league($league);

        return $teams->first(fn (Team $t) => $t->name_key === $key)
            ?? $teams->first(fn (Team $t) => in_array($key, (array) $t->aliases, true));
    }

    /**
     * Record what a provider says about a team it was matched to exactly:
     * the other names it uses, its id, and branding that is missing.
     */
    public function learn(Team $team, Side $side, string $provider): void
    {
        $aliases = (array) $team->aliases;
        foreach (array_merge([$side->name], $side->aliases) as $name) {
            $key = Side::key((string) $name);
            if ($key !== '' && $key !== $team->name_key && ! in_array($key, $aliases, true) && count($aliases) < 20
                // Another team already answers to it: never let two teams share a name.
                && ! $this->league($team->league_key)->contains(fn (Team $t) => $t->id !== $team->id && ($t->name_key === $key || in_array($key, (array) $t->aliases, true)))) {
                $aliases[] = $key;
            }
        }
        $team->aliases = $aliases;

        $ids = (array) $team->external_ids;
        if ($side->id && ! isset($ids[$provider])) {
            $ids[$provider] = $side->id;
            $team->external_ids = $ids;
        }

        if (! $team->locked) {
            $team->abbreviation ??= $side->abbreviation;
            $team->color ??= $side->color;
            $team->alt_color ??= $side->altColor;

            if ($side->logo && ! $team->logo_source && Logos::allowed($side->logo)) {
                $team->logo_source = mb_substr($side->logo, 0, 255);
            }
        }

        if ($team->isDirty()) {
            $team->branded_at = Carbon::now();
            $team->save();
        }
    }
}
