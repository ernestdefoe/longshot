import type { BookEvent, MarketType } from '../../common/types';

export interface SlipLeg {
  selection: number;
  odds: number;
  line: string | null;
  label: string;
  market: string;
  marketType: MarketType;
  event: { id: number; name: string };
  stake: string;
}

const KEY = 'longshot.slip';

/**
 * The bet slip. It lives in this tab's sessionStorage so a member can browse
 * from the board to an event and back without losing it; nothing in it is
 * trusted, the server checks every price again.
 */
class Slip {
  legs: SlipLeg[] = [];
  mode: 'single' | 'parlay' = 'single';
  parlayStake = '';
  open = false;

  constructor() {
    try {
      const saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
      if (saved && Array.isArray(saved.legs)) {
        this.legs = saved.legs;
        this.mode = saved.mode === 'parlay' ? 'parlay' : 'single';
        this.parlayStake = String(saved.parlayStake ?? '');
      }
    } catch (e) {
      // Private windows and blocked storage: an empty slip.
    }
  }

  save() {
    try {
      sessionStorage.setItem(KEY, JSON.stringify({ legs: this.legs, mode: this.mode, parlayStake: this.parlayStake }));
    } catch (e) {
      // Not saved; the slip still works on this page.
    }
  }

  has(selection: number): boolean {
    return this.legs.some((l) => l.selection === selection);
  }

  toggle(leg: Omit<SlipLeg, 'stake'>) {
    if (this.has(leg.selection)) {
      this.remove(leg.selection);
      return;
    }

    this.legs.push({ ...leg, stake: '' });
    this.save();
  }

  remove(selection: number) {
    this.legs = this.legs.filter((l) => l.selection !== selection);
    if (this.legs.length < 2) this.mode = 'single';
    this.save();
  }

  clear() {
    this.legs = [];
    this.mode = 'single';
    this.parlayStake = '';
    this.save();
  }

  /** New prices from a refused slip, so the member sees them before trying again. */
  reprice(prices: Array<{ selection: number; odds: number | null; line: string | null }>) {
    for (const p of prices) {
      const leg = this.legs.find((l) => l.selection === p.selection);
      if (leg && p.odds !== null) {
        leg.odds = p.odds;
        leg.line = p.line;
      }
    }
    this.save();
  }

  /** Two picks from one game cannot go in one parlay. */
  sameEvent(): boolean {
    return new Set(this.legs.map((l) => l.event.id)).size !== this.legs.length;
  }
}

export const slip = new Slip();

export type { BookEvent };
