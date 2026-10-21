<?php

namespace ErnestDefoe\Longshot\Notification;

use ErnestDefoe\Longshot\Model\OfferMatch;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "Alice took the other side of your offer." Sent to the backer. */
class OfferMatchedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public OfferMatch $match, public User $layer)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->layer;
    }

    public function getFromUser(): ?User
    {
        return $this->layer;
    }

    public function getData(): mixed
    {
        return ['offer' => $this->match->offer_id, 'match' => $this->match->id, 'back' => $this->match->back_stake];
    }

    public static function getType(): string
    {
        return 'longshotOfferMatched';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
