<?php

namespace ErnestDefoe\Longshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string      $kind
 * @property int         $stake
 * @property int         $potential
 * @property int|null    $payout
 * @property string      $status
 * @property Carbon      $placed_at
 * @property Carbon|null $settled_at
 * @property-read User   $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Leg> $legs
 */
class Bet extends AbstractModel
{
    public const SINGLE = 'single';
    public const PARLAY = 'parlay';

    public const OPEN = 'open';
    public const WON = 'won';
    public const LOST = 'lost';
    /** Every leg pushed or voided: the stake comes back. */
    public const PUSH = 'push';
    public const VOID = 'void';

    public $timestamps = false;

    protected $table = 'longshot_bets';

    protected $casts = [
        'stake' => 'integer',
        'potential' => 'integer',
        'payout' => 'integer',
        'placed_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<Leg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(Leg::class, 'bet_id')->orderBy('id');
    }
}
