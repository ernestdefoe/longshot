<?php

namespace ErnestDefoe\Longshot\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $market_id
 * @property string      $key
 * @property string|null $label
 * @property int|null    $odds
 * @property string|null $result
 * @property int         $sort
 * @property-read Market $market
 */
class Selection extends AbstractModel
{
    public $timestamps = false;

    protected $table = 'longshot_selections';

    protected $casts = [
        'odds' => 'integer',
        'sort' => 'integer',
    ];

    /** @return BelongsTo<Market, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }
}
