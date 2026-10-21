import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import extractText from 'flarum/common/utils/extractText';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import type Mithril from 'mithril';
import type { BookEvent, Market } from '../../common/types';
import { t } from '../../common/t';
import { adminSend } from '../api';

interface Attrs extends IFormModalAttrs {
  event: BookEvent;
  market: Market;
  onsave: () => void;
}

/** Name the result of a custom market, option by option. */
export default class ResolveModal extends FormModal<Attrs> {
  results: Record<number, string> = {};

  className() {
    return 'Modal--small';
  }

  title() {
    return t('admin.resolve.title', { market: this.attrs.market.label });
  }

  content(): Mithril.Children {
    const options = { win: t('admin.resolve.win'), lose: t('admin.resolve.lose'), void: t('admin.resolve.void') };

    return (
      <div className="Modal-body">
        <p className="helpText">{t('admin.resolve.help')}</p>
        {this.attrs.market.selections.map((s) => (
          <div className="Form-group">
            <label>{s.label}</label>
            <Select options={options} value={this.results[s.id] ?? 'lose'} onchange={(v: string) => (this.results[s.id] = v)} />
          </div>
        ))}
        <Button className="Button Button--primary" type="submit" loading={this.loading}>
          {t('admin.resolve.submit')}
        </Button>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const results: Record<number, string> = {};
    this.attrs.market.selections.forEach((s) => (results[s.id] = this.results[s.id] ?? 'lose'));

    if (!confirm(extractText(t('admin.resolve.confirm')))) return;

    this.loading = true;
    adminSend('POST', `/markets/${this.attrs.market.id}/resolve`, { results })
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
