import type { BookEvent, MarketType } from '../common/types';
import extractText from 'flarum/common/utils/extractText';
import { formatLine, sideLine } from '../common/odds';
import { t } from '../common/t';
import { wallet } from './api';

/** "Steelers -3.5", "Over 44.5", "Draw", or a custom option's own name. */
export function selectionLabel(event: BookEvent, type: MarketType, key: string, label: string | null, line: string | null): string {
  if (type === 'custom') return label ?? '';

  const team = key === 'home' ? event.home?.name : key === 'away' ? event.away?.name : null;

  if (type === 'moneyline') return key === 'draw' ? extractText(t('forum.market.draw')) : (team ?? '');
  if (type === 'spread') return `${team ?? ''} ${formatLine(sideLine(type, key, line))}`;

  return `${extractText(t(key === 'over' ? 'forum.market.over' : 'forum.market.under'))} ${line === null ? '' : Number(line)}`;
}

export function marketLabel(type: MarketType, label: string | null): string {
  return type === 'custom' ? (label ?? '') : extractText(t('forum.market.' + type));
}

/** Points, with the forum's name for them. */
export function points(n: number | null | undefined, name?: string): string {
  const value = (n ?? 0).toLocaleString();
  const custom = name ?? wallet.current?.currencyName ?? '';

  return custom ? `${value} ${custom}` : extractText(t('forum.points', { count: n ?? 0 }));
}

export function when(iso: string): string {
  const date = new Date(iso);
  return date.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export function day(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
}
