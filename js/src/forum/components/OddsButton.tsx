import Component, { type ComponentAttrs } from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type { BookEvent, Market, Selection } from '../../common/types';
import { formatLine, formatOdds, sideLine } from '../../common/odds';
import { marketLabel, selectionLabel } from '../labels';
import { prefs } from '../prefs';
import { slip } from '../state/slip';

export interface OddsButtonAttrs extends ComponentAttrs {
  event: BookEvent;
  market: Market;
  selection: Selection;
  /** Show the option's name above the price (custom markets, the event page). */
  named?: boolean;
}

/** One price. Tapping it adds the selection to the slip, or takes it off. */
export default class OddsButton extends Component<OddsButtonAttrs> {
  view(): Mithril.Children {
    const { event, market, selection, named } = this.attrs;
    const line = sideLine(market.type, selection.key, market.line);
    const open = event.bettable && market.status === 'open' && selection.odds !== null;
    const picked = slip.has(selection.id);
    const label = selectionLabel(event, market.type, selection.key, selection.label, market.line);

    return (
      <button
        type="button"
        className={classList('Longshot-odds', { 'is-picked': picked, 'is-won': selection.result === 'win', 'is-lost': selection.result === 'lose' })}
        disabled={!open}
        aria-pressed={picked ? 'true' : 'false'}
        aria-label={`${label} ${formatOdds(selection.odds, prefs.odds)}`}
        onclick={() =>
          slip.toggle({
            selection: selection.id,
            odds: selection.odds as number,
            line: market.type === 'spread' || market.type === 'total' ? market.line : null,
            label,
            market: marketLabel(market.type, market.label),
            marketType: market.type,
            event: { id: event.id, name: event.name },
          })
        }
      >
        {named ? <span className="Longshot-odds-name">{label}</span> : null}
        {!named && line !== null ? (
          <span className="Longshot-odds-line">
            {market.type === 'total' ? `${selection.key === 'over' ? 'O' : 'U'} ${Number(line)}` : formatLine(line)}
          </span>
        ) : null}
        <span className="Longshot-odds-price">{formatOdds(selection.odds, prefs.odds)}</span>
      </button>
    );
  }
}
