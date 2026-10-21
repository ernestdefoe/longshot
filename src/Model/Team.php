<?php

namespace ErnestDefoe\Longshot\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int         $id
 * @property string      $league_key
 * @property string      $name
 * @property string      $name_key
 * @property string|null $abbreviation
 * @property string|null $color
 * @property string|null $alt_color
 * @property string|null $logo_path
 * @property string|null $logo_source
 * @property array<int, string>|null $aliases
 * @property array<string, string>|null $external_ids
 * @property bool        $locked
 * @property Carbon|null $branded_at
 * @property Carbon|null $logo_failed_at
 */
class Team extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'longshot_teams';

    protected $casts = [
        'aliases' => 'array',
        'external_ids' => 'array',
        'locked' => 'boolean',
        'branded_at' => 'datetime',
        'logo_failed_at' => 'datetime',
    ];
}
