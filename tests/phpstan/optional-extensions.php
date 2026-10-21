<?php

/*
 * FoF Gamification's point grants are newer than its latest release (see
 * FriendsOfFlarum/gamification#180). Longshot only uses them when the class
 * exists; this describes it for the analyser.
 */

namespace FoF\Gamification;

if (! class_exists(PointGrants::class, false)) {
    class PointGrants
    {
        public function grant(\Flarum\User\User $user, string $source, string $reason, int $points): bool
        {
            return true;
        }
    }
}
