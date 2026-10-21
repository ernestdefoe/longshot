<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * Every point that moves, moves through here, and leaves a ledger row.
 *
 * 🚨 The ledger row is written FIRST, with a ref that is unique in the
 * database. A second attempt at the same movement (a retried job, two
 * settlement runs at once, a double-clicked button) inserts nothing and moves
 * nothing. `insertOrIgnore` rather than catching the violation: on
 * PostgreSQL a failed insert poisons the whole surrounding transaction.
 *
 * 🚨 A debit is one conditional UPDATE (`balance >= amount`), so two bets
 * racing for the last points cannot both pass a check made before either
 * wrote. Callers run inside a transaction; if the debit is refused, the
 * exception rolls the ledger row back with everything else.
 */
class Bank
{
    public const SOURCE = 'longshot';

    public function __construct(private ConnectionInterface $db, private Config $config, private Container $container)
    {
    }

    /** The actor's spendable balance. */
    public function balance(User $user): int
    {
        if ($this->config->currency() === Config::POINTS) {
            return (int) $this->db->table('users')->where('id', $user->id)->value('votes');
        }

        return (int) $this->db->table('longshot_wallets')->where('user_id', $user->id)->value('balance');
    }

    /** @param list<int> $ids @return array<int, int> */
    public function balances(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $query = $this->config->currency() === Config::POINTS
            ? $this->db->table('users')->whereIn('id', $ids)->pluck('votes', 'id')
            : $this->db->table('longshot_wallets')->whereIn('user_id', $ids)->pluck('balance', 'user_id');

        return array_map('intval', $query->all());
    }

    /**
     * Make sure the member has a wallet, crediting the starting balance the
     * first time. Cheap after that: one insert-or-ignore.
     */
    public function open(User $user): void
    {
        $now = Carbon::now();

        $created = $this->db->table('longshot_wallets')->insertOrIgnore([
            'user_id' => $user->id, 'balance' => 0, 'private' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($created && ($start = $this->config->startingBalance()) > 0) {
            $this->db->transaction(fn () => $this->move($user, $start, 'start', 'start:'.$user->id), 3);
        }
    }

    /**
     * Move points into (positive) or out of (negative) a member's balance.
     *
     * @return bool false when this ref was already moved: nothing happened.
     * @throws InsufficientBalance on a debit the balance cannot cover.
     */
    public function move(User $user, int $amount, string $kind, string $ref): bool
    {
        if ($amount === 0) {
            return false;
        }

        if ($this->db->transactionLevel() === 0) {
            return $this->db->transaction(fn () => $this->move($user, $amount, $kind, $ref), 3);
        }

        $claimed = $this->db->table('longshot_ledger')->insertOrIgnore([
            'user_id' => $user->id,
            'amount' => $amount,
            'kind' => $kind,
            'ref' => $ref,
            'created_at' => Carbon::now(),
        ]);

        if (! $claimed) {
            return false;
        }

        if ($this->config->currency() === Config::POINTS) {
            $this->points($user, $amount, $ref);

            return true;
        }

        if ($amount > 0) {
            // Payouts go to members who opened a wallet to bet; an admin's
            // grant may not have. Ignored when the wallet is there.
            $this->db->table('longshot_wallets')->insertOrIgnore([
                'user_id' => $user->id, 'balance' => 0, 'private' => false, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
        }

        $query = $this->db->table('longshot_wallets')->where('user_id', $user->id);

        if ($amount < 0) {
            $query->where('balance', '>=', -$amount);
        }

        // $amount is an int: nothing user-supplied reaches the expression.
        $updated = $query->update([
            'balance' => $this->db->raw($this->db->getQueryGrammar()->wrap('balance').' + '.$amount),
            'updated_at' => Carbon::now(),
        ]);

        if (! $updated) {
            throw new InsufficientBalance();
        }

        return true;
    }

    /**
     * Spend or pay FoF Gamification points, through its grants.
     *
     * The member's row is locked first, so two debits for the same member
     * queue rather than both reading the same total.
     */
    private function points(User $user, int $amount, string $ref): void
    {
        $votes = (int) $this->db->table('users')->where('id', $user->id)->lockForUpdate()->value('votes');

        if ($amount < 0 && $votes < -$amount) {
            throw new InsufficientBalance();
        }

        /** @var \FoF\Gamification\PointGrants $grants */
        $grants = $this->container->make(\FoF\Gamification\PointGrants::class);
        $grants->grant($user, self::SOURCE, $ref, $amount);
    }
}
