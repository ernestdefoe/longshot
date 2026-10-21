<?php

namespace ErnestDefoe\Longshot;

/**
 * Whether a selection won, from the final score and the line it was taken at.
 *
 * 🚨 Lines are compared in tenths as integers: a spread of -7.5 is -75 and a
 * score of 24 is 240. No float ever decides a result, so a push on a whole
 * number is a push, not a 6.9999999 loss.
 */
final class Grader
{
    public const WIN = 'win';
    public const LOSE = 'lose';
    public const PUSH = 'push';
    public const VOID = 'void';

    /** The result of one selection, or null when the market cannot be graded from a score. */
    public static function grade(string $type, string $key, ?string $line, int $home, int $away, bool $hasDraw = false): ?string
    {
        $tenths = $line === null ? 0 : self::tenths($line);
        $h = $home * 10;
        $a = $away * 10;

        $margin = match ($type) {
            Model\Market::MONEYLINE => match ($key) {
                'home' => $h - $a,
                'away' => $a - $h,
                // The draw wins on a tie and loses otherwise.
                'draw' => $h === $a ? 1 : -1,
                default => null,
            },
            // The line is the HOME team's handicap; the away side takes the opposite.
            Model\Market::SPREAD => match ($key) {
                'home' => $h - $a + $tenths,
                'away' => $a - $h - $tenths,
                default => null,
            },
            Model\Market::TOTAL => match ($key) {
                'over' => $h + $a - $tenths,
                'under' => $tenths - $h - $a,
                default => null,
            },
            default => null,
        };

        if ($margin === null) {
            return null;
        }

        // A tie on a two-way moneyline is a push; with a draw on offer, the sides lose.
        if ($margin === 0 && $type === Model\Market::MONEYLINE && $hasDraw) {
            return self::LOSE;
        }

        return $margin > 0 ? self::WIN : ($margin < 0 ? self::LOSE : self::PUSH);
    }

    /** "-7.5" → -75, "45" → 450. The database returns decimals as strings. */
    public static function tenths(string|int|float $line): int
    {
        $line = trim((string) $line);
        $negative = str_starts_with($line, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($line, '+-'), 2), 2, '0');

        $value = (int) $whole * 10 + (int) substr($fraction.'0', 0, 1);

        return $negative ? -$value : $value;
    }

    /** A line as stored and shown: one decimal place, half points only. */
    public static function normalise(mixed $line): ?string
    {
        if ($line === null || $line === '') {
            return null;
        }

        if (! is_numeric($line)) {
            return null;
        }

        $tenths = (int) round((float) $line * 10);

        // Whole and half points only: a quarter line is two bets, not one.
        if ($tenths % 5 !== 0 || abs($tenths) > 99990) {
            return null;
        }

        return number_format($tenths / 10, 1, '.', '');
    }
}
