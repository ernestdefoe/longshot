<?php

namespace ErnestDefoe\Longshot\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $bet_id
 * @property int         $selection_id
 * @property int         $market_id
 * @property int         $event_id
 * @property int         $odds
 * @property string|null $line
 * @property string|null $result
 * @property-read Selection $selection
 */
class Leg extends AbstractModel
{
    public $timestamps = false;

    protected $table = 'longshot_bet_legs';

    protected $casts = [
        'odds' => 'integer',
    ];

    /** @return BelongsTo<Selection, $this> */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class, 'selection_id');
    }
}
