<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's limits: the forum's defaults, raised by any group they are in.
 *
 * 🚨 Most generous wins, as with permissions: a member who is in Members and
 * in High Rollers gets the High Rollers limit. Members are counted in the
 * Members group even though Flarum stores no row for it.
 */
class Limits
{
    /** @var array<int, array{max_stake: int, daily_limit: int, allowance: int}> */
    private array $memo = [];

    public function __construct(private ConnectionInterface $db, private Config $config)
    {
    }

    /** @return array{max_stake: int, daily_limit: int, allowance: int} */
    public function for(User $user): array
    {
        if (isset($this->memo[$user->id])) {
            return $this->memo[$user->id];
        }

        $limits = ['max_stake' => $this->config->maxStake(), 'daily_limit' => $this->config->dailyLimit(), 'allowance' => $this->config->allowance()];

        $groups = $user->groups->pluck('id')->push(Group::MEMBER_ID)->unique()->all();

        foreach ($this->db->table('longshot_group_limits')->whereIn('group_id', $groups)->get() as $row) {
            foreach (['max_stake', 'allowance'] as $key) {
                if ($row->$key !== null) {
                    $limits[$key] = max($limits[$key], (int) $row->$key);
                }
            }

            // 0 means "no daily limit", which is the most generous of all.
            if ($row->daily_limit !== null && $limits['daily_limit'] !== 0) {
                $limits['daily_limit'] = (int) $row->daily_limit === 0 ? 0 : max($limits['daily_limit'], (int) $row->daily_limit);
            }
        }

        $limits['max_stake'] = min($limits['max_stake'], Odds::HARD_MAX_PAYOUT);

        return $this->memo[$user->id] = $limits;
    }

    /** What the member has staked today (UTC), on bets and exchange offers and matches alike. */
    public function stakedToday(User $user): int
    {
        return (int) -$this->db->table('longshot_ledger')
            ->where('user_id', $user->id)
            ->whereIn('kind', ['stake', 'offer', 'lay'])
            ->where('created_at', '>=', Carbon::now()->utc()->startOfDay())
            ->sum('amount');
    }
}
