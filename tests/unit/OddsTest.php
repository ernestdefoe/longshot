<?php

namespace ErnestDefoe\Longshot\Tests\unit;

use ErnestDefoe\Longshot\Feed\EspnOdds;
use ErnestDefoe\Longshot\Grader;
use ErnestDefoe\Longshot\Model\Market;
use ErnestDefoe\Longshot\Odds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OddsTest extends TestCase
{
    #[Test]
    public function singles_pay_whole_points_rounded_down(): void
    {
        $this->assertSame(190, Odds::payout(100, -110)); // 90.909...
        $this->assertSame(250, Odds::payout(100, 150));
        $this->assertSame(200, Odds::payout(100, 100));
        $this->assertSame(200, Odds::payout(100, -100));
        $this->assertSame(9, Odds::payout(7, -300)); // 2.33 → 2
        $this->assertSame(1, Odds::profit(3, -300));
        $this->assertSame(0, Odds::profit(2, -300));
        $this->assertSame(10100, Odds::payout(100, 10000));
    }

    #[Test]
    public function decimal_odds_are_exact_for_plus_prices_and_floored_for_minus(): void
    {
        $this->assertSame(25000, Odds::decimal(150));
        $this->assertSame(19090, Odds::decimal(-110));
        $this->assertSame(20000, Odds::decimal(100));
        $this->assertSame(1010000, Odds::decimal(10000));
        $this->assertSame(10100, Odds::decimal(-10000));
    }

    #[Test]
    public function a_parlay_multiplies_and_floors_and_one_leg_prices_like_a_single(): void
    {
        // 100 × 1.909 × 1.909 = 364.42 → 364
        $this->assertSame(364, Odds::parlay(100, [-110, -110]));
        // 10 × 2.5 × 3.0 = 75
        $this->assertSame(75, Odds::parlay(10, [150, 200]));
        $this->assertSame(Odds::payout(100, -110), Odds::parlay(100, [-110]));
    }

    #[Test]
    public function an_absurd_parlay_never_overflows(): void
    {
        $this->assertSame(Odds::HARD_MAX_PAYOUT + 1, Odds::parlay(Odds::HARD_MAX_PAYOUT, array_fill(0, 12, 10000)));
        $this->assertGreaterThan(Odds::HARD_MAX_PAYOUT, Odds::parlay(1000, array_fill(0, 8, 10000)));
    }

    #[Test]
    public function only_whole_prices_from_100_to_10000_are_valid(): void
    {
        foreach ([-110, 100, -100, 150, 10000, -10000] as $ok) {
            $this->assertTrue(Odds::valid($ok), (string) $ok);
        }
        foreach ([0, 99, -99, 50, 10001, -10001, '110', 110.0, null] as $bad) {
            $this->assertFalse(Odds::valid($bad), var_export($bad, true));
        }
    }

    #[Test]
    public function feed_prices_parse(): void
    {
        $this->assertSame(114, Odds::parse('+114'));
        $this->assertSame(-135, Odds::parse('-135'));
        $this->assertSame(100, Odds::parse('EVEN'));
        $this->assertNull(Odds::parse('OFF'));
        $this->assertNull(Odds::parse('+50'));
        $this->assertNull(Odds::parse(''));
        $this->assertSame('EVEN', Odds::format(-100));
        $this->assertSame('+150', Odds::format(150));
    }

    /** @return list<array{string, string, ?string, int, int, bool, ?string}> */
    public static function grades(): array
    {
        return [
            // moneyline, two-way: a tie is a push
            [Market::MONEYLINE, 'home', null, 24, 17, false, Grader::WIN],
            [Market::MONEYLINE, 'away', null, 24, 17, false, Grader::LOSE],
            [Market::MONEYLINE, 'home', null, 20, 20, false, Grader::PUSH],
            // three-way: a tie loses for both sides, wins the draw
            [Market::MONEYLINE, 'home', null, 1, 1, true, Grader::LOSE],
            [Market::MONEYLINE, 'away', null, 1, 1, true, Grader::LOSE],
            [Market::MONEYLINE, 'draw', null, 1, 1, true, Grader::WIN],
            [Market::MONEYLINE, 'draw', null, 2, 1, true, Grader::LOSE],
            // spreads: the line is the home team's
            [Market::SPREAD, 'home', '-7.5', 28, 20, false, Grader::WIN],
            [Market::SPREAD, 'away', '-7.5', 28, 20, false, Grader::LOSE],
            [Market::SPREAD, 'home', '-7.5', 27, 20, false, Grader::LOSE],
            [Market::SPREAD, 'away', '-7.5', 27, 20, false, Grader::WIN],
            [Market::SPREAD, 'home', '-7.0', 27, 20, false, Grader::PUSH],
            [Market::SPREAD, 'away', '-7.0', 27, 20, false, Grader::PUSH],
            [Market::SPREAD, 'home', '3.5', 17, 20, false, Grader::WIN],
            [Market::SPREAD, 'away', '+3.5', 17, 20, false, Grader::LOSE],
            [Market::SPREAD, 'home', '0.0', 10, 10, false, Grader::PUSH],
            // totals
            [Market::TOTAL, 'over', '45.5', 24, 22, false, Grader::WIN],
            [Market::TOTAL, 'under', '45.5', 24, 22, false, Grader::LOSE],
            [Market::TOTAL, 'over', '46.0', 24, 22, false, Grader::PUSH],
            [Market::TOTAL, 'under', '46', 24, 22, false, Grader::PUSH],
            [Market::TOTAL, 'under', '46.5', 24, 22, false, Grader::WIN],
            // custom markets are not graded from a score
            [Market::CUSTOM, 'opt1', null, 1, 0, false, null],
            [Market::SPREAD, 'draw', '1.5', 1, 0, false, null],
        ];
    }

    #[Test]
    #[DataProvider('grades')]
    public function grading(string $type, string $key, ?string $line, int $home, int $away, bool $draw, ?string $expected): void
    {
        $this->assertSame($expected, Grader::grade($type, $key, $line, $home, $away, $draw));
    }

    #[Test]
    public function lines_are_whole_or_half_points(): void
    {
        $this->assertSame('-7.5', Grader::normalise('-7.5'));
        $this->assertSame('3.0', Grader::normalise('+3'));
        $this->assertSame('45.5', Grader::normalise(45.5));
        $this->assertNull(Grader::normalise('0.25'));
        $this->assertNull(Grader::normalise('abc'));
        $this->assertNull(Grader::normalise(''));
        $this->assertSame(-75, Grader::tenths('-7.5'));
        $this->assertSame(460, Grader::tenths('46.0'));
        $this->assertSame(5, Grader::tenths('0.5'));
        $this->assertSame(-5, Grader::tenths('-0.5'));
    }

    #[Test]
    public function an_espn_odds_block_parses_to_markets(): void
    {
        $block = json_decode('{"moneyline":{"home":{"close":{"odds":"+114"},"open":{"odds":"+120"}},"away":{"close":{"odds":"-135"}}},
            "pointSpread":{"home":{"close":{"line":"+2.5","odds":"-105"}},"away":{"close":{"line":"-2.5","odds":"-115"}}},
            "total":{"over":{"close":{"line":"o45.5","odds":"-102"}},"under":{"close":{"line":"u45.5","odds":"-118"}}},
            "drawOdds":{"moneyLine":255}}', true);

        $this->assertSame([
            Market::MONEYLINE => ['home' => 114, 'away' => -135, 'draw' => 255],
            Market::SPREAD => ['line' => '2.5', 'home' => -105, 'away' => -115],
            Market::TOTAL => ['line' => '45.5', 'over' => -102, 'under' => -118],
        ], EspnOdds::parse($block));

        // Quarter lines and missing prices come through as null.
        $partial = EspnOdds::parse(['pointSpread' => ['home' => ['close' => ['line' => '+0.25', 'odds' => '-110']]]]);
        $this->assertNull($partial[Market::SPREAD]['line']);
        $this->assertNull($partial[Market::SPREAD]['away']);
        $this->assertNull($partial[Market::MONEYLINE]['draw']);
    }
}
