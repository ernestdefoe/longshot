<?php

namespace ErnestDefoe\Longshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string|null $title
 * @property string      $league
 * @property string|null $home_name
 * @property string|null $away_name
 * @property string|null $home_logo
 * @property string|null $away_logo
 * @property string|null $home_color
 * @property string|null $away_color
 * @property Carbon      $starts_at
 * @property string      $status
 * @property int|null    $home_score
 * @property int|null    $away_score
 * @property int|null    $picks_event_id
 * @property string      $source
 * @property string|null $external_id
 * @property string|null $league_key
 * @property bool        $locked
 * @property int|null    $home_team_id
 * @property int|null    $away_team_id
 * @property int|null    $created_by
 * @property Carbon|null $settled_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Market> $markets
 */
class Event extends AbstractModel
{
    public const OPEN = 'open';
    public const SETTLED = 'settled';
    public const VOID = 'void';
    /** Taken off the board by staff; kept so a sync does not bring it back. */
    public const HIDDEN = 'hidden';

    public $timestamps = true;

    protected $table = 'longshot_events';

    protected $casts = [
        'starts_at' => 'datetime',
        'settled_at' => 'datetime',
        'home_score' => 'integer',
        'away_score' => 'integer',
        'picks_event_id' => 'integer',
        'locked' => 'boolean',
        'home_team_id' => 'integer',
        'away_team_id' => 'integer',
    ];

    /** @return HasMany<Market, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class, 'event_id')->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'event_id');
    }

    /** @return HasMany<Leg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(Leg::class, 'event_id');
    }

    /** A game between two sides, rather than a free-standing event with a title. */
    public function isMatchup(): bool
    {
        return $this->home_name !== null && $this->away_name !== null;
    }

    /** Taking bets: open, and not yet started. */
    public function isBettable(?Carbon $now = null): bool
    {
        return $this->status === self::OPEN && ($now ?? Carbon::now())->lt($this->starts_at);
    }

    public function name(): string
    {
        return $this->title ?: trim(($this->away_name ?? '').' @ '.($this->home_name ?? ''));
    }
}
