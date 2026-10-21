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
 * @property int         $selection_id
 * @property int         $market_id
 * @property int         $event_id
 * @property int         $odds
 * @property string|null $line
 * @property int         $stake
 * @property int         $matched
 * @property int|null    $target_user_id
 * @property string      $status
 * @property Carbon      $created_at
 * @property-read User   $user
 * @property-read User|null $target
 * @property-read Selection $selection
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OfferMatch> $matches
 */
class Offer extends AbstractModel
{
    /** Open to be matched. */
    public const OPEN = 'open';
    /** No more matching: cancelled, filled or started. Matched parts await the result. */
    public const CLOSED = 'closed';
    /** Every match settled. */
    public const SETTLED = 'settled';

    public $timestamps = true;

    protected $table = 'longshot_offers';

    protected $casts = [
        'user_id' => 'integer',
        'odds' => 'integer',
        'stake' => 'integer',
        'matched' => 'integer',
        'target_user_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** @return BelongsTo<Selection, $this> */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class, 'selection_id');
    }

    /** @return HasMany<OfferMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(OfferMatch::class, 'offer_id')->orderBy('id');
    }

    public function remaining(): int
    {
        return $this->stake - $this->matched;
    }
}
