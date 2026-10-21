<?php

namespace ErnestDefoe\Longshot;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The member's side of the book as the page shows it.
 *
 * Reading it opens the wallet (with the starting balance) and pays the week's
 * allowance if it is due. That is a write behind a GET, deliberately: both are
 * idempotent and only ever credit the member who is reading, so a page forced
 * to load it gives nobody anything they would not get on their next visit.
 */
class Wallets
{
    public function __construct(
        private ConnectionInterface $db,
        private Bank $bank,
        private Allowance $allowance,
        private Limits $limits,
        private Config $config
    ) {
    }

    /** @return array<string, mixed>|null */
    public function summary(User $actor): ?array
    {
        if ($actor->isGuest()) {
            return null;
        }

        $canBet = $actor->hasPermission('longshot.bet');
        $canExchange = $actor->hasPermission('longshot.exchange');
        $collected = 0;

        if ($canBet || $canExchange) {
            $this->bank->open($actor);
            $collected = $this->allowance->collect($actor);
        }

        $limits = $this->limits->for($actor);

        return [
            'balance' => $this->bank->balance($actor),
            'currency' => $this->config->currency(),
            'currencyName' => $this->config->currencyName(),
            'collected' => $collected,
            'allowance' => $limits['allowance'],
            'allowanceMode' => $this->config->allowanceMode(),
            'nextAllowance' => Allowance::next()->toIso8601String(),
            'maxStake' => $limits['max_stake'],
            'dailyLimit' => $limits['daily_limit'],
            'stakedToday' => $limits['daily_limit'] > 0 ? $this->limits->stakedToday($actor) : null,
            'minStake' => $this->config->minStake(),
            'maxLegs' => $this->config->maxLegs(),
            'maxPayout' => $this->config->maxPayout(),
            'acceptBetterOdds' => $this->config->acceptBetterOdds(),
            'private' => (bool) $this->db->table('longshot_wallets')->where('user_id', $actor->id)->value('private'),
            'canBet' => $canBet,
            'canExchange' => $canExchange,
            'canManage' => $actor->can('longshot.manage'),
        ];
    }
}
