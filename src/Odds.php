<?php

namespace ErnestDefoe\Longshot;

use InvalidArgumentException;

/**
 * Prices and returns, in whole points.
 *
 * 🚨 Integer arithmetic only, and every rounding is DOWN. A float would make
 * the same bet pay 190 on one server and 189 on another; a floor is the same
 * everywhere, and the book never pays out a fraction of a point it does not
 * have. The caps below keep every intermediate product inside a 64-bit int.
 */
final class Odds
{
    /** Longest price either way: +10000 / -10000 (101.0 in decimal). */
    public const MAX = 10000;

    /** No single bet may return more than this, whatever the settings say. */
    public const HARD_MAX_PAYOUT = 100_000_000;

    /** Decimal odds are carried in ten-thousandths: 1.9090 is 19090. */
    public const SCALE = 10000;

    public static function valid(mixed $odds): bool
    {
        return is_int($odds) && abs($odds) >= 100 && abs($odds) <= self::MAX;
    }

    public static function assertValid(mixed $odds): int
    {
        if (! self::valid($odds)) {
            throw new InvalidArgumentException('American odds must be a whole number from 100 to '.self::MAX.' either way.');
        }

        return $odds;
    }

    /** What a winning single returns on top of its stake. */
    public static function profit(int $stake, int $odds): int
    {
        self::assertValid($odds);

        return $odds > 0 ? intdiv($stake * $odds, 100) : intdiv($stake * 100, -$odds);
    }

    /**
     * What a layer puts up against $back of a backer's stake at the backer's
     * price: the backer's profit, rounded UP.
     *
     * 🚨 Up, not down. The layer chooses how much to match, so rounding in
     * their favour can be farmed: laying 1,000 at -101 in pieces of 2 cost
     * 1 point a piece (500 in all, not 990). Rounding up, a piece can only
     * ever cost the layer a fraction of a point more, never the backer.
     */
    public static function liability(int $back, int $odds): int
    {
        self::assertValid($odds);

        return $odds > 0 ? intdiv($back * $odds + 99, 100) : intdiv($back * 100 + (-$odds - 1), -$odds);
    }

    /** Stake plus profit: what a winning single pays back. */
    public static function payout(int $stake, int $odds): int
    {
        return $stake + self::profit($stake, $odds);
    }

    /** Decimal odds in ten-thousandths, rounded down: -110 is 19090, +150 is 25000. */
    public static function decimal(int $odds): int
    {
        self::assertValid($odds);

        return self::SCALE + ($odds > 0 ? $odds * 100 : intdiv(self::SCALE * 100, -$odds));
    }

    /**
     * What a parlay pays back when every leg in $odds wins (the others having
     * been pushed or voided and dropped). One leg is priced like a single.
     *
     * The running value is kept in ten-thousandths and floored after each leg,
     * so the order of the legs is fixed by the caller (by leg id).
     *
     * @param list<int> $odds
     */
    public static function parlay(int $stake, array $odds): int
    {
        if (count($odds) === 1) {
            return self::payout($stake, $odds[0]);
        }

        $value = $stake * self::SCALE;

        foreach ($odds as $price) {
            $decimal = self::decimal($price);

            // Past the hard cap the exact figure no longer matters: placement
            // refuses it. Stopping here keeps the product inside 64 bits.
            if ($value > intdiv(PHP_INT_MAX, $decimal) || $value > self::HARD_MAX_PAYOUT * self::SCALE) {
                return self::HARD_MAX_PAYOUT + 1;
            }

            $value = intdiv($value * $decimal, self::SCALE);
        }

        return intdiv($value, self::SCALE);
    }

    /** "+150", "-110", and "EVEN" for +/-100. */
    public static function format(int $odds): string
    {
        return abs($odds) === 100 ? 'EVEN' : ($odds > 0 ? '+'.$odds : (string) $odds);
    }

    /**
     * American odds from a feed's text ("+114", "-135", "EVEN"), or null.
     */
    public static function parse(mixed $text): ?int
    {
        if (is_int($text)) {
            return self::valid($text) ? $text : null;
        }

        $text = strtoupper(trim((string) $text));

        if ($text === 'EVEN' || $text === 'EV') {
            return 100;
        }

        if (! preg_match('/^[+-]?\d{3,5}$/', $text)) {
            return null;
        }

        $odds = (int) $text;

        return self::valid($odds) ? $odds : null;
    }
}
