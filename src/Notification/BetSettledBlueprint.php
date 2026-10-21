<?php

namespace ErnestDefoe\Longshot\Notification;

use ErnestDefoe\Longshot\Model\Bet;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * "Your bet won 350 points." The subject is the member themselves: a bet has
 * no API resource of its own, and the alert carries everything it shows.
 */
class BetSettledBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Bet $bet, public User $user)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): mixed
    {
        return ['bet' => $this->bet->id, 'status' => $this->bet->status, 'payout' => (int) $this->bet->payout, 'stake' => $this->bet->stake];
    }

    public static function getType(): string
    {
        return 'longshotBetSettled';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
