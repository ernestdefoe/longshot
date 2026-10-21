import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import extractText from 'flarum/common/utils/extractText';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type { BookEvent } from '../../common/types';
import { t } from '../../common/t';
import { adminSend } from '../api';

interface Attrs extends IFormModalAttrs {
  event: BookEvent;
  onsave: () => void;
}

/** Enter a final score: every moneyline, spread and total is graded from it, once. */
export default class ScoreModal extends FormModal<Attrs> {
  home = '';
  away = '';

  className() {
    return 'Modal--small';
  }

  title() {
    return t('admin.score.title', { name: this.attrs.event.name });
  }

  content(): Mithril.Children {
    const e = this.attrs.event;

    return (
      <div className="Modal-body">
        <p className="helpText">{t('admin.score.help')}</p>
        <div className="Form-group">
          <label>{e.away?.name}</label>
          <input
            className="FormControl"
            type="number"
            min="0"
            value={this.away}
            oninput={(ev: InputEvent) => (this.away = (ev.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <label>{e.home?.name}</label>
          <input
            className="FormControl"
            type="number"
            min="0"
            value={this.home}
            oninput={(ev: InputEvent) => (this.home = (ev.target as HTMLInputElement).value)}
          />
        </div>
        <Button className="Button Button--primary" type="submit" loading={this.loading} disabled={this.home === '' || this.away === ''}>
          {t('admin.score.submit')}
        </Button>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!confirm(extractText(t('admin.score.confirm', { away: this.away, home: this.home })))) return;

    this.loading = true;
    adminSend('POST', `/events/${this.attrs.event.id}/score`, { home: parseInt(this.home, 10), away: parseInt(this.away, 10) })
      .then(() => {
        this.attrs.onsave();
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
