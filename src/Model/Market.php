<?php

namespace ErnestDefoe\Longshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int         $event_id
 * @property string      $type
 * @property string|null $label
 * @property string|null $line
 * @property bool        $feed
 * @property string|null $odds_provider
 * @property string      $status
 * @property int         $sort
 * @property Carbon|null $settled_at
 * @property-read Event  $event
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Selection> $selections
 */
class Market extends AbstractModel
{
    public const MONEYLINE = 'moneyline';
    public const SPREAD = 'spread';
    public const TOTAL = 'total';
    public const CUSTOM = 'custom';

    public const TYPES = [self::MONEYLINE, self::SPREAD, self::TOTAL, self::CUSTOM];

    public const OPEN = 'open';
    public const SUSPENDED = 'suspended';
    public const SETTLED = 'settled';
    public const VOID = 'void';

    /** The selection keys a scored market offers. Custom markets number theirs. */
    public const KEYS = [
        self::MONEYLINE => ['away', 'home', 'draw'],
        self::SPREAD => ['away', 'home'],
        self::TOTAL => ['over', 'under'],
    ];

    public $timestamps = true;

    protected $table = 'longshot_markets';

    protected $casts = [
        'feed' => 'boolean',
        'sort' => 'integer',
        'settled_at' => 'datetime',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /** @return HasMany<Selection, $this> */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class, 'market_id')->orderBy('sort')->orderBy('id');
    }

    public function isScored(): bool
    {
        return $this->type !== self::CUSTOM;
    }

    public function isFinished(): bool
    {
        return $this->status === self::SETTLED || $this->status === self::VOID;
    }

    /** Whether a selection's line is the market's line (spread, total) or none (moneyline, custom). */
    public function hasLine(): bool
    {
        return $this->type === self::SPREAD || $this->type === self::TOTAL;
    }
}
