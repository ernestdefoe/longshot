import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type { BookEvent } from '../../common/types';
import { t } from '../../common/t';
import { adminGet, adminSend } from '../api';
import EventModal from './EventModal';
import ScoreModal from './ScoreModal';
import ResolveModal from './ResolveModal';
import LimitsEditor from './LimitsEditor';
import ProvidersPanel from './ProvidersPanel';
import TeamsEditor from './TeamsEditor';

/** The book itself: events, results, group limits and points by hand. */
export default class BookManager extends Component {
  status: 'open' | 'finished' = 'open';
  q = '';
  events: BookEvent[] | null = null;
  more = false;
  syncing = false;
  synced: string | null = null;
  grantUser = '';
  grantAmount = '';
  granting = false;
  granted: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load(append = false) {
    adminGet('/events', { status: this.status, q: this.q || undefined, offset: append ? (this.events ?? []).length : 0 }).then((r: any) => {
      this.events = append ? [...(this.events ?? []), ...r.data] : r.data;
      this.more = r.meta.more;
      m.redraw();
    });
  }

  view(): Mithril.Children {
    return (
      <div className="Longshot-manager">
        <h3 className="Longshot-adminHeading">{t('admin.book.title')}</h3>
        <p className="helpText">{t('admin.book.help')}</p>

        <div className="Longshot-manager-bar">
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => app.modal.show(EventModal, { onsave: () => this.load() })}>
            {t('admin.book.new_event')}
          </Button>
          <Button className="Button" icon="fas fa-rotate" loading={this.syncing} onclick={() => this.sync()}>
            {t('admin.book.sync')}
          </Button>
          {this.synced ? <span className="helpText">{this.synced}</span> : null}
        </div>

        <div className="Longshot-manager-filters">
          {(['open', 'finished'] as const).map((s) => (
            <Button
              className={classList('Button', { 'Button--primary': this.status === s })}
              onclick={() => {
                this.status = s;
                this.events = null;
                this.load();
              }}
            >
              {t('admin.book.' + s)}
            </Button>
          ))}
          <input
            className="FormControl"
            type="search"
            placeholder={extractText(t('admin.book.search'))}
            value={this.q}
            oninput={(e: InputEvent) => (this.q = (e.target as HTMLInputElement).value)}
            onkeydown={(e: KeyboardEvent) => e.key === 'Enter' && this.load()}
          />
        </div>

        {this.events === null ? (
          <LoadingIndicator />
        ) : !this.events.length ? (
          <p className="helpText">{t('admin.book.none')}</p>
        ) : (
          <table className="Longshot-adminTable">
            <thead>
              <tr>
                <th>{t('admin.book.event')}</th>
                <th>{t('admin.book.starts')}</th>
                <th>{t('admin.book.activity')}</th>
                <th />
              </tr>
            </thead>
            <tbody>{this.events.map((e) => this.row(e))}</tbody>
          </table>
        )}

        {this.more ? (
          <Button className="Button" onclick={() => this.load(true)}>
            {t('admin.book.more')}
          </Button>
        ) : null}

        <ProvidersPanel />

        <TeamsEditor leagues={{ ...((app.forum.attribute('longshotAdmin') as any)?.feedLeagues ?? {}) }} />

        <LimitsEditor />

        <h3 className="Longshot-adminHeading">{t('admin.grant.title')}</h3>
        <p className="helpText">{t('admin.grant.help')}</p>
        <div className="Longshot-manager-bar">
          <input
            className="FormControl"
            placeholder={extractText(t('admin.grant.user'))}
            value={this.grantUser}
            oninput={(e: InputEvent) => (this.grantUser = (e.target as HTMLInputElement).value)}
          />
          <input
            className="FormControl"
            type="number"
            placeholder={extractText(t('admin.grant.amount'))}
            value={this.grantAmount}
            oninput={(e: InputEvent) => (this.grantAmount = (e.target as HTMLInputElement).value)}
          />
          <Button
            className="Button"
            loading={this.granting}
            disabled={!this.grantUser || !parseInt(this.grantAmount, 10)}
            onclick={() => this.grant()}
          >
            {t('admin.grant.submit')}
          </Button>
          {this.granted ? <span className="helpText">{this.granted}</span> : null}
        </div>
      </div>
    );
  }

  row(event: BookEvent): Mithril.Children {
    const open = event.status === 'open';
    const custom = (event.markets ?? []).filter((mk) => mk.type === 'custom' && mk.status !== 'settled' && mk.status !== 'void');
    const scoreable = open && !!event.home && !!event.away;

    return (
      <tr key={event.id}>
        <td>
          <strong>{event.name}</strong>
          <div className="helpText">
            {event.league}
            {event.source && event.source !== 'manual' ? ` · ${extractText(t('admin.book.from', { source: event.source }))}` : ''}
            {event.locked ? ` · ${extractText(t('admin.book.locked'))}` : ''}
            {event.score ? ` · ${event.score.away}–${event.score.home}` : ''}
            {event.status !== 'open' ? ` · ${t('admin.book.status_' + event.status)}` : ''}
          </div>
        </td>
        <td>{new Date(event.startsAt).toLocaleString()}</td>
        <td>{t('admin.book.counts', { bets: event.bets ?? 0, offers: event.offers ?? 0 })}</td>
        <td className="Longshot-adminActions">
          {open ? (
            <Button
              className="Button Button--icon"
              icon="fas fa-pen"
              aria-label={extractText(t('admin.book.edit'))}
              onclick={() => app.modal.show(EventModal, { event, onsave: () => this.load() })}
            />
          ) : null}
          {scoreable ? (
            <Button className="Button" onclick={() => app.modal.show(ScoreModal, { event, onsave: () => this.load() })}>
              {t('admin.book.score')}
            </Button>
          ) : null}
          {custom.map((market) => (
            <Button className="Button" onclick={() => app.modal.show(ResolveModal, { event, market, onsave: () => this.load() })}>
              {t('admin.book.resolve', { market: market.label })}
            </Button>
          ))}
          {open ? (
            <Button className="Button" onclick={() => this.void(event)}>
              {t('admin.book.void')}
            </Button>
          ) : null}
          {!event.bets && !event.offers ? (
            <Button
              className="Button Button--icon Button--danger"
              icon="fas fa-trash"
              aria-label={extractText(t('admin.book.delete'))}
              onclick={() => this.delete(event)}
            />
          ) : null}
        </td>
      </tr>
    );
  }

  sync() {
    this.syncing = true;
    adminSend('POST', '/sync')
      .then(
        (r: any) =>
          (this.synced = extractText(t('admin.book.synced', { imported: r.data.imported, calls: r.data.odds.calls, prices: r.data.odds.updated })))
      )
      .finally(() => {
        this.syncing = false;
        this.load();
      });
  }

  void(event: BookEvent) {
    if (!confirm(extractText(t('admin.book.void_confirm', { name: event.name })))) return;
    adminSend('POST', `/events/${event.id}/void`).then(() => this.load());
  }

  hide(event: BookEvent) {
    if (!confirm(extractText(t('admin.book.hide_confirm', { name: event.name })))) return;
    adminSend('POST', `/events/${event.id}/hide`).then(() => this.load());
  }

  delete(event: BookEvent) {
    if (!confirm(extractText(t('admin.book.delete_confirm', { name: event.name })))) return;
    adminSend('DELETE', `/events/${event.id}`).then(() => this.load());
  }

  grant() {
    this.granting = true;
    this.granted = null;
    adminSend('POST', '/grants', { user: this.grantUser.trim(), amount: parseInt(this.grantAmount, 10) })
      .then((r: any) => {
        this.granted = extractText(t('admin.grant.done', { balance: r.data.balance }));
        this.grantAmount = '';
      })
      .finally(() => {
        this.granting = false;
        m.redraw();
      });
  }
}
