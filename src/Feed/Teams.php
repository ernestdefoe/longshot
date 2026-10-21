<?php

namespace ErnestDefoe\Longshot\Feed;

use Carbon\Carbon;
use ErnestDefoe\Longshot\Model\Team;
use Illuminate\Support\Collection;

/**
 * The book's own list of teams, per league, which every provider's names are
 * matched against ("Man United" and "Manchester United" are one team).
 *
 * 🚨 Staff's edits win: a locked team's name, colours and logo are never
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
        $team = $this->find($league, $side->name, $side->abbreviation);

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

    /** A team by any name it is known by, or by abbreviation; null if unknown. */
    public function find(string $league, string $name, ?string $abbreviation = null): ?Team
    {
        $key = Side::key($name);
        $teams = $this->league($league);

        return $teams->first(fn (Team $t) => $t->name_key === $key || in_array($key, (array) $t->aliases, true))
            // "Arizona Cardinals" vs "Cardinals": one name wholly inside the other.
            ?? $teams->first(fn (Team $t) => strlen($key) >= 4 && strlen($t->name_key) >= 4 && (str_contains($t->name_key, $key) || str_contains($key, $t->name_key)) && $this->unique($teams, $key))
            ?? ($abbreviation ? $teams->first(fn (Team $t) => $t->abbreviation !== null && strcasecmp($t->abbreviation, $abbreviation) === 0) : null);
    }

    /** Only when exactly one team contains the name, or two "Miami"s would merge. */
    private function unique(Collection $teams, string $key): bool
    {
        return $teams->filter(fn (Team $t) => str_contains($t->name_key, $key) || str_contains($key, $t->name_key))->count() === 1;
    }

    /** Remember another name, an id, and fill in branding that is missing. */
    public function learn(Team $team, Side $side, string $provider): void
    {
        $key = Side::key($side->name);
        $aliases = (array) $team->aliases;
        if ($key !== '' && $key !== $team->name_key && ! in_array($key, $aliases, true) && count($aliases) < 20) {
            $aliases[] = $key;
            $team->aliases = $aliases;
        }

        if ($side->id) {
            $ids = (array) $team->external_ids;
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
