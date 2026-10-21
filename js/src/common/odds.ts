/*
 * The same integer arithmetic as the server's Odds class, so the slip shows
 * exactly what a bet will pay. BigInt for parlays: a long one passes 2^53
 * on the way.
 */

// BigInt() calls, not 10000n literals: the build's targets may predate them.
const SCALE = BigInt(10000);
const HUNDRED = BigInt(100);

export function profit(stake: number, odds: number): number {
  return odds > 0 ? Math.floor((stake * odds) / 100) : Math.floor((stake * 100) / -odds);
}

export function payout(stake: number, odds: number): number {
  return stake + profit(stake, odds);
}

export function decimal(odds: number): bigint {
  return SCALE + (odds > 0 ? BigInt(odds) * HUNDRED : (SCALE * HUNDRED) / BigInt(-odds));
}

export function parlay(stake: number, odds: number[]): number {
  if (odds.length === 1) return payout(stake, odds[0]);

  let value = BigInt(stake) * SCALE;
  for (const price of odds) value = (value * decimal(price)) / SCALE;

  const out = value / SCALE;
  return out > BigInt(Number.MAX_SAFE_INTEGER) ? Number.MAX_SAFE_INTEGER : Number(out);
}

export type OddsFormat = 'american' | 'decimal';

export function formatOdds(odds: number | null, format: OddsFormat = 'american'): string {
  if (odds === null) return '–';

  if (format === 'decimal') {
    return (Number(decimal(odds)) / 10000).toFixed(2);
  }

  return Math.abs(odds) === 100 ? 'EVEN' : odds > 0 ? `+${odds}` : String(odds);
}

/** A line with its sign: "+3.5", "-7.0" shown as "-7". */
export function formatLine(line: string | number | null): string {
  if (line === null || line === '') return '';
  const n = Number(line);
  const text = Number.isInteger(n) ? String(Math.abs(n)) : Math.abs(n).toFixed(1);
  return n > 0 ? `+${text}` : n < 0 ? `-${text}` : 'PK';
}

/** The line a selection is taken at, from the market's (home) line. */
export function sideLine(type: string, key: string, line: string | null): string | null {
  if (line === null) return null;
  if (type === 'spread' && key === 'away') return String(-Number(line));
  return line;
}
