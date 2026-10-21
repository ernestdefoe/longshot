<?php

namespace ErnestDefoe\Longshot;

use Carbon\Carbon;
use Flarum\User\User;

/**
 * The weekly allowance, paid the first time a member visits the book in a
 * week, rather than to every account by a job on Monday: members who never
 * bet cost nothing, and nobody is paid twice, because the week is in the ref.
 */
class Allowance
{
    public function __construct(private Bank $bank, private Config $config, private Limits $limits)
    {
    }

    /** The ISO week the allowance belongs to, in UTC: "2026-W43". */
    public static function week(?Carbon $now = null): string
    {
        return ($now ?? Carbon::now())->utc()->format('o-\WW');
    }

    /** When the next allowance can be collected. */
    public static function next(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->utc()->startOfWeek(Carbon::MONDAY)->addWeek();
    }

    /** Pay this week's allowance if it is due. Returns what was paid. */
    public function collect(User $user): int
    {
        $amount = $this->limits->for($user)['allowance'];

        if ($amount <= 0) {
            return 0;
        }

        $ref = 'allowance:'.$user->id.':'.self::week();

        if ($this->config->allowanceMode() === 'topup') {
            // Topped up to the allowance, never past it, never taken away.
            $amount = max(0, $amount - $this->bank->balance($user));
        }

        // Nothing to top up claims nothing: the week's one top-up is still
        // there for later in the week. The ref keeps it to one.
        if ($amount === 0) {
            return 0;
        }

        return $this->bank->move($user, $amount, 'allowance', $ref) ? $amount : 0;
    }
}
