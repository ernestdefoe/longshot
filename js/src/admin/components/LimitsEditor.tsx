import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { adminGet, adminSend } from '../api';

interface Row {
  id: number;
  name: string;
  maxStake: string;
  dailyLimit: string;
  allowance: string;
}

/**
 * Per-group limits. Empty means the forum's default; a member in several
 * groups gets the most generous value of each.
 */
export default class LimitsEditor extends Component {
  rows: Row[] | null = null;
  saving = false;
  saved = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    adminGet('/limits').then((r: any) => {
      this.rows = r.data.map((g: any) => ({
        id: g.id,
        name: g.name,
        maxStake: g.maxStake ?? '',
        dailyLimit: g.dailyLimit ?? '',
        allowance: g.allowance ?? '',
      }));
      m.redraw();
    });
  }

  view(): Mithril.Children {
    return (
      <div>
        <h3 className="Longshot-adminHeading">{t('admin.limits.title')}</h3>
        <p className="helpText">{t('admin.limits.help')}</p>
        {this.rows === null ? (
          <LoadingIndicator />
        ) : (
          <table className="Longshot-adminTable">
            <thead>
              <tr>
                <th>{t('admin.limits.group')}</th>
                <th>{t('admin.limits.max_stake')}</th>
                <th>{t('admin.limits.daily_limit')}</th>
                <th>{t('admin.limits.allowance')}</th>
              </tr>
            </thead>
            <tbody>
              {this.rows.map((row) => (
                <tr>
                  <td>{row.name}</td>
                  {(['maxStake', 'dailyLimit', 'allowance'] as const).map((field) => (
                    <td>
                      <input
                        className="FormControl"
                        type="number"
                        min="0"
                        placeholder={extractText(t('admin.limits.default'))}
                        value={row[field]}
                        oninput={(e: InputEvent) => {
                          row[field] = (e.target as HTMLInputElement).value;
                          this.saved = false;
                        }}
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Button className="Button" loading={this.saving} onclick={() => this.save()}>
          {this.saved ? t('admin.limits.saved') : t('admin.limits.save')}
        </Button>
      </div>
    );
  }

  save() {
    if (!this.rows) return;
    this.saving = true;

    const limits: Record<number, any> = {};
    this.rows.forEach((r) => (limits[r.id] = { maxStake: r.maxStake, dailyLimit: r.dailyLimit, allowance: r.allowance }));

    adminSend('POST', '/limits', { limits })
      .then(() => (this.saved = true))
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }
}
