export interface Person {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
  slug: string;
}

export type MarketType = 'moneyline' | 'spread' | 'total' | 'custom';
export type Result = 'win' | 'lose' | 'push' | 'void' | null;

export interface Selection {
  id: number;
  key: string;
  label: string | null;
  odds: number | null;
  result: Result;
}

export interface Market {
  id: number;
  type: MarketType;
  label: string | null;
  line: string | null;
  status: 'open' | 'suspended' | 'settled' | 'void';
  feed: boolean;
  oddsProvider?: string | null;
  selections: Selection[];
}

export interface Side {
  name: string;
  logo: string | null;
  color: string | null;
}

export interface BookEvent {
  id: number;
  title: string | null;
  name: string;
  league: string;
  leagueKey?: string | null;
  home: Side | null;
  away: Side | null;
  startsAt: string;
  status: 'open' | 'settled' | 'void';
  bettable: boolean;
  score: { home: number; away: number } | null;
  linked: boolean;
  source?: string;
  locked?: boolean;
  markets?: Market[];
  offers?: number;
  bets?: number;
}

export interface Leg {
  id: number;
  odds: number;
  line: string | null;
  result: Result;
  selection: { id: number; key: string; label: string | null };
  market: { id: number; type: MarketType; label: string | null };
  event: BookEvent;
}

export interface Bet {
  id: number;
  kind: 'single' | 'parlay';
  stake: number;
  potential: number;
  payout: number | null;
  status: 'open' | 'won' | 'lost' | 'push' | 'void';
  placedAt: string;
  settledAt: string | null;
  legs: Leg[];
}

export interface OfferMatch {
  id: number;
  user: Person | null;
  back: number;
  lay: number;
  result: 'back' | 'lay' | 'push' | 'void' | null;
}

export interface Offer {
  id: number;
  user: Person | null;
  target: Person | null;
  odds: number;
  line: string | null;
  stake: number;
  matched: number;
  remaining: number;
  status: 'open' | 'closed' | 'settled';
  createdAt: string;
  mine: boolean;
  selection: { id: number; key: string; label: string | null };
  market: { id: number; type: MarketType; label: string | null };
  event: BookEvent;
  matches: OfferMatch[];
}

export interface Wallet {
  balance: number;
  currency: 'own' | 'points';
  currencyName: string;
  collected: number;
  allowance: number;
  allowanceMode: 'add' | 'topup';
  nextAllowance: string;
  maxStake: number;
  dailyLimit: number;
  stakedToday: number | null;
  minStake: number;
  maxLegs: number;
  maxPayout: number;
  acceptBetterOdds: boolean;
  private: boolean;
  canBet: boolean;
  canExchange: boolean;
  canManage: boolean;
}
