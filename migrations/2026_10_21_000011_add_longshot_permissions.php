<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'longshot.view' => Group::GUEST_ID,
    'longshot.bet' => Group::MEMBER_ID,
    'longshot.exchange' => Group::MEMBER_ID,
]);
