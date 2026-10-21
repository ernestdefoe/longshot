<?php

namespace ErnestDefoe\Longshot\Notification;

use ErnestDefoe\Longshot\Model\Offer;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "Bob challenged you." Sent to the member a direct offer names. */
class ChallengeBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Offer $offer, public User $backer)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->backer;
    }

    public function getFromUser(): ?User
    {
        return $this->backer;
    }

    public function getData(): mixed
    {
        return ['offer' => $this->offer->id, 'event' => $this->offer->event_id];
    }

    public static function getType(): string
    {
        return 'longshotChallenge';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
