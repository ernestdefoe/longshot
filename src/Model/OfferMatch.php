<?php

namespace ErnestDefoe\Longshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A layer's match against an offer. (Named so because `match` is a PHP keyword.)
 *
 * @property int         $id
 * @property int         $offer_id
 * @property int         $user_id
 * @property int         $back_stake
 * @property int         $lay_stake
 * @property string|null $result  back | lay | push | void
 * @property Carbon      $created_at
 * @property Carbon|null $settled_at
 * @property-read Offer  $offer
 * @property-read User   $user
 */
class OfferMatch extends AbstractModel
{
    public const BACK = 'back';
    public const LAY = 'lay';
    public const PUSH = 'push';
    public const VOID = 'void';

    public $timestamps = false;

    protected $table = 'longshot_matches';

    protected $casts = [
        'user_id' => 'integer',
        'offer_id' => 'integer',
        'back_stake' => 'integer',
        'lay_stake' => 'integer',
        'created_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'offer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
