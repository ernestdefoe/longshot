import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type { BookEvent, Market } from '../../common/types';
import { t } from '../../common/t';
import { when } from '../labels';
import OddsButton from './OddsButton';

export interface EventCardAttrs extends ComponentAttrs {
  event: BookEvent;
  /** The league's badge, when the providers cached one. */
  badge?: string | null;
  /** On the event's own page: no link to itself. */
  standalone?: boolean;
}

const ROWS: Array<'away' | 'home'> = ['away', 'home'];

/**
 * A game the way every book shows one: two rows, the sides, and a column per
 * market. Anything else (a draw, props) is on the event's own page.
 */
export default class EventCard extends Component<EventCardAttrs> {
  view(): Mithril.Children {
    const event = this.attrs.event;
    const markets = event.markets ?? [];
    const scored = (['spread', 'total', 'moneyline'] as const).map((type) => markets.find((mk) => mk.type === type));
    // Markets this card does not show: props beside a game, or beyond the
    // first on a titled event; and a moneyline's draw.
    const props = markets.filter((mk) => mk.type === 'custom').length;
    const extra = (event.home ? props : Math.max(0, props - 1)) + (markets.some((mk) => mk.selections.some((s) => s.key === 'draw')) ? 1 : 0);

    return (
      <article className="Longshot-event">
        <header className="Longshot-event-head">
          <span className="Longshot-event-league">
            {this.attrs.badge ? <img src={this.attrs.badge} alt="" width="18" height="18" loading="lazy" decoding="async" /> : null}
            {event.league}
          </span>
          {this.status(event)}
          {this.attrs.standalone ? null : (
            <Link className="Longshot-event-more" href={app.route('longshot.event', { id: event.id })}>
              {extra ? t('forum.board.more_markets', { count: extra }) : t('forum.board.details')}
              {event.offers ? <span className="Longshot-badge">{t('forum.board.offers', { count: event.offers })}</span> : null}
            </Link>
          )}
        </header>

        {event.home && event.away ? (
          <div className="Longshot-grid" role="table" aria-label={event.name}>
            <div className="Longshot-grid-row Longshot-grid-labels" role="row">
              <span role="columnheader" />
              {scored.map((mk, i) => (
                <span role="columnheader" key={i}>
                  {mk ? t('forum.market.' + mk.type) : ''}
                </span>
              ))}
            </div>
            {ROWS.map((side) => (
              <div className="Longshot-grid-row" role="row" key={side}>
                <span className="Longshot-team" role="rowheader" style={event[side]?.color ? { '--team-color': event[side]!.color } : undefined}>
                  {event[side]?.logo ? <img src={event[side]!.logo!} alt="" width="24" height="24" loading="lazy" decoding="async" /> : null}
                  <span className="Longshot-team-name">{event[side]?.name}</span>
                  {event.score ? <strong className="Longshot-team-score">{event.score[side]}</strong> : null}
                </span>
                {scored.map((mk, i) => (
                  <span role="cell" key={i}>
                    {this.cell(event, mk, side)}
                  </span>
                ))}
              </div>
            ))}
          </div>
        ) : (
          <div className="Longshot-titled">
            <h3>{event.title}</h3>
            {markets.slice(0, 1).map((mk) => (
              <div className="Longshot-options">
                {mk.selections.slice(0, 6).map((s) => (
                  <OddsButton event={event} market={mk} selection={s} named />
                ))}
              </div>
            ))}
          </div>
        )}
      </article>
    );
  }

  cell(event: BookEvent, market: Market | undefined, side: 'away' | 'home'): Mithril.Children {
    if (!market) return null;

    const key = market.type === 'total' ? (side === 'away' ? 'over' : 'under') : side;
    const selection = market.selections.find((s) => s.key === key);

    return selection ? <OddsButton event={event} market={market} selection={selection} /> : null;
  }

  status(event: BookEvent): Mithril.Children {
    if (event.status === 'void') return <span className="Longshot-state is-void">{t('forum.event.void')}</span>;
    if (event.status === 'settled') return <span className="Longshot-state is-final">{t('forum.event.final')}</span>;

    if (!event.bettable) {
      return (
        <span className="Longshot-state is-live">
          <Icon name="fas fa-circle" /> {t('forum.event.started')}
        </span>
      );
    }

    return (
      <time className="Longshot-state" dateTime={event.startsAt}>
        {when(event.startsAt)}
      </time>
    );
  }
}
